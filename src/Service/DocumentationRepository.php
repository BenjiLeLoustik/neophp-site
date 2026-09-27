<?php

declare(strict_types=1);

namespace App\Service;

use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Package\Markdown\Contract\MarkdownParserInterface;
use NeoPHP\Package\Markdown\Document\MarkdownDocument;

class DocumentationRepository
{

    public const GROUPS = ['components', 'packages', 'process'];

    public function __construct(
        protected MarkdownParserInterface $markdownParser,
        #[Autowire('%kernel.root_path%/vendor/neophp/framework')]
        protected string $frameworkPath,
    ) {
    }

    public function getVersions(): array
    {
        $versions = array_map('basename', glob($this->frameworkPath . '/docs/v*', GLOB_ONLYDIR) ?: []);
        usort($versions, static fn (string $a, string $b): int => strnatcmp($b, $a));

        return $versions;
    }

    public function getLatestVersion(): ?string
    {
        return $this->getVersions()[0] ?? null;
    }

    public function hasVersion(string $version): bool
    {
        return in_array($version, $this->getVersions());
    }

    public function getFeatures(string $version): array
    {
        $features = [];

        foreach (self::GROUPS as $group) {
            foreach (glob(sprintf('%s/src/%s/*/Docs/%s/README.md', $this->frameworkPath, $group, $version)) ?: [] as $file) {
                $name = basename(dirname($file, 3));
                $features[$group][strtolower($name)] = $name;
            }
        }

        return $features;
    }

    public function getGuide(string $version): ?MarkdownDocument
    {
        return $this->read(sprintf('%s/docs/%s/README.md', $this->frameworkPath, $version));
    }

    public function getFeature(string $version, string $group, string $slug): ?MarkdownDocument
    {
        $name = $this->getFeatureName($version, $group, $slug);

        if ($name === null) {
            return null;
        }

        return $this->read(sprintf('%s/src/%s/%s/Docs/%s/README.md', $this->frameworkPath, $group, $name, $version));
    }

    public function getFeatureName(string $version, string $group, string $slug): ?string
    {
        return $this->getFeatures($version)[$group][$slug] ?? null;
    }

    public function getCatalog(string $version): array
    {
        $catalog = [];

        foreach ($this->getFeatures($version) as $group => $items) {
            foreach ($items as $slug => $name) {
                $document = $this->getFeature($version, $group, $slug);
                $topics = [];

                foreach ($document?->getHeadings() ?? [] as $heading) {
                    if ($heading->getLevel() >= 2 && !in_array(strtolower($heading->getText()), ['summary', 'changelog'], true)) {
                        $topics[] = ['text' => $heading->getText(), 'id' => $heading->getId()];
                    }
                }

                $catalog[$group][] = [
                    'slug' => $slug,
                    'name' => $name,
                    'description' => $document?->getDescription(),
                    'topics' => $topics,
                ];
            }
        }

        return $catalog;
    }

    public function getReleases(string $version, int $limit = 4): array
    {
        $section = $this->getGuide($version)?->getSection('Changelog');

        if ($section === null) {
            return [];
        }

        $releases = [];

        foreach (preg_split('/\R/', $section->getMarkdown()) ?: [] as $line) {
            if (preg_match('/^- (\S+) — (.+)$/u', trim($line), $match) === 1) {
                $releases[] = ['version' => $match[1], 'notes' => $this->markdownParser->toHtml($match[2])];
            }

            if (count($releases) >= $limit) {
                break;
            }
        }

        return $releases;
    }

    protected function read(string $file): ?MarkdownDocument
    {
        return is_file($file) ? $this->markdownParser->get($file) : null;
    }

}