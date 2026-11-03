<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Character;
use App\Message\SyncCorporationMiningExtractions;
use App\Message\TriggerCorporationMiningExtractionsSync;
use App\Repository\CharacterRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Sync\CorporationMiningExtractionSyncService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TriggerCorporationMiningExtractionsSyncHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private MessageBusInterface $messageBus,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(TriggerCorporationMiningExtractionsSync $message): void
    {
        $this->syncTracker->start(CorporationMiningExtractionSyncService::SYNC_TYPE);

        try {
            $corporationIds = array_unique(array_map(
                static fn (Character $character): int => $character->getCorporationId(),
                $this->characterRepository->findAll(),
            ));

            foreach ($corporationIds as $corporationId) {
                $this->messageBus->dispatch(new SyncCorporationMiningExtractions($corporationId));
            }

            $this->syncTracker->complete(CorporationMiningExtractionSyncService::SYNC_TYPE, count($corporationIds) . ' corporations dispatched');
        } catch (\Throwable $e) {
            $this->syncTracker->fail(CorporationMiningExtractionSyncService::SYNC_TYPE, $e->getMessage());
            throw $e;
        }
    }
}
