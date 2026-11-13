<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Character;
use App\Message\SyncCharacterWalletTransactions;
use App\Message\SyncWalletTransactions;
use App\Repository\CharacterRepository;
use App\Repository\CharacterWalletReadingRepository;
use App\Service\Admin\SyncTracker;
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
            $walletCharacters = $this->walletCharactersById($characters);
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
     * @param array<Character> $characters
     * @return array<string, Character>
     */
    private function walletCharactersById(array $characters): array
    {
        $walletCharacters = [];
        foreach ($characters as $character) {
            $characterId = $character->getId()?->toRfc4122();
            if ($characterId === null || $character->getEveToken()?->hasScope('esi-wallet.read_character_wallet.v1') !== true) {
                continue;
            }

            $walletCharacters[$characterId] = $character;
        }

        return $walletCharacters;
    }

    /**
     * @param array<string, Character> $walletCharacters
     * @return array<string, Character>
     */
    private function dueCharacters(array $walletCharacters): array
    {
        $now = $this->clock->now();
        $notDue = [];
        foreach ($this->readingRepository->findByCharacters(array_values($walletCharacters)) as $reading) {
            $characterId = $reading->getCharacter()->getId()?->toRfc4122();
            if ($characterId !== null && $reading->getNextReadAt() > $now) {
                $notDue[$characterId] = true;
            }
        }

        return array_diff_key($walletCharacters, $notDue);
    }
}
