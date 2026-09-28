<?php

declare(strict_types=1);

namespace App\Service;

use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Package\Markdown\Contract\MarkdownParserInterface;
use NeoPHP\Package\Markdown\Document\MarkdownDocument;

class DocumentationRepository
{
    public const GROUPS = ['components', 'packages', 'process'];

    protected ?array $roots = null;

    public function __construct(
        protected MarkdownParserInterface $markdown,
        #[Autowire('%kernel.root_path%/vendor/neophp/framework')] protected string $frameworkPath,
        #[Autowire('%kernel.root_path%/var/framework')] protected string $syncedPath,
    ) {
    }

    public function getVersions(): array
    {
        return array_keys($this->getRoots());
    }

    public function getLatestVersion(): ?string
    {
        return $this->getVersions()[0] ?? null;
    }

    public function hasVersion(string $version): bool
    {
        return isset($this->getRoots()[$version]);
    }

    public function isSynchronized(): bool
    {
        return glob($this->syncedPath . '/v*/docs', GLOB_ONLYDIR) !== [];
    }

    public function getFeatures(string $version): array
    {
        $root = $this->getRoots()[$version] ?? null;
        $features = [];

        if ($root === null) {
            return [];
        }

        foreach (self::GROUPS as $group) {
            foreach (glob(sprintf('%s/src/%s/*/[Dd]ocs/%s/README.md', $root, $group, $version)) ?: [] as $file) {
                $name = basename(dirname($file, 3));
                $features[$group][strtolower($name)] = $name;
            }

            if (isset($features[$group])) {
                ksort($features[$group]);
            }
        }

        return $features;
    }

    public function getGuide(string $version): ?MarkdownDocument
    {
        $root = $this->getRoots()[$version] ?? null;

        return $root !== null ? $this->read(sprintf('%s/docs/%s/README.md', $root, $version)) : null;
    }

    public function getFeature(string $version, string $group, string $slug): ?MarkdownDocument
    {
        $root = $this->getRoots()[$version] ?? null;
        $name = $this->getFeatureName($version, $group, $slug);

        if ($root === null || $name === null) {
            return null;
        }

        $files = glob(sprintf('%s/src/%s/%s/[Dd]ocs/%s/README.md', $root, $group, $name, $version)) ?: [];

        return $files !== [] ? $this->read($files[0]) : null;
    }

    public function getFeatureName(string $version, string $group, string $slug): ?string
    {
        return $this->getFeatures($version)[$group][$slug] ?? null;
    }

    public function getCatalog(string $version): array
    {
        $catalog = [];

        foreach ($this->getFeatures($version) as $group => $items) {
            foreach ($items as $slug => $name) {
                $document = $this->getFeature($version, $group, $slug);
                $topics = [];

                foreach ($document?->getHeadings() ?? [] as $heading) {
                    if ($heading->getLevel() >= 2 && !in_array(strtolower($heading->getText()), ['summary', 'changelog'], true)) {
                        $topics[] = ['text' => $heading->getText(), 'id' => $heading->getId()];
                    }
                }

                $catalog[$group][] = [
                    'slug' => $slug,
                    'name' => $name,
                    'description' => $document?->getDescription(),
                    'topics' => $topics,
                ];
            }
        }

        return $catalog;
    }

    public function getReleases(string $version, int $limit = 4): array
    {
        $changelog = $this->getChangelog($version);

        if ($changelog === null) {
            return [];
        }

        $releases = [];

        foreach (preg_split('/\R/', $changelog) ?: [] as $line) {
            if (preg_match('/^- (\S+) — (.+)$/u', trim($line), $match) === 1) {
                $releases[] = ['version' => $match[1], 'notes' => $this->markdown->toHtml($match[2])];
            }

            if (count($releases) >= $limit) {
                break;
            }
        }

        return $releases;
    }

    public function getChangelog(string $version): ?string
    {
        $root = $this->getRoots()[$version] ?? null;

        if ($root === null) {
            return null;
        }

        if (is_file($root . '/CHANGELOG.md')) {
            return (string) file_get_contents($root . '/CHANGELOG.md');
        }

        return $this->getGuide($version)?->getSection('Changelog')?->getMarkdown();
    }

    protected function getRoots(): array
    {
        if ($this->roots !== null) {
            return $this->roots;
        }

        $roots = [];

        foreach (glob($this->syncedPath . '/v*/docs/v*', GLOB_ONLYDIR) ?: [] as $directory) {
            $version = basename($directory);

            if ($version === basename(dirname($directory, 2))) {
                $roots[$version] = dirname($directory, 2);
            }
        }

        if ($roots === []) {
            foreach (glob($this->frameworkPath . '/docs/v*', GLOB_ONLYDIR) ?: [] as $directory) {
                $roots[basename($directory)] = $this->frameworkPath;
            }
        }

        uksort($roots, static fn (string $a, string $b): int => strnatcmp($b, $a));

        return $this->roots = $roots;
    }

    protected function read(string $file): ?MarkdownDocument
    {
        return is_file($file) ? $this->markdown->get($file) : null;
    }
}