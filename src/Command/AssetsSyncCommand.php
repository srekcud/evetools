<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CharacterRepository;
use App\Service\Sync\AssetsSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:assets:sync',
    description: 'Manually sync assets for all characters',
)]
class AssetsSyncCommand extends Command
{
    public function __construct(
        private readonly CharacterRepository $characterRepository,
        private readonly AssetsSyncService $assetsSyncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('character', 'c', InputOption::VALUE_OPTIONAL, 'Character name to sync');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $characterName = $input->getOption('character');

        if ($characterName) {
            $characters = $this->characterRepository->findBy(['name' => $characterName]);
        } else {
            $characters = $this->characterRepository->findAll();
        }

        if (empty($characters)) {
            $io->warning('No characters found');
            return Command::FAILURE;
        }

        foreach ($characters as $character) {
            $io->section("Syncing assets for {$character->getName()}");

            try {
                $this->assetsSyncService->syncCharacterAssets($character);
                $io->success("Synced character assets for {$character->getName()}");
            } catch (\Throwable $e) {
                $io->error("Failed to sync character assets: {$e->getMessage()}");
            }
        }

        return Command::SUCCESS;
    }
}
