<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MarketPriceAlert;
use App\Entity\User;
use App\Enum\AlertPriceSource;

class MarketAlertPriceResolver
{
    public function __construct(
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
    ) {
    }

    /**
     * @param array<MarketPriceAlert> $alerts
     *
     * @return list<?float>
     */
    public function currentPrices(User $user, array $alerts): array
    {
        if ($alerts === []) {
            return [];
        }

        $typeIds = $this->distinctTypeIds($alerts);
        $jitaSellPrices = $this->jitaMarketService->getPricesWithFallback($typeIds);
        $jitaBuyPrices = $this->jitaMarketService->getBuyPricesWithFallback($typeIds);

        $structureSellPrices = [];
        $structureBuyPrices = [];
        foreach ($this->typeIdsByStructure($alerts) as $structureId => $structureTypeIds) {
            $structureSellPrices[$structureId] = $this->structureMarketService->getLowestSellPrices($structureId, $structureTypeIds);
            $structureBuyPrices[$structureId] = $this->structureMarketService->getHighestBuyPrices($structureId, $structureTypeIds);
        }

        return array_values(array_map(
            static fn (MarketPriceAlert $alert): ?float => match ($alert->getPriceSource()) {
                AlertPriceSource::JitaSell => $jitaSellPrices[$alert->getTypeId()] ?? null,
                AlertPriceSource::JitaBuy => $jitaBuyPrices[$alert->getTypeId()] ?? null,
                AlertPriceSource::StructureSell => self::structurePrice($structureSellPrices, $alert),
                AlertPriceSource::StructureBuy => self::structurePrice($structureBuyPrices, $alert),
            },
            $alerts,
        ));
    }

    public function currentPrice(User $user, MarketPriceAlert $alert): ?float
    {
        return $this->currentPrices($user, [$alert])[0];
    }

    /**
     * @param array<MarketPriceAlert> $alerts
     *
     * @return array<int, list<int>>
     */
    private function typeIdsByStructure(array $alerts): array
    {
        $typeIdsByStructure = [];
        foreach ($alerts as $alert) {
            $structureId = $alert->getStructureId();
            if ($structureId === null || !in_array($alert->getPriceSource(), [AlertPriceSource::StructureSell, AlertPriceSource::StructureBuy], true)) {
                continue;
            }
            $typeIdsByStructure[$structureId][] = $alert->getTypeId();
        }

        return array_map(static fn (array $typeIds): array => array_values(array_unique($typeIds)), $typeIdsByStructure);
    }

    /**
     * @param array<int, array<int, ?float>> $pricesByStructure
     */
    private static function structurePrice(array $pricesByStructure, MarketPriceAlert $alert): ?float
    {
        $structureId = $alert->getStructureId();

        return $structureId === null ? null : ($pricesByStructure[$structureId][$alert->getTypeId()] ?? null);
    }

    /**
     * @param array<MarketPriceAlert> $alerts
     *
     * @return list<int>
     */
    private function distinctTypeIds(array $alerts): array
    {
        return array_values(array_unique(array_map(
            static fn (MarketPriceAlert $alert): int => $alert->getTypeId(),
            $alerts,
        )));
    }
}
