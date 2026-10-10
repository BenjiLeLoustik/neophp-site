<?php

declare(strict_types=1);

namespace App\Admin\Widget;

use App\Service\Analytics;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\NeoAdmin\Page\Block\Stat;
use NeoPHP\NeoAdmin\Page\Contract\BlockInterface;
use NeoPHP\NeoAdmin\Page\Contract\WidgetInterface;

class TodayWidget implements WidgetInterface
{
    public function __construct(
        protected Analytics $analytics,
        protected RoutingManagerInterface $routing,
    ) {
    }

    public function build(): BlockInterface
    {
        $today = $this->analytics->summary(1);
        $days = $this->analytics->perDay(8);
        array_pop($days);
        $average = array_sum(array_column($days, 'views')) / max(1, count($days));

        return new Stat(
            'Views today',
            $today['views'],
            'eye',
            $average > 0 ? ($today['views'] - $average) / $average * 100 : null,
            sprintf('%s visitors · %d ms · vs 7-day average', number_format($today['visitors'], 0, ',', ' '), $today['avg_ms']),
            $this->routing->generate('admin_analytics'),
        );
    }
}