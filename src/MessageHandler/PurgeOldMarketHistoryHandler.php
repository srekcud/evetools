<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeOldMarketHistory;
use App\Repository\MarketPriceHistoryRepository;
use App\Repository\StructureMarketSnapshotRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurgeOldMarketHistoryHandler
{
    public function __construct(
        private MarketPriceHistoryRepository $historyRepository,
        private StructureMarketSnapshotRepository $snapshotRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeOldMarketHistory $message): void
    {
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
        }
    }
}
