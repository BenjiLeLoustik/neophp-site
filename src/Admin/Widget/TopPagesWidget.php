<?php

declare(strict_types=1);

namespace App\Admin\Widget;

use App\Service\Analytics;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\NeoAdmin\Page\Block\LinkList;
use NeoPHP\NeoAdmin\Page\Contract\BlockInterface;
use NeoPHP\NeoAdmin\Page\Contract\WidgetInterface;

class TopPagesWidget implements WidgetInterface
{
    public function __construct(
        protected Analytics $analytics,
        protected RoutingManagerInterface $routing,
    ) {
    }

    public function build(): BlockInterface
    {
        $links = [];

        foreach ($this->analytics->topPaths(7, 5) as $row) {
            $links[] = ['label' => (string) $row['path'], 'url' => (string) $row['path'], 'meta' => (int) $row['views'], 'target' => '_blank'];
        }

        return new LinkList('Top pages · 7 days', $links, 'file', 'No page view yet.', $this->routing->generate('admin_analytics', ['days' => 7]));
    }
}