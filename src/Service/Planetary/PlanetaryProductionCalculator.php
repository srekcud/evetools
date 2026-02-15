<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\JitaMarketService;

/**
 * Calculates PI production output and ISK value.
 *
 * PI Tiers:
 *   P0 = Raw materials (extractor output)
 *   P1 = Basic processed (from P0, cycle_time typically 1800s)
 *   P2 = Refined commodities (from P1, cycle_time typically 3600s)
 *   P3 = Specialized commodities (from P2, cycle_time typically 3600s)
 *   P4 = Advanced commodities (from P3+P1, cycle_time typically 21600s)
 */
class PlanetaryProductionCalculator
{
    private const SECONDS_PER_DAY = 86400;
    private const DAYS_PER_MONTH = 30;

    // PI market group IDs in EVE SDE
    private const MARKET_GROUP_P1 = 1334; // Basic Commodities
    private const MARKET_GROUP_P2 = 1335; // Refined Commodities
    private const MARKET_GROUP_P3 = 1336; // Specialized Commodities
    private const MARKET_GROUP_P4 = 1337; // Advanced Commodities

    public function __construct(
        private readonly PlanetSchematicRepository $schematicRepository,
        private readonly PlanetSchematicTypeRepository $schematicTypeRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly JitaMarketService $jitaMarketService,
    ) {
    }

    /**
     * Calculate total estimated daily ISK from all active colonies.
     *
     * @param PlanetaryColony[] $colonies
     */
    public function calculateTotalDailyIsk(array $colonies): float
    {
        $production = $this->calculateProduction($colonies);

        return $production['totalDailyIsk'];
    }

    /**
     * Calculate production breakdown by tier.
     *
     * @param PlanetaryColony[] $colonies
     * @return array{tiers: list<array<string, mixed>>, totalDailyIsk: float, totalMonthlyIsk: float}
     */
    public function calculateProduction(array $colonies): array
    {
        $schematicMap = $this->schematicTypeRepository->getSchematicMap();

        /** @var array<int, float> $consumptionByType */
        $consumptionByType = [];
        /** @var array<int, array<int, float>> $inputsByOutputType */
        $inputsByOutputType = [];
        $outputsByType = $this->aggregateOutputs($colonies, $schematicMap, $consumptionByType, $inputsByOutputType);

        if (empty($outputsByType)) {
            return [
                'tiers' => [],
                'totalDailyIsk' => 0.0,
                'totalMonthlyIsk' => 0.0,
            ];
        }

        // Resolve type names and prices (include input types for name resolution)
        $allInputTypeIds = [];
        foreach ($inputsByOutputType as $inputs) {
            foreach ($inputs as $inputTypeId => $consumed) {
                $allInputTypeIds[$inputTypeId] = true;
            }
        }
        $typeIds = array_unique(array_merge(array_keys($outputsByType), array_keys($allInputTypeIds)));
        $prices = $this->jitaMarketService->getPrices($typeIds);

        // Build tier breakdown
        $tierData = [];
        $totalDailyIsk = 0.0;

        foreach ($outputsByType as $typeId => $dailyQty) {
            $tier = $this->classifyTier($typeId);
            $typeName = $this->resolveTypeName($typeId);
            $unitPrice = $prices[$typeId] ?? null;
            $dailyIsk = $unitPrice !== null ? $dailyQty * $unitPrice : 0.0;
            $totalDailyIsk += $dailyIsk;

            if (!isset($tierData[$tier])) {
                $tierData[$tier] = [
                    'tier' => $tier,
                    'label' => $this->getTierLabel($tier),
                    'items' => [],
                    'dailyIskValue' => 0.0,
                ];
            }

            // Build supply chain inputs for this item
            $inputs = [];
            if (isset($inputsByOutputType[$typeId])) {
                foreach ($inputsByOutputType[$typeId] as $inputTypeId => $dailyConsumed) {
                    $inputs[] = [
                        'typeId' => $inputTypeId,
                        'typeName' => $this->resolveTypeName($inputTypeId),
                        'dailyConsumed' => round($dailyConsumed, 2),
                        'dailyProduced' => round($outputsByType[$inputTypeId] ?? 0.0, 2),
                        'delta' => round(
                            ($outputsByType[$inputTypeId] ?? 0.0) - ($consumptionByType[$inputTypeId] ?? 0.0),
                            2,
                        ),
                    ];
                }
            }

            $tierData[$tier]['items'][] = [
                'typeId' => $typeId,
                'typeName' => $typeName,
                'dailyQuantity' => round($dailyQty, 2),
                'unitPrice' => $unitPrice,
                'dailyIskValue' => round($dailyIsk, 2),
                'inputs' => $inputs,
            ];
            $tierData[$tier]['dailyIskValue'] += $dailyIsk;
        }

        // Sort tiers and round values
        ksort($tierData);
        foreach ($tierData as &$tier) {
            $tier['dailyIskValue'] = round($tier['dailyIskValue'], 2);
            usort($tier['items'], fn ($a, $b) => $b['dailyIskValue'] <=> $a['dailyIskValue']);
        }
        unset($tier);

        return [
            'tiers' => array_values($tierData),
            'totalDailyIsk' => round($totalDailyIsk, 2),
            'totalMonthlyIsk' => round($totalDailyIsk * self::DAYS_PER_MONTH, 2),
        ];
    }

    /**
     * Aggregate daily outputs from all extractors and factories across colonies.
     * Also computes global consumption per input type and per-output-type input breakdown.
     *
     * @param PlanetaryColony[]                                                                          $colonies
     * @param array<int, array{inputs: array<int, int>, output: array{typeId: int, quantity: int}|null}> $schematicMap
     * @param array<int, float>                                                                          &$consumptionByType  Global daily consumption per input typeId (populated by reference)
     * @param array<int, array<int, float>>                                                              &$inputsByOutputType Per output typeId, daily consumption of each input typeId (populated by reference)
     * @return array<int, float> typeId => daily quantity
     */
    private function aggregateOutputs(
        array $colonies,
        array $schematicMap,
        array &$consumptionByType,
        array &$inputsByOutputType,
    ): array {
        $outputsByType = [];
        $now = new \DateTimeImmutable();

        foreach ($colonies as $colony) {
            foreach ($colony->getPins() as $pin) {
                $dailyOutput = $this->calculatePinDailyOutput($pin, $schematicMap, $now);
                if ($dailyOutput === null) {
                    continue;
                }

                $typeId = $dailyOutput['typeId'];
                $outputsByType[$typeId] = ($outputsByType[$typeId] ?? 0.0) + $dailyOutput['quantity'];

                // Track consumption for factory pins
                if ($pin->isFactory()) {
                    $schematicId = $pin->getSchematicId();
                    if ($schematicId === null) {
                        continue;
                    }
                    $schematicInputs = $schematicMap[$schematicId]['inputs'] ?? [];
                    $schematic = $this->schematicRepository->findBySchematicId($schematicId);

                    if ($schematic !== null && $schematic->getCycleTime() > 0) {
                        $cyclesPerDay = self::SECONDS_PER_DAY / $schematic->getCycleTime();

                        foreach ($schematicInputs as $inputTypeId => $inputQty) {
                            $dailyConsumed = $inputQty * $cyclesPerDay;

                            // Global consumption tracking
                            $consumptionByType[$inputTypeId] = ($consumptionByType[$inputTypeId] ?? 0.0) + $dailyConsumed;

                            // Per-output-type input tracking
                            if (!isset($inputsByOutputType[$typeId])) {
                                $inputsByOutputType[$typeId] = [];
                            }
                            $inputsByOutputType[$typeId][$inputTypeId] = ($inputsByOutputType[$typeId][$inputTypeId] ?? 0.0) + $dailyConsumed;
                        }
                    }
                }
            }
        }

        return $outputsByType;
    }

    /**
     * Calculate the daily output of a single pin.
     *
     * @param array<int, array<string, mixed>> $schematicMap
     * @return array{typeId: int, quantity: float}|null
     */
    private function calculatePinDailyOutput(PlanetaryPin $pin, array $schematicMap, \DateTimeImmutable $now): ?array
    {
        // Extractors: use cycle time and qty per cycle (only if still active)
        if ($pin->isExtractor()) {
            $productTypeId = $pin->getExtractorProductTypeId();
            $cycleTime = $pin->getExtractorCycleTime();
            $qtyPerCycle = $pin->getExtractorQtyPerCycle();

            if ($productTypeId === null || $cycleTime === null || $cycleTime <= 0 || $qtyPerCycle === null) {
                return null;
            }

            // Only count active extractors
            $expiry = $pin->getExpiryTime();
            if ($expiry !== null && $expiry < $now) {
                return null;
            }

            $dailyQty = $qtyPerCycle * (self::SECONDS_PER_DAY / $cycleTime);

            return ['typeId' => $productTypeId, 'quantity' => $dailyQty];
        }

        // Factories: use schematic cycle time
        if ($pin->isFactory()) {
            $schematicId = $pin->getSchematicId();
            if ($schematicId === null || !isset($schematicMap[$schematicId])) {
                return null;
            }

            $schematic = $this->schematicRepository->findBySchematicId($schematicId);
            if ($schematic === null) {
                return null;
            }

            $output = $schematicMap[$schematicId]['output'] ?? null;
            if ($output === null) {
                return null;
            }

            $cycleTime = $schematic->getCycleTime();
            if ($cycleTime <= 0) {
                return null;
            }

            $dailyQty = $output['quantity'] * (self::SECONDS_PER_DAY / $cycleTime);

            return ['typeId' => $output['typeId'], 'quantity' => $dailyQty];
        }

        return null;
    }

    /**
     * Classify a type into a PI tier based on its market group hierarchy.
     * P0 = raw materials (no market group match), P1-P4 by market group.
     */
    private function classifyTier(int $typeId): string
    {
        $type = $this->invTypeRepository->find($typeId);
        if ($type === null) {
            return 'P0';
        }

        $marketGroup = $type->getMarketGroup();
        if ($marketGroup === null) {
            return 'P0';
        }

        // Walk up the market group hierarchy to find the PI tier
        $current = $marketGroup;
        $depth = 0;
        while ($current !== null && $depth < 10) {
            $mgId = $current->getMarketGroupId();

            if ($mgId === self::MARKET_GROUP_P1) {
                return 'P1';
            }
            if ($mgId === self::MARKET_GROUP_P2) {
                return 'P2';
            }
            if ($mgId === self::MARKET_GROUP_P3) {
                return 'P3';
            }
            if ($mgId === self::MARKET_GROUP_P4) {
                return 'P4';
            }

            $current = $current->getParentGroup();
            $depth++;
        }

        return 'P0';
    }

    private function getTierLabel(string $tier): string
    {
        return match ($tier) {
            'P0' => 'Raw Materials',
            'P1' => 'Basic Commodities',
            'P2' => 'Refined Commodities',
            'P3' => 'Specialized Commodities',
            'P4' => 'Advanced Commodities',
            default => 'Unknown',
        };
    }

    private function resolveTypeName(int $typeId): string
    {
        $type = $this->invTypeRepository->find($typeId);

        return $type?->getTypeName() ?? "Type #{$typeId}";
    }
}
