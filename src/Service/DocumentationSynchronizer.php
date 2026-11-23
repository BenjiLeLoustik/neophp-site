<?php

namespace App\Service;

use App\Service\Git\RunsGit;
use NeoPHP\Component\Container\Attribute\Autowire;

class DocumentationSynchronizer
{
    use RunsGit;

    public const BRANCH_PATTERN = '/^v\d+\.x$/';

    public function __construct(
        #[Autowire('%kernel.root_path%/var/framework')]
        protected string $path,
        #[Autowire(env: 'FRAMEWORK_REPOSITORY')]
        protected string $repository,
    )
    {
    }

    public function synchronize(): array
    {
        $mirror = $this->path . '/repository.git';
        $this->mirror($this->repository, $mirror);

        preg_match_all('#\srefs/heads/(v\d+\.x)$#m', $this->git(sprintf('--git-dir=%s for-each-ref refs/heads', escapeshellarg($mirror))), $matches);
        $branches = $matches[1];
        $result = [];

        foreach ($branches as $branch) {
            $commit = $this->git(sprintf('--git-dir=%s rev-parse --short %s', escapeshellarg($mirror), escapeshellarg($branch)));
            $target = $this->path . '/' . $branch;
            $paths = ['docs', 'src'];
            $changelog = $this->exists($mirror, $branch, 'CHANGELOG.md');

            if ($changelog) {
                $paths[] = 'CHANGELOG.md';
            }

            if (is_file($target . '/.commit') && trim((string)file_get_contents($target . '/.commit')) === $commit && (!$changelog || is_file($target . '/CHANGELOG.md'))) {
                $result[$branch] = [$commit, false];

                continue;
            }

            $this->export($mirror, $branch, $paths, $target, $commit);
            $result[$branch] = [$commit, true];
        }

        foreach (glob($this->path . '/v*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (!in_array(basename($directory), $branches, true)) {
                $this->remove($directory);
            }
        }

        return $result;
    }
}
