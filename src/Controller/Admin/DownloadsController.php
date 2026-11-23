<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Analytics;
use App\Service\Marketplace;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;
use NeoPHP\NeoAdmin\Contract\AbstractAdminController;
use NeoPHP\NeoAdmin\Page\Action;
use NeoPHP\NeoAdmin\Page\Block\Grid;
use NeoPHP\NeoAdmin\Page\Column;

class DownloadsController extends AbstractAdminController
{
    #[Route('/admin/downloads', name: 'admin_downloads', methods: ['GET'])]
    public function index(Request $request, Analytics $analytics, Marketplace $marketplace): Response
    {
        $this->denyUnlessAdmin();

        $days = (int) $request->query->get('days', 30);
        $days = in_array($days, AnalyticsController::PERIODS, true) ? $days : 30;

        if (!$analytics->isInstalled()) {
            $analytics->install();
        }

        $periods = [];

        foreach (AnalyticsController::PERIODS as $period) {
            $periods[$period] = $period . ' days';
        }

        $ide = $analytics->topDownloads($days, 1, 'ide')[0] ?? ['downloads' => 0];
        $extensions = array_merge($analytics->topDownloads($days, 50, 'plugin'), $analytics->topDownloads($days, 50, 'theme'));
        usort($extensions, static fn (array $a, array $b): int => $b['downloads'] <=> $a['downloads']);
        $errors = array_map(static fn (string $error): array => ['error' => $error], $marketplace->getErrors());

        return $this->page('Downloads')
            ->description(sprintf('Downloads of NeoIDE and of the marketplace extensions, last %d days (UTC). Revision of the marketplace: %s.', $days, $marketplace->getRevision() ?? 'not synchronized'))
            ->action(Action::link('Marketplace', $this->generateUrl('marketplace_index'), 'external')->blank())
            ->tabs('days', $periods, $days)
            ->grid(3, static fn (Grid $grid) => $grid
                ->stat('NeoIDE downloads', (int) $ide['downloads'], 'box')
                ->stat('Extension downloads', array_sum(array_column($extensions, 'downloads')), 'tag')
                ->stat('Extensions', count($marketplace->all()), 'list'))
            ->grid(2, static fn (Grid $grid) => $grid
                ->table('Top extensions', array_slice($extensions, 0, 20), [
                    Column::code('item', 'Extension'),
                    Column::text('kind', 'Type'),
                    Column::number('downloads', 'Downloads'),
                    Column::number('visitors', 'Visitors'),
                ], 'tag', 'No download yet.')
                ->table('Invalid manifests', $errors, [
                    Column::text('error', 'Error'),
                ], 'shield', 'Every manifest is valid. 🎉'))
            ->render();
    }
}
