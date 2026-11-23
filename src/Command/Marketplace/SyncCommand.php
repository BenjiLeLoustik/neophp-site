<?php

declare(strict_types=1);

namespace App\Command\Marketplace;

use App\Service\Marketplace;
use App\Service\MarketplaceSynchronizer;
use App\Service\NeoIde;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use Throwable;

#[AsCommand(name: 'marketplace:sync', description: 'Synchronizes the NeoIDE plugins and themes, and the latest NeoIDE release')]
class SyncCommand extends AbstractConsole
{
    public function __construct(protected MarketplaceSynchronizer $synchronizer, protected Marketplace $marketplace, protected NeoIde $neoIde)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->setHelp('Clones the marketplace repository (MARKETPLACE_REPOSITORY), packages every new version of its plugin-* and theme-* folders, then reads the latest release of NEOIDE_REPOSITORY on GitHub.');
        $this->addExample('marketplace:sync');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $code = self::SUCCESS;

        try {
            $result = $this->synchronizer->synchronize();
            $output->writeln(sprintf('  marketplace  %s  %s', $result['commit'] ?? '-', $result['updated'] ? '<success>updated</success>' : '<muted>up to date</muted>'));

            foreach ($result['packaged'] as $folder) {
                $output->writeln(sprintf('  <success>packaged</success>  %s %s', $folder, $this->marketplace->find($folder)['latest']['version'] ?? ''));
            }

            $output->writeln(sprintf('  %d plugin(s), %d theme(s)', count($this->marketplace->all('plugin')), count($this->marketplace->all('theme'))));

            $warned = array_map(static fn (string $warning): string => strtok($warning, ':'), $result['warnings']);
            $errors = array_filter($this->marketplace->getErrors(), static fn (string $error): bool => !in_array(strtok($error, ':'), $warned, true));

            foreach ([...$result['warnings'], ...$errors] as $warning) {
                $output->warning($warning);
            }
        } catch (Throwable $exception) {
            $output->error('Marketplace: ' . $exception->getMessage());
            $code = self::FAILURE;
        }

        [$release, $error] = $this->neoIde->refresh();
        $output->writeln(sprintf('  neoide       %s', $release !== null ? $release['tag'] . '  ' . $release['file'] : '<muted>no release: not available</muted>'));

        if ($error !== null) {
            $output->warning('NeoIDE: ' . $error);
        }

        return $code;
    }
}
