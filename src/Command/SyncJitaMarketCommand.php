<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\JitaMarketService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-jita-market',
    description: 'Sync Jita (The Forge) market prices for all industry materials',
)]
class SyncJitaMarketCommand extends Command
{
    public function __construct(
        private readonly JitaMarketService $jitaMarketService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->info('Starting Jita market sync...');
        $io->info('This may take a few minutes...');

        $result = $this->jitaMarketService->syncJitaMarket();

        if (!$result['success']) {
            $io->error('Sync failed: ' . ($result['error'] ?? 'Unknown error'));
            return Command::FAILURE;
        }

        $io->success('Jita market sync completed!');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Type count', $result['typeCount']],
                ['Duration', $result['duration'] . 's'],
            ]
        );

        return Command::SUCCESS;
    }
}
