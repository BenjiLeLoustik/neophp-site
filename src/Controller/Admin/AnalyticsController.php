<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Analytics;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;
use NeoPHP\NeoAdmin\Contract\AbstractAdminController;
use NeoPHP\NeoAdmin\Page\Action;
use NeoPHP\NeoAdmin\Page\Block\Grid;
use NeoPHP\NeoAdmin\Page\Column;

class AnalyticsController extends AbstractAdminController
{
    public const PERIODS = [7, 30, 90];

    #[Route('/admin/analytics', name: 'admin_analytics', methods: ['GET'])]
    public function index(Request $request, Analytics $analytics): Response
    {
        $this->denyUnlessAdmin();

        $days = (int) $request->query->get('days', 7);
        $days = in_array($days, self::PERIODS, true) ? $days : 7;

        if (!$analytics->isInstalled()) {
            $analytics->install();
        }

        $summary = $analytics->summary($days);
        $page = static fn (string $key, string $label): Column => Column::code($key, $label)->url(static fn (array $row): string => (string) $row[$key])->blank();
        $periods = [];

        foreach (self::PERIODS as $period) {
            $periods[$period] = $period . ' days';
        }

        return $this->page('Analytics')
            ->description(sprintf('Anonymous page views of the last %d days (UTC).', $days))
            ->action(Action::link('Open the site', $this->generateUrl('home'), 'external')->blank())
            ->tabs('days', $periods, $days)
            ->grid(3, static fn (Grid $grid) => $grid
                ->stat('Page views', (int) $summary['views'], 'eye')
                ->stat('Unique visitors', (int) $summary['visitors'], 'users')
                ->stat('Average response', $summary['avg_ms'] . ' ms', 'chart'))
            ->chart('Views per day', $analytics->perDay($days), ['views' => 'Views', 'visitors' => 'Visitors'], empty: 'No page view yet.')
            ->grid(2, static fn (Grid $grid) => $grid
                ->table('Top pages', $analytics->topPaths($days), [
                    $page('path', 'Page'),
                    Column::number('views', 'Views'),
                    Column::number('visitors', 'Visitors'),
                    Column::number('avg_ms', 'ms'),
                ], 'file', 'No page view yet.')
                ->table('Top documentation', $analytics->topPaths($days, 10, 'docs_feature'), [
                    $page('path', 'Documentation'),
                    Column::number('views', 'Views'),
                    Column::number('visitors', 'Visitors'),
                    Column::number('avg_ms', 'ms'),
                ], 'list', 'No documentation read yet.')
                ->table('Referrers', $analytics->referrers($days), [
                    Column::text('referrer', 'Domain'),
                    Column::number('views', 'Views'),
                ], 'link', 'No external referrer yet.')
                ->table('Not found (404)', $analytics->notFound($days), [
                    Column::code('path', 'Page'),
                    Column::number('views', 'Views'),
                ], 'search', 'No broken link. 🎉'))
            ->render();
    }
}