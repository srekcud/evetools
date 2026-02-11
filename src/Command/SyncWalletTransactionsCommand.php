<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CharacterRepository;
use App\Service\Sync\WalletTransactionSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-wallet-transactions',
    description: 'Sync wallet transactions for all characters',
)]
class SyncWalletTransactionsCommand extends Command
{
    public function __construct(
        private readonly CharacterRepository $characterRepository,
        private readonly WalletTransactionSyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $characters = $this->characterRepository->findAll();
        $io->info(sprintf('Found %d characters', count($characters)));

        foreach ($characters as $character) {
            $token = $character->getEveToken();
            if ($token === null || !$token->hasScope('esi-wallet.read_character_wallet.v1')) {
                $io->comment(sprintf('Skipping %s (no wallet scope)', $character->getName()));
                continue;
            }

            $user = $character->getUser();
            if ($user === null || !$user->isAuthValid()) {
                $io->comment(sprintf('Skipping %s (invalid auth)', $character->getName()));
                continue;
            }

            $io->section(sprintf('Syncing %s...', $character->getName()));

            try {
                $this->syncService->syncCharacterTransactions($character);
                $io->success('Done');
            } catch (\Throwable $e) {
                $io->error(sprintf('Failed: %s', $e->getMessage()));
            }
        }

        return Command::SUCCESS;
    }
}
