<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\MiningEntryRepository;

/**
 * Calculates mining value using the "best price" strategy:
 * GREATEST(compressedEquivalentPrice, reprocessValue, structureCompressedUnitPrice/100, structureReprocessValue, rawUnitPrice)
 *
 * This matches the frontend logic in useMiningPricing.ts getBestPriceValue().
 */
class MiningBestValueCalculator
{
    public function __construct(
        private readonly MiningEntryRepository $miningEntryRepository,
        private readonly OreValueService $oreValueService,
    ) {
    }

    /**
     * Calculate the total best value for all mining entries in a date range.
     *
     * @param list<string>|null $excludeUsages
     */
    public function getTotalBestValue(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $excludeUsages = null,
    ): float {
        $quantitiesByType = $this->miningEntryRepository->getQuantitiesByType($user, $from, $to, $excludeUsages);

        if (empty($quantitiesByType)) {
            return 0.0;
        }

        $typeIds = array_keys($quantitiesByType);
        $bestPrices = $this->getBestUnitPrices($typeIds);

        $total = 0.0;
        foreach ($quantitiesByType as $typeId => $data) {
            $total += ($bestPrices[$typeId] ?? 0.0) * $data['quantity'];
        }

        return $total;
    }

    /**
     * Calculate daily best values for mining entries in a date range.
     *
     * @param list<string>|null $excludeUsages
     * @return array<string, float> date => totalBestValue
     */
    public function getDailyBestValues(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $excludeUsages = null,
    ): array {
        $byDateAndType = $this->miningEntryRepository->getQuantitiesByTypeAndDate($user, $from, $to, $excludeUsages);

        if (empty($byDateAndType)) {
            return [];
        }

        // Collect all unique type IDs across all dates
        $allTypeIds = [];
        foreach ($byDateAndType as $typeQuantities) {
            foreach (array_keys($typeQuantities) as $typeId) {
                $allTypeIds[$typeId] = true;
            }
        }

        $bestPrices = $this->getBestUnitPrices(array_keys($allTypeIds));

        $dailyValues = [];
        foreach ($byDateAndType as $date => $typeQuantities) {
            $dayTotal = 0.0;
            foreach ($typeQuantities as $typeId => $quantity) {
                $dayTotal += ($bestPrices[$typeId] ?? 0.0) * $quantity;
            }
            $dailyValues[$date] = $dayTotal;
        }

        return $dailyValues;
    }

    /**
     * Calculate totals by usage using the best-price strategy.
     *
     * @return array<string, array{totalValue: float, totalQuantity: int}>
     */
    public function getTotalsByUsageBestValue(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $byUsageAndType = $this->miningEntryRepository->getQuantitiesByTypeAndUsage($user, $from, $to);

        if (empty($byUsageAndType)) {
            return [];
        }

        // Collect all unique type IDs across all usages
        $allTypeIds = [];
        foreach ($byUsageAndType as $typeQuantities) {
            foreach (array_keys($typeQuantities) as $typeId) {
                $allTypeIds[$typeId] = true;
            }
        }

        $bestPrices = $this->getBestUnitPrices(array_keys($allTypeIds));

        $totals = [];
        foreach ($byUsageAndType as $usage => $typeQuantities) {
            $totalValue = 0.0;
            $totalQuantity = 0;
            foreach ($typeQuantities as $typeId => $quantity) {
                $totalValue += ($bestPrices[$typeId] ?? 0.0) * $quantity;
                $totalQuantity += $quantity;
            }
            $totals[$usage] = [
                'totalValue' => $totalValue,
                'totalQuantity' => $totalQuantity,
            ];
        }

        return $totals;
    }

    /**
     * Get best unit prices for the given ore types.
     *
     * @param int[] $typeIds
     * @return array<int, float> typeId => bestUnitPrice
     */
    public function getBestUnitPrices(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $orePrices = $this->oreValueService->getBatchOrePrices($typeIds);

        $bestPrices = [];
        foreach ($typeIds as $typeId) {
            $bestPrices[$typeId] = $this->computeBestUnitPrice($orePrices[$typeId] ?? []);
        }

        return $bestPrices;
    }

    /**
     * Compute the best unit price from ore price data.
     * Mirrors frontend getBestPriceValue() logic.
     *
     * @param array<string, mixed> $priceData
     */
    private function computeBestUnitPrice(array $priceData): float
    {
        $prices = [];

        if (!empty($priceData['compressedEquivalentPrice'])) {
            $prices[] = (float) $priceData['compressedEquivalentPrice'];
        }
        if (!empty($priceData['reprocessValue'])) {
            $prices[] = (float) $priceData['reprocessValue'];
        }
        if (!empty($priceData['structureCompressedUnitPrice'])) {
            $prices[] = (float) $priceData['structureCompressedUnitPrice'] / 100;
        }
        if (!empty($priceData['structureReprocessValue'])) {
            $prices[] = (float) $priceData['structureReprocessValue'];
        }

        if (empty($prices)) {
            // Fallback to raw unit price
            return (float) ($priceData['rawUnitPrice'] ?? 0.0);
        }

        return max($prices);
    }
}
