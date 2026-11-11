<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeOldMarketHistory;
use App\Repository\MarketPriceHistoryRepository;
use App\Repository\StructureMarketSnapshotRepository;
use App\Repository\StructureTradedVolumeRepository;
use App\Service\Admin\SyncTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurgeOldMarketHistoryHandler
{
    private const string SYNC_KEY = 'market-history-purge';

    public function __construct(
        private MarketPriceHistoryRepository $historyRepository,
        private StructureMarketSnapshotRepository $snapshotRepository,
        private StructureTradedVolumeRepository $tradedVolumeRepository,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(PurgeOldMarketHistory $message): void
    {
        $this->syncTracker->start(self::SYNC_KEY);
        $failures = [];

        $this->logger->info('Purging market history older than 365 days');

        try {
            $deleted = $this->historyRepository->purgeOlderThan(365);

            $this->logger->info('Market history purge completed', [
                'deleted' => $deleted,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Market history purge failed', [
                'error' => $e->getMessage(),
            ]);
            $failures[] = $e->getMessage();
        }

        $this->logger->info('Purging structure market snapshots older than 90 days');

        try {
            $deletedSnapshots = $this->snapshotRepository->purgeOlderThan(90);

            $this->logger->info('Structure market snapshot purge completed', [
                'deleted' => $deletedSnapshots,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Structure market snapshot purge failed', [
                'error' => $e->getMessage(),
            ]);
            $failures[] = $e->getMessage();
        }

        $this->logger->info('Purging structure traded volumes older than 90 days');

        try {
            $deletedTradedVolumes = $this->tradedVolumeRepository->purgeOlderThan(90);

            $this->logger->info('Structure traded volume purge completed', [
                'deleted' => $deletedTradedVolumes,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Structure traded volume purge failed', [
                'error' => $e->getMessage(),
            ]);
            $failures[] = $e->getMessage();
        }

        if ($failures !== []) {
            $this->syncTracker->fail(self::SYNC_KEY, implode('; ', $failures));

            return;
        }

        $this->syncTracker->complete(self::SYNC_KEY);
    }
}
