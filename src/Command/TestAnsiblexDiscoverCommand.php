<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CharacterRepository;
use App\Service\Sync\AnsiblexSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-ansiblex-discover',
    description: 'Test Ansiblex discovery via search for a character',
)]
class TestAnsiblexDiscoverCommand extends Command
{
    public function __construct(
        private readonly CharacterRepository $characterRepository,
        private readonly AnsiblexSyncService $ansiblexSyncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('character_name', InputArgument::REQUIRED, 'Character name to use for discovery');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $characterName = $input->getArgument('character_name');

        $character = $this->characterRepository->findOneBy(['name' => $characterName]);

        if (!$character) {
            $io->error("Character '{$characterName}' not found");
            return Command::FAILURE;
        }

        $io->info("Found character: {$character->getName()} (ID: {$character->getEveCharacterId()})");
        $io->info("Corporation: {$character->getCorporationName()}");
        $io->info("Alliance: {$character->getAllianceName()}");

        if (!$this->ansiblexSyncService->canSyncViaSearch($character)) {
            $io->error('Character cannot use search-based discovery (missing scopes)');
            return Command::FAILURE;
        }

        $io->info('Starting Ansiblex discovery via search...');

        try {
            $stats = $this->ansiblexSyncService->syncViaSearch($character);

            $io->success('Discovery completed!');
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Structures discovered', $stats['discovered']],
                    ['Gates added', $stats['added']],
                    ['Gates updated', $stats['updated']],
                ]
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Discovery failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
