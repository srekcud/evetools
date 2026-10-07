<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncCharacterPlanetaryColonies;
use App\Repository\CharacterRepository;
use App\Service\Sync\PlanetarySyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SyncCharacterPlanetaryColoniesHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private PlanetarySyncService $planetarySyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncCharacterPlanetaryColonies $message): void
    {
        $character = $this->characterRepository->find(Uuid::fromString($message->characterId));

        if ($character === null) {
            $this->logger->warning('Character not found for planetary colonies sync', ['characterId' => $message->characterId]);

            return;
        }

        $this->planetarySyncService->syncCharacterColonies($character);
    }
}
