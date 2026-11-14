<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Character;
use App\Message\SyncCharacterWalletTransactions;
use App\Message\SyncWalletTransactions;
use App\Repository\CharacterRepository;
use App\Repository\CharacterWalletReadingRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Sync\WalletReadingDueness;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
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
        private CharacterWalletReadingRepository $readingRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SyncWalletTransactions $message): void
    {
        $this->syncTracker->start('wallet');

        try {
            $characters = $this->characterRepository->findActiveWithValidTokens();
            $walletCharacters = WalletReadingDueness::walletCharactersById($characters);
            $targets = $message->dueOnly ? $this->dueCharacters($walletCharacters) : $walletCharacters;

            foreach (array_keys($targets) as $characterId) {
                $this->messageBus->dispatch(new SyncCharacterWalletTransactions($characterId));
            }

            $queued = count($targets);

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

    /**
     * @param array<string, Character> $walletCharacters
     * @return array<string, Character>
     */
    private function dueCharacters(array $walletCharacters): array
    {
        $readings = $this->readingRepository->findByCharacters(array_values($walletCharacters));

        return array_diff_key($walletCharacters, WalletReadingDueness::notDueAmong($walletCharacters, $readings, $this->clock->now()));
    }
}
