<?php

declare(strict_types=1);

namespace App\Service\Industry\SellOrders;

use App\Constant\EveConstants;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;

class SellOrderBooks
{
    public function __construct(
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
    ) {
    }

    /**
     * @return list<array{price: float, volume: int}>|null null when the market of the location is not followed
     */
    public function of(int $locationId, int $typeId): ?array
    {
        if ($locationId === EveConstants::JITA_STATION_ID) {
            return $this->jitaMarketService->getSellOrders($typeId);
        }

        if ($this->structureMarketService->hasCachedData($locationId)) {
            return $this->structureMarketService->getSellOrders($locationId, $typeId);
        }

        return null;
    }

    /**
     * @param list<int> $typeIds
     */
    public function refreshJita(array $typeIds): void
    {
        if ($typeIds === []) {
            return;
        }

        $refresh = $this->jitaMarketService->refreshPricesForTypes($typeIds);
        if (!$refresh['success']) {
            throw new \RuntimeException(\sprintf('Jita sell books could not be refreshed: %s', $refresh['error'] ?? 'unknown error'));
        }
    }
}
