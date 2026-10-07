<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncCharacterPlanetaryColonies;
use App\Message\SyncPlanetaryColonies;
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
final readonly class SyncPlanetaryColoniesHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(SyncPlanetaryColonies $message): void
    {
        $this->syncTracker->start('planetary');

        try {
            $characters = $this->characterRepository->findActiveWithValidTokens();
            $queued = 0;

            foreach ($characters as $character) {
                $characterId = $character->getId()?->toRfc4122();
                $token = $character->getEveToken();
                if ($characterId === null || $token === null || !$token->hasScope('esi-planets.manage_planets.v1')) {
                    continue;
                }

                $this->messageBus->dispatch(new SyncCharacterPlanetaryColonies($characterId));
                $queued++;
            }

            $this->logger->info('Planetary colonies sync queued', [
                'charactersQueued' => $queued,
                'totalCharacters' => count($characters),
            ]);

            $this->syncTracker->complete('planetary', "{$queued}/" . count($characters) . ' chars queued');
        } catch (\Throwable $e) {
            $this->syncTracker->fail('planetary', $e->getMessage());
            throw $e;
        }
    }
}
