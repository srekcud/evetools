<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Repository\MarketPriceHistoryRepository;

final readonly class PlanetaryMarketStatsReader
{
    private const int ENTRIES_PER_WINDOW = 30;
    private const int ENTRIES_PER_REQUEST = self::ENTRIES_PER_WINDOW * 2;
    private const int AVERAGE_PRECISION = 2;
    private const int PRICE_CHANGE_PRECISION = 1;
    private const int PERCENT = 100;

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
        $entriesByType = $this->historyRepository->findLatestEntriesByType($typeIds, $regionId, self::ENTRIES_PER_REQUEST);
        ksort($entriesByType);

        $stats = [];
        foreach ($entriesByType as $typeId => $allEntries) {
            $entries = array_slice($allEntries, -self::ENTRIES_PER_REQUEST);
            $recentEntries = array_slice($entries, -self::ENTRIES_PER_WINDOW);
            $previousEntries = array_slice($entries, 0, count($entries) - count($recentEntries));
            $recentAveragePrice = $this->mean(array_column($recentEntries, 'average'));

            $stats[] = [
                'typeId' => $typeId,
                'averageDailyVolume' => round($this->mean(array_column($recentEntries, 'volume')), self::AVERAGE_PRECISION),
                'averagePrice' => round($recentAveragePrice, self::AVERAGE_PRECISION),
                'priceChange' => $this->priceChange($recentAveragePrice, $previousEntries),
                'lastDate' => $recentEntries[count($recentEntries) - 1]['date'],
            ];
        }

        return $stats;
    }

    /**
     * @param list<array{date: string, average: float, volume: int}> $previousEntries
     */
    private function priceChange(float $recentAveragePrice, array $previousEntries): ?float
    {
        if ($previousEntries === []) {
            return null;
        }

        $previousAveragePrice = $this->mean(array_column($previousEntries, 'average'));
        if ($previousAveragePrice === 0.0) {
            return null;
        }

        return round(($recentAveragePrice - $previousAveragePrice) / $previousAveragePrice * self::PERCENT, self::PRICE_CHANGE_PRECISION);
    }

    /**
     * @param list<int|float> $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / count($values);
    }
}
