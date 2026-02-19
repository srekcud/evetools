<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Repository\MarketPriceAlertRepository;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class MarketAlertService
{
    public function __construct(
        private readonly MarketPriceAlertRepository $alertRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
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

        // Collect unique type IDs for Jita price batch-loading
        $typeIds = array_values(array_unique(array_map(
            static fn (MarketPriceAlert $a) => $a->getTypeId(),
            $alerts,
        )));

        // Batch-load Jita prices
        $sellPrices = $this->jitaMarketService->getPricesWithFallback($typeIds);
        $buyPrices = $this->jitaMarketService->getBuyPricesWithFallback($typeIds);

        // Batch-load structure prices per user (only for users with structure alerts)
        $structurePricesByUser = $this->loadStructurePrices($alerts);

        $triggered = 0;

        foreach ($alerts as $alert) {
            $userId = $alert->getUser()->getId()?->toRfc4122() ?? '';
            $currentPrice = $this->getCurrentPrice(
                $alert,
                $sellPrices,
                $buyPrices,
                $structurePricesByUser[$userId] ?? null,
            );

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

                if ($userId !== '') {
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
     * Load structure prices grouped by user ID, only for users that have structure-based alerts.
     *
     * @param MarketPriceAlert[] $alerts
     * @return array<string, array{sell: array<int, float|null>, buy: array<int, float|null>}>
     */
    private function loadStructurePrices(array $alerts): array
    {
        // Group structure alerts by user
        /** @var array<string, array{user: User, typeIds: int[]}> $byUser */
        $byUser = [];

        foreach ($alerts as $alert) {
            if (!in_array($alert->getPriceSource(), [MarketPriceAlert::SOURCE_STRUCTURE_SELL, MarketPriceAlert::SOURCE_STRUCTURE_BUY], true)) {
                continue;
            }

            $userId = $alert->getUser()->getId()?->toRfc4122() ?? '';
            if ($userId === '') {
                continue;
            }

            if (!isset($byUser[$userId])) {
                $byUser[$userId] = ['user' => $alert->getUser(), 'typeIds' => []];
            }
            $byUser[$userId]['typeIds'][] = $alert->getTypeId();
        }

        if (empty($byUser)) {
            return [];
        }

        $result = [];

        foreach ($byUser as $userId => $data) {
            $structureId = $data['user']->getPreferredMarketStructureId();
            if ($structureId === null) {
                continue;
            }

            $uniqueTypeIds = array_values(array_unique($data['typeIds']));
            $result[$userId] = [
                'sell' => $this->structureMarketService->getLowestSellPrices($structureId, $uniqueTypeIds),
                'buy' => $this->structureMarketService->getHighestBuyPrices($structureId, $uniqueTypeIds),
            ];
        }

        return $result;
    }

    /**
     * Get the current price for an alert based on its price source.
     *
     * @param array<int, float|null> $sellPrices
     * @param array<int, float|null> $buyPrices
     * @param array{sell: array<int, float|null>, buy: array<int, float|null>}|null $structurePrices
     */
    private function getCurrentPrice(
        MarketPriceAlert $alert,
        array $sellPrices,
        array $buyPrices,
        ?array $structurePrices = null,
    ): ?float {
        return match ($alert->getPriceSource()) {
            MarketPriceAlert::SOURCE_JITA_SELL => $sellPrices[$alert->getTypeId()] ?? null,
            MarketPriceAlert::SOURCE_JITA_BUY => $buyPrices[$alert->getTypeId()] ?? null,
            MarketPriceAlert::SOURCE_STRUCTURE_SELL => $structurePrices['sell'][$alert->getTypeId()] ?? null,
            MarketPriceAlert::SOURCE_STRUCTURE_BUY => $structurePrices['buy'][$alert->getTypeId()] ?? null,
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
