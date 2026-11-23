<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Analytics;
use App\Service\DownloadTracker;
use App\Service\Marketplace;
use App\Service\NeoIde;
use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\BinaryFileResponse;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/marketplace', name: 'marketplace_')]
class MarketplaceController extends AbstractController
{
    public const REQUIREMENTS = ['type' => 'plugins|themes', 'slug' => Marketplace::SLUG_PATTERN];

    public function __construct(
        protected Marketplace $marketplace,
        protected Analytics $analytics,
        #[Autowire(env: 'MARKETPLACE_REPOSITORY')] protected string $repository,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $type = array_search($request->query->get('type'), Marketplace::TYPES, true);

        return $this->render('marketplace/index.html.twig', [
            'extensions' => $this->marketplace->all(),
            'type' => $type !== false ? $type : null,
            'downloads' => $this->downloads(),
            'synchronized' => $this->marketplace->isSynchronized(),
        ]);
    }

    #[Route('/{type}/{slug}', name: 'show', methods: ['GET'], requirements: self::REQUIREMENTS)]
    public function show(NeoIde $neoIde, string $type, string $slug): Response
    {
        $extension = $this->findOr404($type, $slug);

        return $this->render('marketplace/show.html.twig', [
            'extension' => $extension,
            'readme' => $this->marketplace->getReadme($extension),
            'downloads' => $this->downloads()[$extension['id']] ?? 0,
            'neoide' => $neoIde->isAvailable(),
            'source' => $extension['repository'] ?? sprintf('%s/tree/HEAD/%s', preg_replace('/\.git$/', '', $this->repository), $extension['directory']),
        ]);
    }

    #[Route('/{type}/{slug}/media/{file}', name: 'media', methods: ['GET'], requirements: self::REQUIREMENTS + ['file' => Marketplace::MEDIA_PATTERN])]
    public function media(string $type, string $slug, string $file): BinaryFileResponse
    {
        $path = $this->marketplace->getMediaPath($this->findOr404($type, $slug), $file);

        if ($path === null) {
            throw $this->createNotFoundException('Media not found.');
        }

        return $this->fileResponse($path, null, BinaryFileResponse::DISPOSITION_INLINE, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The .zip of the version ("latest" = the most recent one).
     */
    #[Route('/{type}/{slug}/download/{version}', name: 'download', methods: ['GET'], requirements: self::REQUIREMENTS + ['version' => 'latest|\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?'])]
    public function download(Request $request, DownloadTracker $tracker, string $type, string $slug, string $version): BinaryFileResponse
    {
        $extension = $this->findOr404($type, $slug);
        $item = $version === 'latest' ? $extension['latest'] : $this->marketplace->findVersion($extension, $version);
        $file = $item !== null ? $this->marketplace->getPackagePath($extension, $item) : null;

        if ($file === null) {
            throw $this->createNotFoundException('Version not found.');
        }

        $tracker->track($request, $extension['type'], $extension['id'], $item['version']);

        return $this->fileResponse($file, sprintf('%s-%s.zip', $extension['id'], $item['version']), BinaryFileResponse::DISPOSITION_ATTACHMENT, [
            'X-Checksum-Sha256' => $item['sha256'],
        ]);
    }

    protected function findOr404(string $type, string $slug): array
    {
        $extension = $this->marketplace->findBySlug((string) array_search($type, Marketplace::TYPES, true), $slug);

        if ($extension === null) {
            throw $this->createNotFoundException('Extension not found.');
        }

        return $extension;
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
