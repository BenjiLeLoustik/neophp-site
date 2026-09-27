<?php

declare(strict_types=1);

namespace App\Command\Docs;

use App\Service\DocumentationSynchronizer;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use Throwable;

#[AsCommand(name: 'docs:sync', description: 'Synchronizes the documentation with the vX.x branches of the framework repository')]
class SyncCommand extends AbstractConsole
{
    public function __construct(protected DocumentationSynchronizer $synchronizer)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->setHelp('Clones the framework repository (FRAMEWORK_REPOSITORY) into var/framework/ and exports the docs of every vX.x branch.');
        $this->addExample('docs:sync');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        try {
            $branches = $this->synchronizer->synchronize();
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($branches === []) {
            $output->warning('No vX.x branch found in the framework repository.');

            return self::SUCCESS;
        }

        foreach ($branches as $branch => [$commit, $updated]) {
            $output->writeln(
                sprintf('  %s  %s  %s', $branch, $commit, $updated ? '<success>updated</success>' : '<muted>up to date</muted>'))
            ;
        }

        return self::SUCCESS;
    }
}