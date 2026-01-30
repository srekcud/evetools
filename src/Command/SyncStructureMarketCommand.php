<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CharacterRepository;
use App\Service\StructureMarketService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-structure-market',
    description: 'Sync market data for a structure (e.g., C-J6MT Keepstar)',
)]
class SyncStructureMarketCommand extends Command
{
    private const DEFAULT_STRUCTURE_ID = 1049588174021; // C-J6MT Keepstar
    private const DEFAULT_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)';

    public function __construct(
        private readonly CharacterRepository $characterRepository,
        private readonly StructureMarketService $structureMarketService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('character_name', InputArgument::REQUIRED, 'Character name to use for API access')
            ->addArgument('structure_id', InputArgument::OPTIONAL, 'Structure ID', self::DEFAULT_STRUCTURE_ID);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $characterName = $input->getArgument('character_name');
        $structureId = (int) $input->getArgument('structure_id');

        $character = $this->characterRepository->findOneBy(['name' => $characterName]);
        if (!$character) {
            $io->error("Character '{$characterName}' not found");
            return Command::FAILURE;
        }

        $token = $character->getEveToken();
        if (!$token) {
            $io->error('Character has no token');
            return Command::FAILURE;
        }

        $structureName = $structureId === self::DEFAULT_STRUCTURE_ID
            ? self::DEFAULT_STRUCTURE_NAME
            : "Structure {$structureId}";

        $io->info("Syncing market data for: {$structureName}");
        $io->info("Using character: {$character->getName()}");
        $io->info('This may take a while...');

        $result = $this->structureMarketService->syncStructureMarket($structureId, $structureName, $token);

        if (!$result['success']) {
            $io->error('Sync failed: ' . ($result['error'] ?? 'Unknown error'));
            return Command::FAILURE;
        }

        $io->success('Sync completed!');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total orders', $result['totalOrders']],
                ['Sell orders', $result['sellOrders']],
                ['Unique types', $result['typeCount']],
                ['Duration', $result['duration'] . 's'],
            ]
        );
        return Command::SUCCESS;
    }
}
