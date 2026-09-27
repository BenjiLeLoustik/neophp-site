<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DocumentationRepository;
use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(DocumentationRepository $docs): Response
    {
        $version = $docs->getLatestVersion();
        $features = $version !== null ? $docs->getFeatures($version) : [];
        $releases = $version !== null ? $docs->getReleases($version) : [];

        return $this->render('home/index.html.twig', [
            'version' => $version,
            'features' => $features,
            'featureCount' => array_sum(array_map('count', $features)),
            'releases' => $releases,
            'latestRelease' => $releases[0]['version'] ?? null,
        ]);
    }
}