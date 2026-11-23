<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Component\HttpClient\HttpClientManagerInterface;
use NeoPHP\Package\Markdown\MarkdownManagerInterface;
use Throwable;

/**
 * The latest release of NeoIDE on GitHub (NEOIDE_REPOSITORY), refreshed by "marketplace:sync" into var/marketplace/neoide.json.
 * No release (or no .exe / .msi / .zip in it) = NeoIDE is not available.
 */
class NeoIde
{
    protected array|false|null $release = false;

    public function __construct(
        protected HttpClientManagerInterface $http,
        protected MarkdownManagerInterface $markdown,
        #[Autowire(env: 'NEOIDE_REPOSITORY')] protected string $repository,
        #[Autowire('%kernel.root_path%/var/marketplace/neoide.json')] protected string $file,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->getRelease() !== null;
    }

    /**
     * @return array{version: string, tag: string, name: string, date: ?string, notes: ?string, page: string, url: string, file: string, size: int, sha256: ?string}|null
     */
    public function getRelease(): ?array
    {
        if ($this->release === false) {
            $data = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;
            $this->release = is_array($data['release'] ?? null) ? $data['release'] : null;
        }

        return $this->release;
    }

    public function getNotesHtml(): ?string
    {
        $notes = $this->getRelease()['notes'] ?? null;

        return is_string($notes) && trim($notes) !== '' ? $this->markdown->toSafeHtml($notes) : null;
    }

    public function getReleasesUrl(): string
    {
        return sprintf('https://github.com/%s/releases', $this->repository);
    }

    /**
     * Reads the latest release from the GitHub API. On a network error, the last known release is kept.
     *
     * @return array{0: ?array, 1: ?string} The release, and the error
     */
    public function refresh(): array
    {
        try {
            $response = $this->http->get(sprintf('https://api.github.com/repos/%s/releases/latest', $this->repository), [
                'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'neophp-site', 'X-GitHub-Api-Version' => '2022-11-28'],
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            $data = $status === 200 ? $response->toArray(false) : null;
        } catch (Throwable $exception) {
            return [$this->getRelease(), $exception->getMessage()];
        }

        if ($status !== 200 && $status !== 404) {
            return [$this->getRelease(), sprintf('GitHub answered %d.', $status)];
        }

        $release = is_array($data) ? $this->normalize($data) : null;
        $error = is_array($data) && $release === null ? sprintf('the release %s has no .exe, .msi or .zip file.', (string) ($data['tag_name'] ?? '')) : null;

        is_dir(dirname($this->file)) || mkdir(dirname($this->file), 0775, true);
        file_put_contents($this->file, json_encode(['checked_at' => (new DateTimeImmutable())->format(DATE_ATOM), 'release' => $release], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->release = $release;

        return [$release, $error];
    }

    protected function normalize(array $data): ?array
    {
        // The Windows download: neoide.exe, then an installer (.exe, .msi), then an archive (.zip, "win" in its name first)
        $score = static function (mixed $asset): int {
            $name = is_array($asset) ? strtolower((string) ($asset['name'] ?? '')) : '';

            return match (true) {
                $name === 'neoide.exe' => 5,
                str_ends_with($name, '.exe') || str_ends_with($name, '.msi') => 4,
                str_ends_with($name, '.zip') && str_contains($name, 'win') => 3,
                str_ends_with($name, '.zip') => 2,
                default => 0,
            };
        };
        $assets = array_values(array_filter(is_array($data['assets'] ?? null) ? $data['assets'] : [], static fn (mixed $asset): bool => $score($asset) > 0));
        usort($assets, static fn (array $a, array $b): int => $score($b) <=> $score($a));
        $asset = $assets[0] ?? null;

        if ($asset === null || !is_string($asset['browser_download_url'] ?? null)) {
            return null;
        }

        $tag = (string) ($data['tag_name'] ?? '');
        $digest = (string) ($asset['digest'] ?? '');

        return [
            'version' => ltrim($tag, 'vV'),
            'tag' => $tag,
            'name' => (string) ($data['name'] ?: $tag),
            'date' => is_string($data['published_at'] ?? null) ? substr($data['published_at'], 0, 10) : null,
            'notes' => is_string($data['body'] ?? null) ? $data['body'] : null,
            'page' => (string) ($data['html_url'] ?? $this->getReleasesUrl()),
            'url' => $asset['browser_download_url'],
            'file' => (string) $asset['name'],
            'size' => (int) ($asset['size'] ?? 0),
            'sha256' => str_starts_with($digest, 'sha256:') ? substr($digest, 7) : null,
        ];
    }
}
