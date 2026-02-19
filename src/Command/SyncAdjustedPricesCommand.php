<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Industry\EsiCostIndexService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-adjusted-prices',
    description: 'Sync ESI adjusted prices for all types (used for job install cost estimation)',
)]
class SyncAdjustedPricesCommand extends Command
{
    public function __construct(
        private readonly EsiCostIndexService $costIndexService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->info('Fetching ESI adjusted prices...');

        try {
            $count = $this->costIndexService->syncAdjustedPrices();
            $io->success(sprintf('Cached %d adjusted prices.', $count));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Sync failed: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
