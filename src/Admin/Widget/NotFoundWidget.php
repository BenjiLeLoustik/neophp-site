<?php

declare(strict_types=1);

namespace App\Admin\Widget;

use App\Service\Analytics;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\NeoAdmin\Page\Block\LinkList;
use NeoPHP\NeoAdmin\Page\Contract\BlockInterface;
use NeoPHP\NeoAdmin\Page\Contract\WidgetInterface;

class NotFoundWidget implements WidgetInterface
{
    public function __construct(
        protected Analytics $analytics,
        protected RoutingManagerInterface $routing,
    ) {
    }

    public function build(): BlockInterface
    {
        $links = [];

        foreach ($this->analytics->notFound(7, 5) as $row) {
            $links[] = ['label' => (string) $row['path'], 'url' => (string) $row['path'], 'meta' => (int) $row['views'], 'target' => '_blank'];
        }

        return new LinkList('Not found (404) · 7 days', $links, 'search', 'No broken link.', $this->routing->generate('admin_analytics', ['days' => 7]));
    }
}