<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MoonChunk\MoonCompositionImporter;
use App\Service\MoonChunk\MoonScanException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:import-moon-compositions',
    description: 'Import the compositions of the corporation moons from a community spreadsheet CSV export',
)]
final class ImportMoonCompositionsCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MoonCompositionImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'Public CSV export URL of the spreadsheet');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $csv = $this->httpClient->request('GET', (string) $input->getArgument('url'))->getContent();
        } catch (ExceptionInterface $failure) {
            $io->error('Download failed: ' . $failure->getMessage());

            return Command::FAILURE;
        }

        try {
            $report = $this->importer->import($csv);
        } catch (MoonScanException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->writeln([
            sprintf('Moons in database: %d', $report->moonsInDatabase),
            sprintf('Matched in the sheet: %d', $report->matched),
            sprintf('Imported: %d', $report->imported),
            sprintf('Skipped (already scanned): %d', $report->skippedAlreadyScanned),
            sprintf('Not found in the sheet: %d', $report->notFoundInSheet),
            sprintf('Rejected: %d', count($report->rejected)),
        ]);
        foreach ($report->rejected as $rejection) {
            $io->writeln(sprintf('  %s: %s', $rejection['moonName'], $rejection['reason']));
        }

        return Command::SUCCESS;
    }
}
