<?php

declare(strict_types=1);

namespace App\Admin\Widget;

use App\Service\Analytics;
use NeoPHP\NeoAdmin\Page\Block\Chart;
use NeoPHP\NeoAdmin\Page\Contract\BlockInterface;
use NeoPHP\NeoAdmin\Page\Contract\WidgetInterface;

class TrafficWidget implements WidgetInterface
{
    public function __construct(
        protected Analytics $analytics,
    ) {
    }

    public function build(): BlockInterface
    {
        return (new Chart(
            'Views · 14 days',
            $this->analytics->perDay(14),
            ['views' => 'Views', 'visitors' => 'Visitors'],
            'chart',
            'No page view yet.',
        ))->span(2);
    }
}