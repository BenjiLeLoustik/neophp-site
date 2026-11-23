<?php

declare(strict_types=1);

namespace App\Service\Git;

use FilesystemIterator;
use NeoPHP\Component\Exception\Exception\FrameworkException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Shell helpers shared by the synchronizers mirroring a git repository into var/.
 */
trait RunsGit
{
    protected function mirror(string $repository, string $mirror): void
    {
        if (!is_dir(dirname($mirror))) {
            mkdir(dirname($mirror), 0775, true);
        }

        if (!is_dir($mirror)) {
            $this->git(sprintf('clone --quiet --mirror %s %s', escapeshellarg($repository), escapeshellarg($mirror)));

            return;
        }

        $this->git(sprintf('--git-dir=%s remote set-url origin %s', escapeshellarg($mirror), escapeshellarg($repository)));
        $this->git(sprintf('--git-dir=%s fetch --quiet --prune origin', escapeshellarg($mirror)));
    }

    protected function exists(string $mirror, string $branch, string $path): bool
    {
        exec(sprintf('git --git-dir=%s cat-file -e %s 2>&1', escapeshellarg($mirror), escapeshellarg($branch . ':' . $path)), $output, $code);

        return $code === 0;
    }

    /**
     * Exports $paths of $branch into $target, atomically (a temporary folder is renamed).
     */
    protected function export(string $mirror, string $branch, array $paths, string $target, string $commit): void
    {
        $temporary = $target . '.tmp';
        $this->remove($temporary);
        mkdir($temporary, 0775, true);

        if ($paths !== []) {
            $this->run(sprintf(
                'git --git-dir=%s archive --format=tar %s -- %s | tar -x -C %s',
                escapeshellarg($mirror),
                escapeshellarg($branch),
                implode(' ', array_map('escapeshellarg', $paths)),
                escapeshellarg($temporary),
            ));
        }

        file_put_contents($temporary . '/.commit', $commit);

        $this->remove($target . '.old');
        if (is_dir($target)) {
            rename($target, $target . '.old');
        }
        rename($temporary, $target);
        $this->remove($target . '.old');
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
