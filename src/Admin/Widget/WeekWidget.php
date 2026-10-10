<?php

declare(strict_types=1);

namespace App\Admin\Widget;

use App\Service\Analytics;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\NeoAdmin\Page\Block\Stat;
use NeoPHP\NeoAdmin\Page\Contract\BlockInterface;
use NeoPHP\NeoAdmin\Page\Contract\WidgetInterface;

class WeekWidget implements WidgetInterface
{
    public function __construct(
        protected Analytics $analytics,
        protected RoutingManagerInterface $routing,
    ) {
    }

    public function build(): BlockInterface
    {
        $week = $this->analytics->summary(7);
        $previous = $this->analytics->summary(14)['views'] - $week['views'];

        return new Stat(
            'Views · 7 days',
            $week['views'],
            'chart',
            $previous > 0 ? ($week['views'] - $previous) / $previous * 100 : null,
            sprintf('%s unique visitors · vs previous 7 days', number_format($week['visitors'], 0, ',', ' ')),
            $this->routing->generate('admin_analytics', ['days' => 7]),
        );
    }
}