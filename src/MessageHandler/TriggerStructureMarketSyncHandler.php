<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncStructureMarket;
use App\Message\TriggerStructureMarketSync;
use App\Repository\CharacterRepository;
use App\Repository\UserRepository;
use App\Service\Admin\SyncTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TriggerStructureMarketSyncHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private UserRepository $userRepository,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
        private int $defaultMarketStructureId,
        private string $defaultMarketStructureName,
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

            // Build the list of structures to sync: default + user preferences
            $structures = $this->getStructuresToSync();

            foreach ($structures as $structureId => $structureName) {
                $this->messageBus->dispatch(
                    new SyncStructureMarket($structureId, $structureName, $characterId)
                );
                $this->logger->info('Queued structure market sync', [
                    'structureId' => $structureId,
                    'structureName' => $structureName,
                ]);
            }

            $this->syncTracker->complete('market-structure', count($structures) . ' structures queued');
        } catch (\Throwable $e) {
            $this->syncTracker->fail('market-structure', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Collect the default structure + all distinct user-preferred structures.
     *
     * @return array<int, string> structureId => structureName
     */
    private function getStructuresToSync(): array
    {
        $structures = [
            $this->defaultMarketStructureId => $this->defaultMarketStructureName,
        ];

        $userStructureIds = $this->userRepository->findDistinctPreferredMarketStructureIds();

        foreach ($userStructureIds as $structureId) {
            if (!isset($structures[$structureId])) {
                $structures[$structureId] = "Structure {$structureId}";
            }
        }

        return $structures;
    }
}
