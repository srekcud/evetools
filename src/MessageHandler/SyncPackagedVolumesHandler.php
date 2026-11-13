<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncPackagedVolumes;
use App\Service\Admin\SyncTracker;
use App\Service\Assets\PackagedVolumeSyncService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncPackagedVolumesHandler
{
    private const string SYNC_TYPE = 'packaged-volumes';

    public function __construct(
        private PackagedVolumeSyncService $syncService,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(SyncPackagedVolumes $message): void
    {
        $this->syncTracker->start(self::SYNC_TYPE);

        try {
            $result = $this->syncService->sync();
        } catch (\Throwable $e) {
            $this->syncTracker->fail(self::SYNC_TYPE, $e->getMessage());
            throw $e;
        }

        $summary = sprintf('%d types synced; %d failed', $result->synced, $result->failed);

        if ($result->failed > 0 || $result->aborted) {
            $this->syncTracker->fail(self::SYNC_TYPE, $summary);

            return;
        }

        $this->syncTracker->complete(self::SYNC_TYPE, $summary);
    }
}
