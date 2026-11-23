<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DownloadTracker;
use App\Service\Marketplace;
use App\Service\NeoIde;
use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;

#[Route('/neoide', name: 'neoide_')]
class NeoIdeController extends AbstractController
{
    public function __construct(protected NeoIde $neoIde)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Marketplace $marketplace): Response
    {
        return $this->render('neoide/index.html.twig', [
            'release' => $this->neoIde->getRelease(),
            'notes' => $this->neoIde->getNotesHtml(),
            'releasesUrl' => $this->neoIde->getReleasesUrl(),
            'pluginCount' => count($marketplace->all('plugin')),
            'themeCount' => count($marketplace->all('theme')),
        ]);
    }

    /**
     * Counts the download, then redirects to the .exe of the latest GitHub release.
     */
    #[Route('/download', name: 'download', methods: ['GET'])]
    public function download(Request $request, DownloadTracker $tracker): Response
    {
        $release = $this->neoIde->getRelease();

        if ($release === null) {
            throw $this->createNotFoundException('NeoIDE is not available.');
        }

        $tracker->track($request, 'ide', 'neoide', $release['version']);

        return $this->redirect($release['url']);
    }
}
