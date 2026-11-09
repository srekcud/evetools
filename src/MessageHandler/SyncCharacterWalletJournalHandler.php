<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncCharacterWalletTransactions;
use App\Repository\CharacterRepository;
use App\Service\Sync\WalletJournalSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SyncCharacterWalletJournalHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private WalletJournalSyncService $walletJournalSyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncCharacterWalletTransactions $message): void
    {
        $character = $this->characterRepository->find(Uuid::fromString($message->characterId));

        if ($character === null) {
            $this->logger->warning('Character not found for wallet journal sync', ['characterId' => $message->characterId]);

            return;
        }

        $this->walletJournalSyncService->syncCharacterJournal($character);
    }
}
