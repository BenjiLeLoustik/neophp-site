<?php

declare(strict_types=1);

namespace App\Admin\Widget;

use App\Service\Analytics;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\NeoAdmin\Page\Block\LinkList;
use NeoPHP\NeoAdmin\Page\Contract\BlockInterface;
use NeoPHP\NeoAdmin\Page\Contract\WidgetInterface;
use Throwable;

class DownloadsWidget implements WidgetInterface
{
    public function __construct(
        protected Analytics $analytics,
        protected RoutingManagerInterface $routing,
    ) {
    }

    public function build(): BlockInterface
    {
        $links = [];

        try {
            $rows = $this->analytics->topDownloads(7, 5);
        } catch (Throwable) {
            $rows = [];
        }

        foreach ($rows as $row) {
            $url = $row['kind'] === 'ide'
                ? $this->routing->generate('neoide_index')
                : $this->routing->generate('marketplace_show', ['type' => $row['kind'] . 's', 'slug' => (string) preg_replace('/^(plugin|theme)-/', '', (string) $row['item'])]);
            $links[] = ['label' => $row['kind'] === 'ide' ? 'NeoIDE' : (string) $row['item'], 'url' => $url, 'meta' => (int) $row['downloads'], 'target' => '_blank'];
        }

        return new LinkList('Downloads · 7 days', $links, 'box', 'No download yet.', $this->routing->generate('admin_downloads', ['days' => 7]));
    }
}
