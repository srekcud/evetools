<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckAlertPrices;
use App\Repository\MarketPriceAlertRepository;
use App\Service\Admin\SyncTracker;
use App\Service\JitaMarketService;
use App\Service\MarketAlertService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckAlertPricesHandler
{
    public function __construct(
        private MarketPriceAlertRepository $alertRepository,
        private JitaMarketService $jitaMarketService,
        private MarketAlertService $marketAlertService,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(CheckAlertPrices $message): void
    {
        $this->syncTracker->start('alert-prices');

        try {
            $typeIds = $this->alertRepository->getActiveAlertTypeIds();

            if (empty($typeIds)) {
                $this->logger->info('No active alerts, skipping alert price refresh');
                $this->syncTracker->complete('alert-prices', 'No active alerts');

                return;
            }

            $this->logger->info('Refreshing prices for alert types', [
                'typeCount' => count($typeIds),
            ]);

            // Fetch fresh prices from ESI for these specific types
            $result = $this->jitaMarketService->refreshPricesForTypes($typeIds);

            if (!$result['success']) {
                $this->syncTracker->fail('alert-prices', $result['error'] ?? 'Price refresh failed');

                return;
            }

            $this->logger->info('Alert prices refreshed, checking thresholds', [
                'typeCount' => $result['typeCount'],
                'duration' => $result['duration'],
            ]);

            // Now check all alerts against the freshly updated prices
            $triggered = $this->marketAlertService->checkAlerts();

            $summary = sprintf(
                '%d types refreshed in %ss, %d alerts triggered',
                $result['typeCount'],
                $result['duration'],
                $triggered,
            );

            $this->logger->info('Alert price check completed', [
                'typesRefreshed' => $result['typeCount'],
                'alertsTriggered' => $triggered,
            ]);

            $this->syncTracker->complete('alert-prices', $summary);
        } catch (\Throwable $e) {
            $this->logger->error('Alert price check failed', [
                'error' => $e->getMessage(),
            ]);
            $this->syncTracker->fail('alert-prices', $e->getMessage());
        }
    }
}
