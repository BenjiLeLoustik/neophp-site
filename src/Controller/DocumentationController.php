<?php

namespace App\Controller;

use App\Service\DocumentationRepository;
use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;
use NeoPHP\Package\Markdown\Document\MarkdownDocument;

#[Route('/docs', name: 'docs_')]
class DocumentationController extends AbstractController
{

    public function __construct(protected DocumentationRepository $documentationRepository)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $version = $this->documentationRepository->getLatestVersion();

        if ($version === null) {
            throw $this->createNotFoundException('No documentation found.');
        }

        return $this->catalog($version);
    }

    #[Route('/{version}/all', name: 'catalog', methods: ['GET'], requirements: ['version' => 'v[0-9]+\.[0-9x]+'])]
    public function catalog(string $version): Response
    {
        if (!$this->documentationRepository->hasVersion($version)) {
            throw $this->createNotFoundException('Documentation not found.');
        }

        $guide = $this->documentationRepository->getGuide($version);

        return $this->render('docs/index.html.twig', [
            'version' => $version,
            'versions' => $this->documentationRepository->getVersions(),
            'guide' => $guide?->getDescription(),
            'catalog' => $this->documentationRepository->getCatalog($version),
        ]);
    }

    #[Route('/{version}', name: 'guide', methods: ['GET'], requirements: ['version' => 'v[0-9]+\.[0-9x]+'])]
    public function guide(string $version): Response
    {
        $document = $this->documentationRepository->hasVersion($version)
            ? $this->documentationRepository->getGuide($version)
            : null;

        if ($document === null) {
            throw $this->createNotFoundException('Documentation not found.');
        }

        return $this->renderDocument($document, $version);
    }

    #[Route('/{version}/{group}/{slug}', name: 'feature', methods: ['GET'], requirements: ['version' => 'v[0-9]+\.[0-9x]+', 'group' => 'components|packages|process', 'slug' => '[a-z0-9-]+'])]
    public function feature(string $version, string $group, string $slug): Response
    {
        $document = $this->documentationRepository->getFeature($version, $group, $slug);

        if ($document === null) {
            throw $this->createNotFoundException('Documentation not found.');
        }

        return $this->renderDocument($document, $version, $group, $slug);
    }

    protected function renderDocument(MarkdownDocument $document, string $version, ?string $group = null, ?string $slug = null): Response
    {
        $headings = array_values(array_filter($document->getHeadings(), static fn ($heading): bool => $heading->getLevel() === 2 && strtolower($heading->getText()) !== 'summary'));

        return $this->render('docs/show.html.twig', [
            'title' => $document->getTitle() ?? 'Documentation',
            'description' => $document->getDescription(),
            'content' => preg_replace(['#<h1[^>]*>.*?</h1>#s', '#<h2 id="summary">.*?</h2>\s*<ul>.*?</ul>#s'], '', $document->toHtml(), 1),
            'headings' => array_map(static fn ($heading): array => $heading->toArray(), $headings),
            'version' => $version,
            'versions' => $this->documentationRepository->getVersions(),
            'features' => $this->documentationRepository->getFeatures($version),
            'currentGroup' => $group,
            'currentSlug' => $slug,
        ]);
    }
}