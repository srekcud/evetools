<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MarketPriceAlert;
use App\Repository\MarketPriceAlertRepository;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class MarketAlertService
{
    public function __construct(
        private readonly MarketPriceAlertRepository $alertRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    /**
     * Check all active alerts against current prices.
     * Returns the number of alerts triggered.
     */
    public function checkAlerts(): int
    {
        $alerts = $this->alertRepository->findActiveAlerts();

        if (empty($alerts)) {
            return 0;
        }

        // Collect unique type IDs
        $typeIds = array_values(array_unique(array_map(
            static fn (MarketPriceAlert $a) => $a->getTypeId(),
            $alerts,
        )));

        // Batch-load prices
        $sellPrices = $this->jitaMarketService->getPrices($typeIds);
        $buyPrices = $this->jitaMarketService->getBuyPrices($typeIds);

        $triggered = 0;

        foreach ($alerts as $alert) {
            $currentPrice = $this->getCurrentPrice($alert, $sellPrices, $buyPrices);

            if ($currentPrice === null) {
                continue;
            }

            if ($this->isTriggered($alert, $currentPrice)) {
                $alert->trigger();
                $triggered++;

                $this->logger->info('Market alert triggered', [
                    'alertId' => $alert->getId()?->toRfc4122(),
                    'typeId' => $alert->getTypeId(),
                    'typeName' => $alert->getTypeName(),
                    'direction' => $alert->getDirection(),
                    'threshold' => $alert->getThreshold(),
                    'currentPrice' => $currentPrice,
                ]);

                $userId = $alert->getUser()->getId()?->toRfc4122();
                if ($userId !== null) {
                    $this->mercurePublisher->publishAlert($userId, 'market-price', [
                        'alertId' => $alert->getId()?->toRfc4122(),
                        'typeId' => $alert->getTypeId(),
                        'typeName' => $alert->getTypeName(),
                        'direction' => $alert->getDirection(),
                        'threshold' => $alert->getThreshold(),
                        'currentPrice' => $currentPrice,
                        'priceSource' => $alert->getPriceSource(),
                    ]);
                }
            }
        }

        if ($triggered > 0) {
            $this->em->flush();
        }

        return $triggered;
    }

    /**
     * Get the current price for an alert based on its price source.
     *
     * @param array<int, float|null> $sellPrices
     * @param array<int, float|null> $buyPrices
     */
    private function getCurrentPrice(MarketPriceAlert $alert, array $sellPrices, array $buyPrices): ?float
    {
        return match ($alert->getPriceSource()) {
            MarketPriceAlert::SOURCE_JITA_SELL => $sellPrices[$alert->getTypeId()] ?? null,
            MarketPriceAlert::SOURCE_JITA_BUY => $buyPrices[$alert->getTypeId()] ?? null,
            default => null,
        };
    }

    private function isTriggered(MarketPriceAlert $alert, float $currentPrice): bool
    {
        return match ($alert->getDirection()) {
            MarketPriceAlert::DIRECTION_ABOVE => $currentPrice >= $alert->getThreshold(),
            MarketPriceAlert::DIRECTION_BELOW => $currentPrice <= $alert->getThreshold(),
            default => false,
        };
    }
}
