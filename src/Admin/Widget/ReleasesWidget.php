<?php

declare(strict_types=1);

namespace App\Admin\Widget;

use App\Service\DocumentationRepository;
use NeoPHP\NeoAdmin\Page\Block\Table;
use NeoPHP\NeoAdmin\Page\Column;
use NeoPHP\NeoAdmin\Page\Contract\BlockInterface;
use NeoPHP\NeoAdmin\Page\Contract\WidgetInterface;

class ReleasesWidget implements WidgetInterface
{
    public function __construct(
        protected DocumentationRepository $documentation,
    ) {
    }

    public function build(): BlockInterface
    {
        $latest = $this->documentation->getLatestVersion();
        $rows = [];

        foreach ($latest !== null ? $this->documentation->getReleases($latest, 5) : [] as $release) {
            $rows[] = ['version' => $release['version'], 'notes' => trim(html_entity_decode(strip_tags($release['notes'])))];
        }

        return (new Table(
            'Latest releases',
            $rows,
            [Column::code('version', 'Version'), Column::text('notes', 'Notes')->truncate(90)],
            'tag',
            'No release found.',
        ))->span(3);
    }
}