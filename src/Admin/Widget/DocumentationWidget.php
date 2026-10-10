<?php

declare(strict_types=1);

namespace App\Admin\Widget;

use App\Service\DocumentationRepository;
use NeoPHP\Component\Routing\RoutingManagerInterface;
use NeoPHP\NeoAdmin\Page\Block\Stat;
use NeoPHP\NeoAdmin\Page\Contract\BlockInterface;
use NeoPHP\NeoAdmin\Page\Contract\WidgetInterface;

class DocumentationWidget implements WidgetInterface
{
    public function __construct(
        protected DocumentationRepository $documentation,
        protected RoutingManagerInterface $routing,
    ) {
    }

    public function build(): BlockInterface
    {
        $latest = $this->documentation->getLatestVersion();
        $url = $this->routing->getRoutes()->has('docs_index') ? $this->routing->generate('docs_index') : null;

        if ($latest === null) {
            return new Stat('Documentation', 'None', 'file', null, 'No version found: run the synchronization.', $url);
        }

        $features = array_sum(array_map('count', $this->documentation->getFeatures($latest)));

        return (new Stat(
            'Documentation',
            $latest,
            'file',
            null,
            sprintf(
                '%d version(s) · %d features · %s',
                count($this->documentation->getVersions()),
                $features,
                $this->documentation->isSynchronized() ? 'synchronized' : 'from vendor',
            ),
            $url,
        ))->more('Open the docs');
    }
}