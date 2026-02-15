<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncStructureMarket;
use App\Message\TriggerStructureMarketSync;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
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
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(TriggerStructureMarketSync $message): void
    {
        $this->syncTracker->start('market-structure');
        $this->logger->info('Triggering structure market sync');

        try {
            $characters = $this->characterRepository->findWithValidTokens();

            if (empty($characters)) {
                $this->logger->warning('No characters with valid tokens for structure market sync');
                $this->syncTracker->complete('market-structure', 'No valid tokens');
                return;
            }

            $character = $characters[0];
            $charUuid = $character->getId();
            if ($charUuid === null) {
                $this->logger->warning('Character has no ID for structure market sync');
                $this->syncTracker->complete('market-structure', 'Character has no ID');
                return;
            }
            $characterId = $charUuid->toRfc4122();

            foreach (self::STRUCTURES as $structureId => $structureName) {
                $this->messageBus->dispatch(
                    new SyncStructureMarket($structureId, $structureName, $characterId)
                );
                $this->logger->info('Queued structure market sync', [
                    'structureId' => $structureId,
                    'structureName' => $structureName,
                ]);
            }

            $this->syncTracker->complete('market-structure', count(self::STRUCTURES) . ' structures queued');
        } catch (\Throwable $e) {
            $this->syncTracker->fail('market-structure', $e->getMessage());
            throw $e;
        }
    }
}
