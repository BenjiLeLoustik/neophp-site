<?php

declare(strict_types=1);

namespace App\Admin\Widget;

use App\Service\Analytics;
use NeoPHP\NeoAdmin\Page\Block\LinkList;
use NeoPHP\NeoAdmin\Page\Contract\BlockInterface;
use NeoPHP\NeoAdmin\Page\Contract\WidgetInterface;

class ReferrersWidget implements WidgetInterface
{
    public function __construct(
        protected Analytics $analytics,
    ) {
    }

    public function build(): BlockInterface
    {
        $links = [];

        foreach ($this->analytics->referrers(7, 5) as $row) {
            $links[] = ['label' => (string) $row['referrer'], 'meta' => (int) $row['views']];
        }

        return new LinkList('Referrers · 7 days', $links, 'link', 'No external referrer yet.');
    }
}