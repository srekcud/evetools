<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\MiningEntryRepository;
use App\Service\MiningValuation\MiningProfileUnitPrices;
use App\Service\MiningValuation\ValuationSource;

class MiningBestValueCalculator
{
    public function __construct(
        private readonly MiningEntryRepository $miningEntryRepository,
        private readonly MiningProfileUnitPrices $unitPrices,
    ) {
    }

    /**
     * @param list<string>|null $excludeUsages
     * @return array{totalValue: float|null, missingPriceTypeIds: list<int>}
     */
    public function getTotalBestValue(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $excludeUsages = null,
    ): array {
        $quantitiesByType = $this->miningEntryRepository->getQuantitiesByType($user, $from, $to, $excludeUsages);

        if (empty($quantitiesByType)) {
            return ['totalValue' => 0.0, 'missingPriceTypeIds' => []];
        }

        $bestPrices = $this->getBestUnitPrices($user, array_keys($quantitiesByType));

        return $this->valueQuantities(
            array_map(static fn (array $data): int => $data['quantity'], $quantitiesByType),
            $bestPrices,
        );
    }

    /**
     * @param list<string>|null $excludeUsages
     * @return array{byDate: array<string, float|null>, missingPriceTypeIds: list<int>}
     */
    public function getDailyBestValues(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?array $excludeUsages = null,
    ): array {
        $byDateAndType = $this->miningEntryRepository->getQuantitiesByTypeAndDate($user, $from, $to, $excludeUsages);

        if (empty($byDateAndType)) {
            return ['byDate' => [], 'missingPriceTypeIds' => []];
        }

        $bestPrices = $this->getBestUnitPricesOfEverySet($user, $byDateAndType);

        $byDate = [];
        $missingPriceTypeIds = [];
        foreach ($byDateAndType as $date => $typeQuantities) {
            $dayValue = $this->valueQuantities($typeQuantities, $bestPrices);
            $byDate[$date] = $dayValue['totalValue'];
            $missingPriceTypeIds = array_merge($missingPriceTypeIds, $dayValue['missingPriceTypeIds']);
        }

        $missingPriceTypeIds = array_unique($missingPriceTypeIds);
        sort($missingPriceTypeIds);

        return ['byDate' => $byDate, 'missingPriceTypeIds' => $missingPriceTypeIds];
    }

    /**
     * @return array<string, array{totalValue: float|null, totalQuantity: int, missingPriceTypeIds: list<int>}>
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

        $bestPrices = $this->getBestUnitPricesOfEverySet($user, $byUsageAndType);

        $totals = [];
        foreach ($byUsageAndType as $usage => $typeQuantities) {
            $usageValue = $this->valueQuantities($typeQuantities, $bestPrices);
            $totals[$usage] = [
                'totalValue' => $usageValue['totalValue'],
                'totalQuantity' => array_sum($typeQuantities),
                'missingPriceTypeIds' => $usageValue['missingPriceTypeIds'],
            ];
        }

        return $totals;
    }

    /**
     * @param int[] $typeIds
     * @return array<int, float|null> typeId => bestUnitPrice, null when the type has no price
     */
    public function getBestUnitPrices(User $user, array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        return array_fill_keys($typeIds, 0.0);
    }

    public function valuationSourceOf(User $user): ValuationSource
    {
        return new ValuationSource(MiningValuationMarket::Jita, MarketSide::Sell, MiningValuationForm::Raw, null);
    }

    /**
     * @param array<string, array<int, int>> $quantitiesBySet
     * @return array<int, float|null>
     */
    private function getBestUnitPricesOfEverySet(User $user, array $quantitiesBySet): array
    {
        $typeIds = [];
        foreach ($quantitiesBySet as $typeQuantities) {
            foreach (array_keys($typeQuantities) as $typeId) {
                $typeIds[$typeId] = true;
            }
        }

        return $this->getBestUnitPrices($user, array_keys($typeIds));
    }

    /**
     * @param array<int, int> $typeQuantities typeId => quantity
     * @param array<int, float|null> $bestPrices
     * @return array{totalValue: float|null, missingPriceTypeIds: list<int>}
     */
    private function valueQuantities(array $typeQuantities, array $bestPrices): array
    {
        $totalValue = 0.0;
        $missingPriceTypeIds = [];
        foreach ($typeQuantities as $typeId => $quantity) {
            if ($quantity === 0) {
                continue;
            }
            $bestPrice = $bestPrices[$typeId];
            if ($bestPrice === null) {
                $missingPriceTypeIds[] = $typeId;
                continue;
            }
            $totalValue += $bestPrice * $quantity;
        }
        sort($missingPriceTypeIds);

        return [
            'totalValue' => $missingPriceTypeIds === [] ? $totalValue : null,
            'missingPriceTypeIds' => $missingPriceTypeIds,
        ];
    }
}
