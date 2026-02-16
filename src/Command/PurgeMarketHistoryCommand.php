<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MarketPriceHistoryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-market-history',
    description: 'Delete market price history entries older than 365 days',
)]
class PurgeMarketHistoryCommand extends Command
{
    public function __construct(
        private readonly MarketPriceHistoryRepository $historyRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->info('Purging market history entries older than 365 days...');

        $deleted = $this->historyRepository->purgeOlderThan(365);

        $io->success(sprintf('Purged %d entries.', $deleted));

        return Command::SUCCESS;
    }
}
