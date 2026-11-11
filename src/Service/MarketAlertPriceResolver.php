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

        $structureId = $user->getPreferredMarketStructureId();
        $structureAlerts = array_filter(
            $alerts,
            static fn (MarketPriceAlert $alert): bool => in_array($alert->getPriceSource(), [AlertPriceSource::StructureSell, AlertPriceSource::StructureBuy], true),
        );
        $structureSellPrices = [];
        $structureBuyPrices = [];

        if ($structureId !== null && $structureAlerts !== []) {
            $structureTypeIds = $this->distinctTypeIds($structureAlerts);
            $structureSellPrices = $this->structureMarketService->getLowestSellPrices($structureId, $structureTypeIds);
            $structureBuyPrices = $this->structureMarketService->getHighestBuyPrices($structureId, $structureTypeIds);
        }

        return array_values(array_map(
            static fn (MarketPriceAlert $alert): ?float => match ($alert->getPriceSource()) {
                AlertPriceSource::JitaSell => $jitaSellPrices[$alert->getTypeId()] ?? null,
                AlertPriceSource::JitaBuy => $jitaBuyPrices[$alert->getTypeId()] ?? null,
                AlertPriceSource::StructureSell => $structureSellPrices[$alert->getTypeId()] ?? null,
                AlertPriceSource::StructureBuy => $structureBuyPrices[$alert->getTypeId()] ?? null,
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
