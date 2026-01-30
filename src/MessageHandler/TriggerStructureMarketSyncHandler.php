<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncStructureMarket;
use App\Message\TriggerStructureMarketSync;
use App\Repository\CharacterRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TriggerStructureMarketSyncHandler
{
    // Structures to sync market data for
    private const STRUCTURES = [
        1049588174021 => 'C-J6MT - 1st Taj Mahgoon (Keepstar)',
    ];

    public function __construct(
        private CharacterRepository $characterRepository,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TriggerStructureMarketSync $message): void
    {
        $this->logger->info('Triggering structure market sync');

        // Find a character with valid token that can access the structures
        // For now, use any character with a valid token
        $characters = $this->characterRepository->findWithValidTokens();

        if (empty($characters)) {
            $this->logger->warning('No characters with valid tokens for structure market sync');
            return;
        }

        // Use the first available character
        $character = $characters[0];
        $characterId = $character->getId()->toRfc4122();

        foreach (self::STRUCTURES as $structureId => $structureName) {
            $this->messageBus->dispatch(
                new SyncStructureMarket($structureId, $structureName, $characterId)
            );
            $this->logger->info('Queued structure market sync', [
                'structureId' => $structureId,
                'structureName' => $structureName,
            ]);
        }
    }
}
