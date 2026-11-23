<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Git\RunsGit;
use DateTimeImmutable;
use NeoPHP\Component\Container\Attribute\Autowire;

/**
 * Mirrors the marketplace repository (MARKETPLACE_REPOSITORY): every plugin-* / theme-* folder of its default branch
 * is exported into var/marketplace/current/, and each new "Version" of its plugin.json / theme.json is packaged into
 * var/marketplace/packages/<folder>/<version>.zip (+ <version>.json: sha256, size, date...), in the format installed
 * by NeoIDE: the files of the folder at the root of the zip, without the files of the site (README.md, icon, screenshots/).
 */
class MarketplaceSynchronizer
{
    use RunsGit;

    public function __construct(
        #[Autowire('%kernel.root_path%/var/marketplace')]
        protected string $path,
        #[Autowire(env: 'MARKETPLACE_REPOSITORY')]
        protected string $repository,
    ) {
    }

    /**
     * @return array{commit: ?string, updated: bool, packaged: string[], warnings: string[]}
     */
    public function synchronize(): array
    {
        $mirror = $this->path . '/repository.git';
        $target = $this->path . '/current';
        $this->mirror($this->repository, $mirror);

        if ($this->git(sprintf('--git-dir=%s for-each-ref refs/heads', escapeshellarg($mirror))) === '') {
            $this->export($mirror, 'HEAD', [], $target, '');

            return ['commit' => null, 'updated' => false, 'packaged' => [], 'warnings' => ['The marketplace repository is empty.']];
        }

        $commit = $this->git(sprintf('--git-dir=%s rev-parse --short HEAD', escapeshellarg($mirror)));
        $folders = array_values(array_filter(
            explode("\n", $this->git(sprintf('--git-dir=%s ls-tree -d --name-only HEAD', escapeshellarg($mirror)))),
            static fn (string $folder): bool => preg_match(Marketplace::FOLDER_PATTERN, $folder) === 1,
        ));
        $updated = !is_file($target . '/.commit') || trim((string) file_get_contents($target . '/.commit')) !== $commit;

        if ($updated) {
            $this->export($mirror, 'HEAD', $folders, $target, $commit);
        }

        $packaged = [];
        $warnings = [];

        foreach ($folders as $folder) {
            $result = $this->package($mirror, $folder, $target . '/' . $folder);

            if ($result === true) {
                $packaged[] = $folder;
            } elseif (is_string($result)) {
                $warnings[] = $folder . ': ' . $result;
            }
        }

        return ['commit' => $commit, 'updated' => $updated, 'packaged' => $packaged, 'warnings' => $warnings];
    }

    /**
     * Builds the .zip of the current version of the folder when it does not exist yet.
     *
     * @return bool|string true when a package was built, false when it already exists, or the error
     */
    protected function package(string $mirror, string $folder, string $directory): bool|string
    {
        $type = str_starts_with($folder, 'theme-') ? 'theme' : 'plugin';
        $manifest = Marketplace::readManifest($directory, $type);
        $version = $manifest['version'] ?? null;

        if ($manifest === null) {
            return Marketplace::MANIFESTS[$type] . ' is missing or is not valid JSON.';
        }

        if (!is_string($version) || preg_match(Marketplace::VERSION_PATTERN, $version) !== 1) {
            return sprintf('"Version" of %s must be a semantic version (1.2.0, 1.3.0-beta.1).', Marketplace::MANIFESTS[$type]);
        }

        // Fingerprint of the packaged files only: editing the README, the icon or the screenshots needs no new version
        $tree = hash('sha256', implode("\n", array_filter(
            explode("\n", $this->git(sprintf('--git-dir=%s ls-tree -r %s', escapeshellarg($mirror), escapeshellarg('HEAD:' . $folder)))),
            static fn (string $line): bool => !in_array(explode('/', explode("\t", $line, 2)[1] ?? '')[0], Marketplace::SITE_FILES, true),
        )));
        $packages = $this->path . '/packages/' . $folder;
        $zip = $packages . '/' . $version . '.zip';

        if (is_file($zip)) {
            $metadata = json_decode((string) file_get_contents($packages . '/' . $version . '.json'), true);

            return is_array($metadata) && ($metadata['tree'] ?? null) !== $tree
                ? sprintf('the content changed but the version is still %s: increase "Version" to publish it.', $version)
                : false;
        }

        if (!is_dir($packages)) {
            mkdir($packages, 0775, true);
        }

        $this->run(sprintf(
            'git --git-dir=%s archive --format=zip -o %s %s -- . %s',
            escapeshellarg($mirror),
            escapeshellarg($zip . '.tmp'),
            escapeshellarg('HEAD:' . $folder),
            implode(' ', array_map(static fn (string $file): string => escapeshellarg(':(exclude)' . $file), Marketplace::SITE_FILES)),
        ));
        rename($zip . '.tmp', $zip);

        file_put_contents($packages . '/' . $version . '.json', json_encode([
            'version' => $version,
            'ide' => is_string($manifest['ide'] ?? null) && trim($manifest['ide']) !== '' ? trim($manifest['ide']) : '*',
            'changelog' => is_string($manifest['changelog'] ?? null) ? $manifest['changelog'] : null,
            'date' => (new DateTimeImmutable())->format('Y-m-d'),
            'sha256' => hash_file('sha256', $zip),
            'size' => filesize($zip),
            'tree' => $tree,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return true;
    }
}
