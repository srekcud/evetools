<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Sde\SdeImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sde:import',
    description: 'Import EVE Online Static Data Export (SDE) into the database',
)]
class SdeImportCommand extends Command
{
    public function __construct(
        private readonly SdeImportService $sdeImportService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force import without confirmation'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('EVE Online SDE Import');

        if (!$input->getOption('force')) {
            $io->warning([
                'This command will download the EVE Online Static Data Export',
                'and replace all existing SDE data in the database.',
                '',
                'Tables affected:',
                '  - sde_inv_categories',
                '  - sde_inv_groups',
                '  - sde_inv_types',
                '  - sde_inv_market_groups',
                '  - sde_map_regions',
                '  - sde_map_constellations',
                '  - sde_map_solar_systems',
                '  - sde_sta_stations',
            ]);

            if (!$io->confirm('Do you want to continue?', false)) {
                $io->info('Import cancelled.');
                return Command::SUCCESS;
            }
        }

        $io->section('Starting import...');

        $startTime = microtime(true);

        try {
            $this->sdeImportService->downloadAndImport(function (string $message) use ($io) {
                $io->text($message);
            });

            $duration = round(microtime(true) - $startTime, 2);

            $io->newLine();
            $io->success("SDE import completed in {$duration} seconds!");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error([
                'Import failed!',
                $e->getMessage(),
            ]);

            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
