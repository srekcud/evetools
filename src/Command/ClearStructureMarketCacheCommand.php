<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Cache\CacheItemPoolInterface;

#[AsCommand(
    name: 'app:clear-structure-market-cache',
    description: 'Clear structure market cache to free memory',
)]
class ClearStructureMarketCacheCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'structure_market.cache')]
        private readonly CacheItemPoolInterface $cache,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->info('Clearing structure market cache...');

        // Clear the entire cache pool
        $this->cache->clear();

        $io->success('Structure market cache cleared!');

        return Command::SUCCESS;
    }
}
