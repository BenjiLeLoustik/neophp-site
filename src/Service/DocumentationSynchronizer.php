<?php

namespace App\Service;

use FilesystemIterator;
use NeoPHP\Component\Container\Attribute\Autowire;
use NeoPHP\Component\Exception\FrameworkException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class DocumentationSynchronizer
{
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

        if (!is_dir($this->path)) {
            mkdir($this->path, 0775, true);
        }

        if (!is_dir($mirror)) {
            $this->git(sprintf('clone --quiet --mirror %s %s', escapeshellarg($this->repository), escapeshellarg($mirror)));
        } else {
            $this->git(sprintf('--git-dir=%s remote set-url origin %s', escapeshellarg($mirror), escapeshellarg($this->repository)));
            $this->git(sprintf('--git-dir=%s fetch --quiet --prune origin', escapeshellarg($mirror)));
        }

        preg_match_all('#\srefs/heads/(v\d+\.x)$#m', $this->git(sprintf('--git-dir=%s for-each-ref refs/heads', escapeshellarg($mirror))), $matches);
        $branches = $matches[1];
        $result = [];

        foreach ($branches as $branch) {
            $commit = $this->git(sprintf('--git-dir=%s rev-parse --short %s', escapeshellarg($mirror), escapeshellarg($branch)));
            $target = $this->path . '/' . $branch;

            if (is_file($target . '/.commit') && trim((string)file_get_contents($target . '/.commit')) === $commit) {
                $result[$branch] = [$commit, false];

                continue;
            }

            $temporary = $target . '.tmp';
            $this->remove($temporary);
            mkdir($temporary, 0775, true);
            $this->run(sprintf(
                'git --git-dir=%s archive --format=tar %s -- docs src | tar -x -C %s',
                escapeshellarg($mirror),
                escapeshellarg($branch),
                escapeshellarg($temporary),
            ));
            file_put_contents($temporary . '/.commit', $commit);

            $this->remove($target . '.old');
            if (is_dir($target)) {
                rename($target, $target . '.old');
            }
            rename($temporary, $target);
            $this->remove($target . '.old');

            $result[$branch] = [$commit, true];
        }

        foreach (glob($this->path . '/v*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (!in_array(basename($directory), $branches, true)) {
                $this->remove($directory);
            }
        }

        return $result;
    }

    protected function git(string $arguments): string
    {
        return $this->run('git ' . $arguments);
    }

    protected function run(string $command): string
    {
        exec($command . ' 2>&1', $output, $code);

        if ($code !== 0) {
            throw new FrameworkException(
                sprintf('Command failed (%d): %s', $code, implode("\n", $output))
            );
        }

        return trim(implode("\n", $output));
    }

    protected function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $directory,
                FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}