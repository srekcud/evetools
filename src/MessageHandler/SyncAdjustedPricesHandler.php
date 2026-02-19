<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncAdjustedPrices;
use App\Service\Admin\SyncTracker;
use App\Service\Industry\EsiCostIndexService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncAdjustedPricesHandler
{
    public function __construct(
        private EsiCostIndexService $costIndexService,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(SyncAdjustedPrices $message): void
    {
        $this->syncTracker->start('adjusted-prices');
        $this->logger->info('Starting ESI adjusted prices sync');

        try {
            $count = $this->costIndexService->syncAdjustedPrices();

            $this->logger->info('ESI adjusted prices sync completed', [
                'count' => $count,
            ]);

            $this->syncTracker->complete('adjusted-prices', $count . ' types');
        } catch (\Throwable $e) {
            $this->logger->error('ESI adjusted prices sync failed', [
                'error' => $e->getMessage(),
            ]);
            $this->syncTracker->fail('adjusted-prices', $e->getMessage());
        }
    }
}
