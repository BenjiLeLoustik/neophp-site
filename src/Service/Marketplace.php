<?php

declare(strict_types=1);

namespace App\Service;

use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Package\Markdown\MarkdownManagerInterface;

/**
 * Reads the NeoIDE plugins and themes exported by "marketplace:sync".
 *
 * Every folder of the marketplace repository named plugin-<name> is a plugin, theme-<name> a theme:
 * manifest.json (required: "version"), README.md, icon.png and screenshots/ are optional.
 * The packages (.zip of the folder, one per version) are in var/marketplace/packages/<folder>/.
 */
class Marketplace
{
    /** Type => segment of the URLs */
    public const TYPES = ['plugin' => 'plugins', 'theme' => 'themes'];

    public const FOLDER_PATTERN = '/^(plugin|theme)-([a-z0-9][a-z0-9-]*)$/';

    /** The id of an extension is its folder: plugin-git-tools, theme-midnight */
    public const ID_PATTERN = '(?:plugin|theme)-[a-z0-9][a-z0-9-]*';

    public const SLUG_PATTERN = '[a-z0-9][a-z0-9-]*';

    public const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/';

    public const MEDIA_PATTERN = '[a-z0-9][a-z0-9_-]*\.(?:png|jpe?g|webp)';

    protected ?array $extensions = null;

    protected array $errors = [];

    public function __construct(
        protected MarkdownManagerInterface $markdown,
        #[Autowire('%kernel.root_path%/var/marketplace')] protected string $path,
    ) {
    }

    /**
     * The short commit of the synchronized repository (null when never synchronized or empty).
     */
    public function getRevision(): ?string
    {
        $file = $this->path . '/current/.commit';
        $commit = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $commit !== '' ? $commit : null;
    }

    public function isSynchronized(): bool
    {
        return is_file($this->path . '/current/.commit');
    }

    /**
     * Every extension with at least one package, sorted by name.
     */
    public function all(?string $type = null): array
    {
        $extensions = $this->load();

        return $type === null ? array_values($extensions) : array_values(array_filter($extensions, static fn (array $extension): bool => $extension['type'] === $type));
    }

    public function find(string $id): ?array
    {
        return $this->load()[$id] ?? null;
    }

    public function findBySlug(string $type, string $slug): ?array
    {
        return $this->find($type . '-' . $slug);
    }

    /**
     * The extensions matching the filters; with $ide, "latest" is the latest version compatible with this NeoIDE version.
     */
    public function search(?string $type = null, ?string $query = null, ?string $ide = null): array
    {
        $terms = preg_split('/\s+/', mb_strtolower(trim((string) $query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $result = [];

        foreach ($this->all($type) as $extension) {
            $haystack = mb_strtolower(implode(' ', [$extension['id'], $extension['name'], $extension['author'], $extension['description'], ...$extension['tags']]));

            foreach ($terms as $term) {
                if (!str_contains($haystack, $term)) {
                    continue 2;
                }
            }

            if ($ide !== null) {
                $extension['latest'] = $this->getLatestVersion($extension, $ide);

                if ($extension['latest'] === null) {
                    continue;
                }
            }

            $result[] = $extension;
        }

        return $result;
    }

    /**
     * The most recent version of the extension, compatible with $ide when given.
     */
    public function getLatestVersion(array $extension, ?string $ide = null): ?array
    {
        foreach ($extension['versions'] as $version) {
            if ($ide === null || $this->isCompatible($version['ide'], $ide)) {
                return $version;
            }
        }

        return null;
    }

    public function findVersion(array $extension, string $version): ?array
    {
        foreach ($extension['versions'] as $item) {
            if ($item['version'] === $version) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The .zip of a version of the extension.
     */
    public function getPackagePath(array $extension, array $version): ?string
    {
        $file = sprintf('%s/packages/%s/%s.zip', $this->path, $extension['id'], $version['version']);

        return is_file($file) ? $file : null;
    }

    /**
     * The README of the extension as HTML (raw HTML is escaped).
     */
    public function getReadme(array $extension): ?string
    {
        $file = $this->extensionPath($extension) . '/README.md';

        return is_file($file) ? $this->markdown->toSafeHtml((string) file_get_contents($file)) : null;
    }

    /**
     * The absolute path of the icon or of a screenshot of the extension.
     */
    public function getMediaPath(array $extension, string $file): ?string
    {
        $path = match (true) {
            $file === $extension['icon'] => $this->extensionPath($extension) . '/' . $file,
            in_array($file, $extension['screenshots'], true) => $this->extensionPath($extension) . '/screenshots/' . $file,
            default => null,
        };

        return $path !== null && is_file($path) ? $path : null;
    }

    /**
     * Checks a NeoIDE version against a constraint: "*", "0.4.2", ">=0.4 <1.0", ">=0.4 <0.6 || >=1.0".
     */
    public function isCompatible(string $constraint, string $version): bool
    {
        foreach (explode('||', $constraint) as $alternative) {
            $tokens = preg_split('/\s+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $matches = true;

            foreach ($tokens as $token) {
                if ($token === '*') {
                    continue;
                }

                if (preg_match('/^(>=|<=|>|<|=|!=)?v?(\d+(?:\.\d+){0,2}(?:-[0-9A-Za-z.-]+)?)$/', $token, $match) !== 1 || !version_compare($version, $match[2], $match[1] !== '' ? $match[1] : '=')) {
                    $matches = false;

                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * The folders skipped while reading the repository.
     */
    public function getErrors(): array
    {
        $this->load();

        return $this->errors;
    }

    protected function load(): array
    {
        if ($this->extensions !== null) {
            return $this->extensions;
        }

        $extensions = [];

        foreach (glob($this->path . '/current/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (preg_match(self::FOLDER_PATTERN, basename($directory), $match) !== 1) {
                continue;
            }

            $extension = $this->normalize($match[1], $match[2], $directory);

            if ($extension !== null) {
                $extensions[$extension['id']] = $extension;
            }
        }

        uasort($extensions, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $this->extensions = $extensions;
    }

    protected function normalize(string $type, string $slug, string $directory): ?array
    {
        $id = basename($directory);
        $manifest = json_decode((string) @file_get_contents($directory . '/manifest.json'), true);

        if (!is_array($manifest)) {
            $this->errors[] = $id . ': manifest.json is missing or is not valid JSON.';

            return null;
        }

        $versions = [];

        foreach (glob(sprintf('%s/packages/%s/*.json', $this->path, $id)) ?: [] as $file) {
            $version = json_decode((string) file_get_contents($file), true);

            if (is_array($version) && is_file(substr($file, 0, -5) . '.zip')) {
                $versions[$version['version']] = $version;
            }
        }

        if ($versions === []) {
            $this->errors[] = $id . ': not listed, no version packaged yet (run "php bin/neo marketplace:sync").';

            return null;
        }

        uksort($versions, static fn (string $a, string $b): int => version_compare($b, $a));
        $versions = array_values($versions);
        $screenshots = array_map('basename', glob($directory . '/screenshots/*') ?: []);
        $icon = array_values(array_filter(['icon.png', 'icon.webp', 'icon.jpg'], static fn (string $name): bool => is_file($directory . '/' . $name)))[0] ?? null;
        $string = static fn (string $key): ?string => is_string($manifest[$key] ?? null) && trim($manifest[$key]) !== '' ? trim($manifest[$key]) : null;

        return [
            'id' => $id,
            'type' => $type,
            'slug' => $slug,
            'folder' => self::TYPES[$type],
            'name' => $string('name') ?? ucwords(str_replace('-', ' ', $slug)),
            'author' => $string('author') ?? 'NeoPHP',
            'description' => $string('description') ?? '',
            'tags' => array_values(array_filter(is_array($manifest['tags'] ?? null) ? $manifest['tags'] : [], static fn (mixed $tag): bool => is_string($tag) && $tag !== '')),
            'license' => $string('license'),
            'homepage' => $this->url($manifest['homepage'] ?? null),
            'icon' => $icon,
            'screenshots' => array_values(array_filter($screenshots, static fn (string $name): bool => preg_match('/^' . self::MEDIA_PATTERN . '$/', $name) === 1)),
            'versions' => $versions,
            'latest' => $versions[0],
        ];
    }

    protected function extensionPath(array $extension): string
    {
        return $this->path . '/current/' . $extension['id'];
    }

    protected function url(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }
}
