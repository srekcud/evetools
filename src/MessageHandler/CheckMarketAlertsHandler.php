<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckMarketAlerts;
use App\Service\Admin\SyncTracker;
use App\Service\MarketAlertService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckMarketAlertsHandler
{
    public function __construct(
        private MarketAlertService $marketAlertService,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(CheckMarketAlerts $message): void
    {
        $this->syncTracker->start('market-alerts');
        $this->logger->info('Checking market price alerts');

        try {
            $triggered = $this->marketAlertService->checkAlerts();

            $this->logger->info('Market alerts check completed', [
                'triggered' => $triggered,
            ]);

            $this->syncTracker->complete('market-alerts', sprintf('%d alerts triggered', $triggered));
        } catch (\Throwable $e) {
            $this->logger->error('Market alerts check failed', [
                'error' => $e->getMessage(),
            ]);
            $this->syncTracker->fail('market-alerts', $e->getMessage());
        }
    }
}
