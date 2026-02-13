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
            )
            ->addOption(
                'only',
                'o',
                InputOption::VALUE_REQUIRED,
                'Import only specific sections (comma-separated): inventory, map, industry, dogma, reference, planetary'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('EVE Online SDE Import');

        $onlyRaw = $input->getOption('only');
        $onlySections = null;

        if ($onlyRaw !== null) {
            $validSections = SdeImportService::VALID_SECTIONS;
            $onlySections = array_map('trim', explode(',', $onlyRaw));
            $invalid = array_diff($onlySections, $validSections);

            if (!empty($invalid)) {
                $io->error(sprintf(
                    'Invalid section(s): %s. Valid: %s',
                    implode(', ', $invalid),
                    implode(', ', $validSections),
                ));

                return Command::FAILURE;
            }
        }

        if (!$input->getOption('force')) {
            if ($onlySections !== null) {
                $io->warning([
                    'Partial SDE import: ' . implode(', ', $onlySections),
                    'Only the selected sections will be re-imported.',
                ]);
            } else {
                $io->warning([
                    'This command will download the EVE Online Static Data Export',
                    'and replace all existing SDE data in the database.',
                ]);
            }

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
            }, $onlySections);

            $duration = round(microtime(true) - $startTime, 2);

            $io->newLine();
            $label = $onlySections !== null ? implode(', ', $onlySections) : 'full';
            $io->success("SDE import ({$label}) completed in {$duration} seconds!");

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
