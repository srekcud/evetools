<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncWalletTransactions;
use App\Repository\CharacterRepository;
use App\Service\Sync\WalletTransactionSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncWalletTransactionsHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private WalletTransactionSyncService $walletTransactionSyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncWalletTransactions $message): void
    {
        $characters = $this->characterRepository->findAll();

        foreach ($characters as $character) {
            $token = $character->getEveToken();
            if ($token === null || !$token->hasScope('esi-wallet.read_character_wallet.v1')) {
                continue;
            }

            $user = $character->getUser();
            if ($user === null || !$user->isAuthValid()) {
                continue;
            }

            try {
                $this->walletTransactionSyncService->syncCharacterTransactions($character);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to sync wallet transactions', [
                    'characterName' => $character->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
