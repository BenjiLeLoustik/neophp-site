<?php

declare(strict_types=1);

namespace App\Service;

use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Package\Markdown\MarkdownManagerInterface;

/**
 * Reads the NeoIDE plugins and themes exported by "marketplace:sync".
 *
 * Every folder of the marketplace repository named plugin-<name> is a plugin, theme-<name> a theme, in the native
 * format of NeoIDE: plugin.json + DLL and dependencies, or theme.json. README.md, icon.png and screenshots/ are
 * only used by the site (never packaged). The packages (.zip of the folder, one per version) are in
 * var/marketplace/packages/<folder>/.
 */
class Marketplace
{
    /** Type => segment of the URLs */
    public const TYPES = ['plugin' => 'plugins', 'theme' => 'themes'];

    public const FOLDER_PATTERN = '/^(plugin|theme)-([a-z0-9][a-z0-9-]*)$/';

    /** The "Id" of plugin.json / theme.json: publisher.name, as accepted by the neoide://install/ links of NeoIDE */
    public const ID_PATTERN = '[a-z0-9][a-z0-9-]*\.[a-z0-9][a-z0-9-]*';

    /** The manifest of NeoIDE in each folder */
    public const MANIFESTS = ['plugin' => 'plugin.json', 'theme' => 'theme.json'];

    /** The files of a folder used by the site only, excluded from the package */
    public const SITE_FILES = ['README.md', 'icon.png', 'icon.webp', 'icon.jpg', 'screenshots'];

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
        foreach ($this->load() as $extension) {
            if ($extension['type'] === $type && $extension['slug'] === $slug) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * The plugin.json / theme.json of a folder, keys in lowercase (NeoIDE reads them case-insensitively).
     */
    public static function readManifest(string $directory, string $type): ?array
    {
        $file = $directory . '/' . self::MANIFESTS[$type];
        $content = is_file($file) ? (string) file_get_contents($file) : '';
        $manifest = json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', $content), true);

        return is_array($manifest) ? array_change_key_case($manifest, CASE_LOWER) : null;
    }

    /**
     * The extensions matching the filters; with $ide, "latest" is the latest version compatible with this NeoIDE version.
     */
    public function search(?string $type = null, ?string $query = null, ?string $ide = null): array
    {
        $terms = preg_split('/\s+/', mb_strtolower(trim((string) $query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $result = [];

        foreach ($this->all($type) as $extension) {
            $haystack = mb_strtolower(implode(' ', [$extension['id'], $extension['name'], $extension['publisher'], $extension['description'], ...$extension['tags']]));

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
        $file = sprintf('%s/packages/%s/%s.zip', $this->path, $extension['directory'], $version['version']);

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

            if ($extension === null) {
                continue;
            }

            if (isset($extensions[$extension['id']])) {
                $this->errors[] = sprintf('%s: the Id "%s" is already used by %s.', $extension['directory'], $extension['id'], $extensions[$extension['id']]['directory']);

                continue;
            }

            $extensions[$extension['id']] = $extension;
        }

        uasort($extensions, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $this->extensions = $extensions;
    }

    protected function normalize(string $type, string $slug, string $directory): ?array
    {
        $folder = basename($directory);
        $manifest = self::readManifest($directory, $type);
        $file = self::MANIFESTS[$type];

        if ($manifest === null) {
            $this->errors[] = sprintf('%s: %s is missing or is not valid JSON.', $folder, $file);

            return null;
        }

        $string = static fn (string $key): ?string => is_string($manifest[$key] ?? null) && trim($manifest[$key]) !== '' ? trim($manifest[$key]) : null;
        $id = $string('id');

        if ($id === null || preg_match('/^' . self::ID_PATTERN . '$/', $id) !== 1) {
            $this->errors[] = sprintf('%s: the "Id" of %s must look like "publisher.name" (lowercase letters, digits and dashes), as required by NeoIDE.', $folder, $file);

            return null;
        }

        $versions = [];

        foreach (glob(sprintf('%s/packages/%s/*.json', $this->path, $folder)) ?: [] as $metadata) {
            $version = json_decode((string) file_get_contents($metadata), true);

            if (is_array($version) && is_file(substr($metadata, 0, -5) . '.zip')) {
                $versions[$version['version']] = $version;
            }
        }

        if ($versions === []) {
            $this->errors[] = $folder . ': not listed, no version packaged yet (run "php bin/neo marketplace:sync").';

            return null;
        }

        uksort($versions, static fn (string $a, string $b): int => version_compare($b, $a));
        $versions = array_values($versions);
        $screenshots = array_map('basename', glob($directory . '/screenshots/*') ?: []);
        $icon = array_values(array_filter(['icon.png', 'icon.webp', 'icon.jpg'], static fn (string $name): bool => is_file($directory . '/' . $name)))[0] ?? null;
        $strings = static fn (string $key): array => array_values(array_filter(is_array($manifest[$key] ?? null) ? $manifest[$key] : [], static fn (mixed $value): bool => is_string($value) && $value !== ''));

        return [
            'id' => $id,
            'type' => $type,
            'slug' => $slug,
            'directory' => $folder,
            'folder' => self::TYPES[$type],
            'name' => $string('name') ?? ucwords(str_replace('-', ' ', $slug)),
            // Same fallback as NeoIDE: the first part of the id
            'publisher' => $string('author') ?? explode('.', $id)[0],
            'description' => $string('description') ?? '',
            'tags' => $strings('tags'),
            'license' => $string('license'),
            'repository' => $this->url($manifest['sourceurl'] ?? null),
            'homepage' => $this->url($manifest['websiteurl'] ?? null),
            'icon' => $icon,
            'screenshots' => array_values(array_filter($screenshots, static fn (string $name): bool => preg_match('/^' . self::MEDIA_PATTERN . '$/', $name) === 1)),
            'screenshot_urls' => array_values(array_filter(array_map(fn (string $url): ?string => $this->url($url), $strings('screenshots')))),
            'versions' => $versions,
            'latest' => $versions[0],
        ];
    }

    protected function extensionPath(array $extension): string
    {
        return $this->path . '/current/' . $extension['directory'];
    }

    protected function url(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }
}
