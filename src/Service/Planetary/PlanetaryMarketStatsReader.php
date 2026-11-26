<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Repository\MarketPriceHistoryRepository;

final readonly class PlanetaryMarketStatsReader
{
    public function __construct(
        private MarketPriceHistoryRepository $historyRepository,
    ) {
    }

    /**
     * @param list<int> $typeIds
     * @return list<array{typeId: int, averageDailyVolume: float, averagePrice: float, priceChange: float|null, lastDate: string}>
     */
    public function statsFor(array $typeIds, int $regionId): array
    {
        return [];
    }
}
