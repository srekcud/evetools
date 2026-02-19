<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncCostIndices;
use App\Service\Admin\SyncTracker;
use App\Service\Industry\EsiCostIndexService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncCostIndicesHandler
{
    public function __construct(
        private EsiCostIndexService $costIndexService,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(SyncCostIndices $message): void
    {
        $this->syncTracker->start('cost-indices');
        $this->logger->info('Starting ESI system cost indices sync');

        try {
            $count = $this->costIndexService->syncCostIndices();

            $this->logger->info('ESI system cost indices sync completed', [
                'count' => $count,
            ]);

            $this->syncTracker->complete('cost-indices', $count . ' systems');
        } catch (\Throwable $e) {
            $this->logger->error('ESI system cost indices sync failed', [
                'error' => $e->getMessage(),
            ]);
            $this->syncTracker->fail('cost-indices', $e->getMessage());
        }
    }
}
