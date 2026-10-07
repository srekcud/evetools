<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncCharacterWalletTransactions;
use App\Message\SyncWalletTransactions;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fans the scheduled sync out to one async message per character, so the worker never
 * holds a long ESI loop inside a single message.
 */
#[AsMessageHandler]
final readonly class SyncWalletTransactionsHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(SyncWalletTransactions $message): void
    {
        $this->syncTracker->start('wallet');

        try {
            $characters = $this->characterRepository->findActiveWithValidTokens();
            $queued = 0;

            foreach ($characters as $character) {
                $characterId = $character->getId()?->toRfc4122();
                $token = $character->getEveToken();
                if ($characterId === null || $token === null || !$token->hasScope('esi-wallet.read_character_wallet.v1')) {
                    continue;
                }

                $this->messageBus->dispatch(new SyncCharacterWalletTransactions($characterId));
                $queued++;
            }

            $this->logger->info('Wallet transactions sync queued', [
                'charactersQueued' => $queued,
                'totalCharacters' => count($characters),
            ]);

            $this->syncTracker->complete('wallet', "{$queued}/" . count($characters) . ' chars queued');
        } catch (\Throwable $e) {
            $this->syncTracker->fail('wallet', $e->getMessage());
            throw $e;
        }
    }
}
