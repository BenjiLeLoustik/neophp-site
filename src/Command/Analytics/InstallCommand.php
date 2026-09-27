<?php

declare(strict_types=1);

namespace App\Command\Analytics;

use App\Service\Analytics;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;

#[AsCommand(name: 'analytics:install', description: 'Creates the analytics tables in the SQLite database')]
class InstallCommand extends AbstractConsole
{
    public function __construct(protected Analytics $analytics)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('drop', null, InputOption::VALUE_NONE, 'Drops the tables first (the data is lost)');
        $input->addOption('purge', null, InputOption::VALUE_REQUIRED, 'Deletes the page views older than this number of days');
        $this->addExample('analytics:install');
        $this->addExample('analytics:install --purge=365');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $purge = $input->getOption('purge');

        if ($purge !== null) {
            $output->success(sprintf('%d page view(s) deleted.', $this->analytics->purge(max(1, (int) $purge))));

            return self::SUCCESS;
        }

        if ($input->getOption('drop') && !$output->confirm('Drop the analytics tables and lose every page view?', false)) {
            return self::SUCCESS;
        }

        $tables = $this->analytics->install((bool) $input->getOption('drop'));
        $output->success('Analytics tables ready: ' . implode(', ', $tables) . '.');

        return self::SUCCESS;
    }
}