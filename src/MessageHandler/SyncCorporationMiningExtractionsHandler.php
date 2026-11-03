<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncCorporationMiningExtractions;
use App\Service\Sync\CorporationMiningExtractionSyncService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncCorporationMiningExtractionsHandler
{
    public function __construct(
        private CorporationMiningExtractionSyncService $syncService,
    ) {
    }

    public function __invoke(SyncCorporationMiningExtractions $message): void
    {
        $this->syncService->sync($message->corporationId);
    }
}
