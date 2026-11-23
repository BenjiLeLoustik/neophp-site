<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Analytics;
use App\Service\Marketplace;
use App\Service\NeoIde;
use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;
use Throwable;

/**
 * The API read by NeoIDE. Versioned: a breaking change goes into /api/v2.
 */
#[Route('/api/v1', name: 'api_v1_')]
class MarketplaceApiController extends AbstractController
{
    public const VERSION = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/';

    public function __construct(protected Marketplace $marketplace, protected Analytics $analytics)
    {
    }

    /**
     * ?type=plugin|theme  ?q=search terms  ?ide=0.4.0 (only the extensions with a version compatible with this NeoIDE)
     */
    #[Route('/extensions', name: 'extensions', methods: ['GET'])]
    public function extensions(Request $request): Response
    {
        $type = $request->query->get('type');
        $ide = $this->ideVersion($request->query->get('ide'));

        if ($type !== null && !isset(Marketplace::TYPES[$type])) {
            return $this->problemJson(400, sprintf('"type" must be one of: %s.', implode(', ', array_keys(Marketplace::TYPES))));
        }

        return $this->cached($request, function () use ($request, $type, $ide): array {
            $downloads = $this->downloads();

            return [
                'revision' => $this->marketplace->getRevision(),
                'items' => array_map(fn (array $extension): array => $this->presentExtension($extension, $downloads), $this->marketplace->search($type, $request->query->get('q'), $ide)),
            ];
        });
    }

    #[Route('/extensions/{id}', name: 'extension', methods: ['GET'], requirements: ['id' => Marketplace::ID_PATTERN])]
    public function extension(Request $request, string $id): Response
    {
        $extension = $this->marketplace->find($id);

        if ($extension === null) {
            return $this->problemJson(404, sprintf('No extension "%s".', $id));
        }

        return $this->cached($request, fn (): array => $this->presentExtension($extension, $this->downloads(), true));
    }

    /**
     * Body: {"ide": "0.4.0", "installed": {"plugin-git-tools": "1.1.0"}}
     * Returns the latest NeoIDE release when it is newer than "ide", and the latest compatible version of every installed extension newer than the installed one.
     */
    #[Route('/updates', name: 'updates', methods: ['POST'])]
    public function updates(Request $request, NeoIde $neoIde): Response
    {
        try {
            $body = $request->toArray();
        } catch (Throwable) {
            return $this->problemJson(400, 'The body must be a JSON object.');
        }

        $ide = $this->ideVersion($body['ide'] ?? null);
        $installed = is_array($body['installed'] ?? null) ? $body['installed'] : [];

        if ($ide === null) {
            return $this->problemJson(400, '"ide" must be the NeoIDE version (0.4.0).');
        }

        $updates = [];

        foreach ($installed as $id => $current) {
            $extension = is_string($id) && is_string($current) ? $this->marketplace->find($id) : null;
            $latest = $extension !== null ? $this->marketplace->getLatestVersion($extension, $ide) : null;

            if ($latest !== null && version_compare($latest['version'], $current, '>')) {
                $updates[] = ['id' => $extension['id'], 'type' => $extension['type'], 'current' => $current] + $this->presentVersion($extension, $latest);
            }
        }

        $release = $this->presentNeoIde($neoIde);

        return $this->json([
            'neoide' => $release !== null && version_compare($release['version'], $ide, '>') ? $release : null,
            'extensions' => $updates,
        ]);
    }

    /**
     * The latest NeoIDE release: version, date, notes, download URL, size and SHA-256 of the .exe.
     */
    #[Route('/neoide', name: 'neoide', methods: ['GET'])]
    public function neoide(NeoIde $neoIde): Response
    {
        $data = $this->presentNeoIde($neoIde);

        return $data !== null ? $this->json($data, 200, ['Cache-Control' => 'public, max-age=300']) : $this->problemJson(404, 'NeoIDE is not available.');
    }

    protected function presentExtension(array $extension, array $downloads, bool $versions = false): array
    {
        $url = fn (string $route, array $parameters = []): string => $this->generateUrl($route, ['type' => $extension['folder'], 'slug' => $extension['slug']] + $parameters, true);
        $data = [
            'id' => $extension['id'],
            'type' => $extension['type'],
            'name' => $extension['name'],
            'author' => $extension['author'],
            'description' => $extension['description'],
            'tags' => $extension['tags'],
            'license' => $extension['license'],
            'homepage' => $extension['homepage'],
            'page' => $url('marketplace_show'),
            'icon' => $extension['icon'] !== null ? $url('marketplace_media', ['file' => $extension['icon']]) : null,
            'screenshots' => array_map(static fn (string $file): string => $url('marketplace_media', ['file' => $file]), $extension['screenshots']),
            'downloads' => $downloads[$extension['id']] ?? 0,
            'latest' => $this->presentVersion($extension, $extension['latest']),
        ];

        if ($versions) {
            $data['versions'] = array_map(fn (array $version): array => $this->presentVersion($extension, $version), $extension['versions']);
        }

        return $data;
    }

    protected function presentVersion(array $extension, array $version): array
    {
        return [
            'version' => $version['version'],
            'ide' => $version['ide'],
            'date' => $version['date'],
            'changelog' => $version['changelog'],
            'download' => $this->generateUrl('marketplace_download', ['type' => $extension['folder'], 'slug' => $extension['slug'], 'version' => $version['version']], true),
            'sha256' => $version['sha256'],
            'size' => $version['size'],
        ];
    }

    protected function presentNeoIde(NeoIde $neoIde): ?array
    {
        $release = $neoIde->getRelease();

        if ($release === null) {
            return null;
        }

        return [
            'version' => $release['version'],
            'date' => $release['date'],
            'notes' => $release['notes'],
            'page' => $release['page'],
            'url' => $this->generateUrl('neoide_download', [], true),
            'file' => $release['file'],
            'size' => $release['size'],
            'sha256' => $release['sha256'],
        ];
    }

    /**
     * A JSON response cached 5 minutes, answered with 304 when the client already has this revision of the catalog.
     */
    protected function cached(Request $request, callable $data): Response
    {
        $etag = sprintf('"%s"', hash('xxh128', ($this->marketplace->getRevision() ?? 'none') . '|' . $request->getUri() . '|' . date('Y-m-d-H')));
        $headers = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=300'];

        if ($request->headers->get('If-None-Match') === $etag) {
            return new Response('', 304, $headers);
        }

        return $this->json($data(), 200, $headers);
    }

    protected function ideVersion(mixed $version): ?string
    {
        return is_string($version) && preg_match(self::VERSION, $version) === 1 ? $version : null;
    }

    protected function downloads(): array
    {
        try {
            return $this->analytics->downloadTotals(array_keys(Marketplace::TYPES));
        } catch (Throwable) {
            return [];
        }
    }
}
