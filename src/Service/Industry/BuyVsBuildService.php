<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;

/**
 * Analyzes whether intermediate components should be built or bought
 * for a given production item, based on Jita prices and production costs.
 */
class BuyVsBuildService
{
    private const string VERDICT_BUILD = 'build';
    private const string VERDICT_BUY = 'buy';
    private const string VERDICT_UNKNOWN = 'unknown';

    public function __construct(
        private readonly IndustryTreeService $treeService,
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
        private readonly EsiCostIndexService $esiCostIndexService,
        private readonly InventionService $inventionService,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly IndustryActivityMaterialRepository $materialRepository,
    ) {
    }

    /**
     * Analyze buy-vs-build for all intermediate components of a product.
     *
     * @return array{
     *     typeId: int,
     *     typeName: string|null,
     *     isT2: bool,
     *     runs: int,
     *     totalProductionCost: float|null,
     *     sellPrice: float|null,
     *     marginPercent: float|null,
     *     components: list<array{typeId: int, typeName: string|null, quantity: int, stage: string, buildCost: float|null, buildMaterials: list<array{typeId: int, typeName: string|null, quantity: int, unitPrice: float|null, totalPrice: float|null}>, buildJobInstallCost: float|null, buyCostJita: float|null, buyCostStructure: float|null, verdict: 'build'|'buy'|'unknown', savings: float|null, savingsPercent: float|null, meUsed: int, runs: int}>,
     *     buildAllCost: float|null,
     *     buyAllCost: float|null,
     *     optimalMixCost: float|null,
     *     buildTypeIds: list<int>,
     *     buyTypeIds: list<int>,
     *     missingPriceTypeIds: list<int>,
     *     unknownInstallCostReasons: list<string>,
     * }
     */
    public function analyze(
        int $typeId,
        int $runs,
        int $meLevel,
        int $solarSystemId,
        ?int $sellStructureId,
        float $brokerFeeRate,
        float $salesTaxRate,
        ?User $user,
        ?int $reactionSolarSystemId = null,
    ): array {
        $typeName = $this->typeNameResolver->findName($typeId);
        $isT2 = $this->inventionService->isT2($typeId);

        // Build the full production tree WITHOUT blacklist to decompose everything
        $tree = $this->treeService->buildProductionTree($typeId, $runs, $meLevel, [], $user);

        $outputPerRun = (int) $tree['outputPerRun'];
        $outputQuantity = $runs * $outputPerRun;

        // Collect all buildable intermediate components from the tree
        $components = [];
        $reactionSolarSystemId ??= $solarSystemId;
        $this->collectBuildableComponents($tree, $components, $solarSystemId, $reactionSolarSystemId);

        $leafMaterials = [];
        $this->collectLeafMaterialsFromTree($tree, $leafMaterials);

        // Collect all type IDs for batch pricing
        $allTypeIds = [$typeId, ...array_keys($leafMaterials)];
        foreach ($components as $comp) {
            $allTypeIds[] = $comp['typeId'];
            foreach ($comp['materialTypeIds'] as $matId) {
                $allTypeIds[] = $matId;
            }
        }
        $allTypeIds = array_unique($allTypeIds);

        // Batch Jita prices
        $jitaPrices = $this->jitaMarketService->getPricesWithFallback($allTypeIds);

        // Sell price for the final product
        $structureSellPrice = $sellStructureId !== null
            ? $this->structureMarketService->getLowestSellPrice($sellStructureId, $typeId)
            : null;
        $sellPrice = $structureSellPrice ?? ($jitaPrices[$typeId] ?? null);

        // Analyze each buildable component
        $analyzedComponents = [];
        $buildAllCost = 0.0;
        $buyAllCost = 0.0;
        $optimalMixCost = 0.0;
        $buildTypeIds = [];
        $buyTypeIds = [];
        $missingPriceTypeIds = [];
        $unknownInstallCostReasons = [];

        // Batch structure prices for all component type IDs
        $componentTypeIds = array_map(static fn (array $c) => $c['typeId'], $components);
        $structurePrices = $sellStructureId !== null && $componentTypeIds !== []
            ? $this->structureMarketService->getLowestSellPrices($sellStructureId, $componentTypeIds)
            : [];

        foreach ($components as $comp) {
            $compTypeId = $comp['typeId'];
            $quantity = $comp['quantity'];

            // Buy cost Jita = Jita price * quantity
            $unitBuyPriceJita = $jitaPrices[$compTypeId] ?? null;
            $buyCostJita = $unitBuyPriceJita !== null ? $unitBuyPriceJita * $quantity : null;

            // Buy cost Structure
            $unitBuyPriceStructure = $structurePrices[$compTypeId] ?? null;
            $buyCostStructure = $unitBuyPriceStructure !== null ? $unitBuyPriceStructure * $quantity : null;

            $buyCost = $buyCostStructure ?? $buyCostJita;
            if ($buyCost === null) {
                $missingPriceTypeIds[] = $compTypeId;
            }

            $buildMaterials = [];
            $matCost = 0.0;
            $isBuildCostUnknown = false;
            foreach ($comp['materials'] as $mat) {
                $matTypeId = $mat['materialTypeId'];
                $matQty = $mat['adjustedQuantity'];
                $matUnitPrice = $jitaPrices[$matTypeId] ?? null;
                $matTotalPrice = null;
                if ($matUnitPrice === null) {
                    $isBuildCostUnknown = true;
                    $missingPriceTypeIds[] = $matTypeId;
                } else {
                    $matTotalPrice = $matUnitPrice * $matQty;
                    $matCost += $matTotalPrice;
                }

                $buildMaterials[] = [
                    'typeId' => $matTypeId,
                    'typeName' => $this->typeNameResolver->findName($matTypeId),
                    'quantity' => $matQty,
                    'unitPrice' => $matUnitPrice !== null ? round($matUnitPrice, 2) : null,
                    'totalPrice' => $matTotalPrice !== null ? round($matTotalPrice, 2) : null,
                ];
            }

            $jobInstallCost = $comp['jobInstallCost']['cost'];
            if ($comp['jobInstallCost']['unknownReason'] !== null) {
                $unknownInstallCostReasons[] = $comp['jobInstallCost']['unknownReason'];
            }
            $buildCost = $isBuildCostUnknown || $jobInstallCost === null ? null : $matCost + $jobInstallCost;

            $savings = null;
            $savingsPercent = null;
            if ($buildCost !== null && $buyCost !== null) {
                $savings = $buyCost - $buildCost;
                $maxCost = max($buildCost, $buyCost);
                $savingsPercent = $maxCost > 0 ? round(abs($savings) / $maxCost * 100, 2) : 0.0;
            }

            $verdict = $this->verdictOf($buildCost, $buyCost);

            $buildAllCost = $this->sumOfKnownCosts($buildAllCost, $buildCost);
            $buyAllCost = $this->sumOfKnownCosts($buyAllCost, $buyCost);

            if ($verdict === self::VERDICT_BUILD) {
                $optimalMixCost = $this->sumOfKnownCosts($optimalMixCost, $buildCost);
                $buildTypeIds[] = $compTypeId;
            } elseif ($verdict === self::VERDICT_BUY) {
                $optimalMixCost = $this->sumOfKnownCosts($optimalMixCost, $buyCost);
                $buyTypeIds[] = $compTypeId;
            } else {
                $optimalMixCost = null;
            }

            $analyzedComponents[] = [
                'typeId' => $compTypeId,
                'typeName' => $this->typeNameResolver->findName($compTypeId),
                'quantity' => $quantity,
                'stage' => $comp['stage'],
                'buildCost' => $buildCost !== null ? round($buildCost, 2) : null,
                'buildMaterials' => $buildMaterials,
                'buildJobInstallCost' => $jobInstallCost !== null ? round($jobInstallCost, 2) : null,
                'buyCostJita' => $buyCostJita !== null ? round($buyCostJita, 2) : null,
                'buyCostStructure' => $buyCostStructure !== null ? round($buyCostStructure, 2) : null,
                'verdict' => $verdict,
                'savings' => $savings !== null ? round($savings, 2) : null,
                'savingsPercent' => $savingsPercent,
                'meUsed' => $comp['meLevel'],
                'runs' => $comp['runs'],
            ];
        }

        usort($analyzedComponents, static fn (array $a, array $b): int => match (true) {
            $a['savings'] !== null && $b['savings'] !== null => $b['savings'] <=> $a['savings'],
            $a['savings'] === null && $b['savings'] === null => $a['typeId'] <=> $b['typeId'],
            default => $a['savings'] === null ? 1 : -1,
        });

        $leafMaterialCost = 0.0;
        foreach ($leafMaterials as $matTypeId => $matQty) {
            $leafUnitPrice = $jitaPrices[$matTypeId] ?? null;
            if ($leafUnitPrice === null) {
                $missingPriceTypeIds[] = $matTypeId;
            }
            $leafMaterialCost = $this->sumOfKnownCosts($leafMaterialCost, $leafUnitPrice !== null ? $leafUnitPrice * $matQty : null);
        }

        $finalJobInstallCost = $this->estimateJobInstallCost(
            (int) $tree['blueprintTypeId'],
            (string) $tree['activityType'],
            $runs,
            $solarSystemId,
            $reactionSolarSystemId,
        );
        if ($finalJobInstallCost['unknownReason'] !== null) {
            $unknownInstallCostReasons[] = $finalJobInstallCost['unknownReason'];
        }

        $totalProductionCost = $this->sumOfKnownCosts($this->sumOfKnownCosts($optimalMixCost, $leafMaterialCost), $finalJobInstallCost['cost']);

        $marginPercent = null;
        if ($sellPrice !== null && $sellPrice > 0 && $totalProductionCost !== null && $totalProductionCost > 0) {
            $grossRevenue = $sellPrice * $outputQuantity;
            $fees = $grossRevenue * ($brokerFeeRate + $salesTaxRate);
            $netRevenue = $grossRevenue - $fees;
            $profit = $netRevenue - $totalProductionCost;
            $marginPercent = round(($profit / $totalProductionCost) * 100, 2);
        }

        $missingPriceTypeIds = array_unique($missingPriceTypeIds);
        sort($missingPriceTypeIds);
        $unknownInstallCostReasons = array_unique($unknownInstallCostReasons);
        sort($unknownInstallCostReasons);

        return [
            'typeId' => $typeId,
            'typeName' => $typeName,
            'isT2' => $isT2,
            'runs' => $runs,
            'totalProductionCost' => $totalProductionCost !== null ? round($totalProductionCost, 2) : null,
            'sellPrice' => $sellPrice !== null ? round($sellPrice, 2) : null,
            'marginPercent' => $marginPercent,
            'components' => $analyzedComponents,
            'buildAllCost' => $buildAllCost !== null ? round($buildAllCost, 2) : null,
            'buyAllCost' => $buyAllCost !== null ? round($buyAllCost, 2) : null,
            'optimalMixCost' => $optimalMixCost !== null ? round($optimalMixCost, 2) : null,
            'buildTypeIds' => array_values(array_unique($buildTypeIds)),
            'buyTypeIds' => array_values(array_unique($buyTypeIds)),
            'missingPriceTypeIds' => $missingPriceTypeIds,
            'unknownInstallCostReasons' => $unknownInstallCostReasons,
        ];
    }

    /**
     * @return self::VERDICT_*
     */
    private function verdictOf(?float $buildCost, ?float $buyCost): string
    {
        return match (true) {
            $buildCost === null && $buyCost === null => self::VERDICT_UNKNOWN,
            $buildCost === null => self::VERDICT_BUY,
            $buyCost === null, $buildCost <= $buyCost => self::VERDICT_BUILD,
            default => self::VERDICT_BUY,
        };
    }

    private function sumOfKnownCosts(?float $total, ?float $cost): ?float
    {
        return $total === null || $cost === null ? null : $total + $cost;
    }

    /**
     * Collect buildable intermediate components from the top level of the production tree.
     * Only depth-1 components are collected; their build costs already include buying
     * sub-components at market price, so recursing would double-count.
     *
     * @param array<string, mixed> $node
     * @param list<array{typeId: int, quantity: int, materials: list<array{materialTypeId: int, adjustedQuantity: int}>, materialTypeIds: list<int>, jobInstallCost: array{cost: float|null, unknownReason: string|null, missingAdjustedPriceTypeIds: list<int>}, stage: string, meLevel: int, runs: int}> $components
     */
    private function collectBuildableComponents(array $node, array &$components, int $solarSystemId, int $reactionSolarSystemId): void
    {
        foreach ($node['materials'] as $material) {
            if (!($material['isBuildable'] ?? false) || !isset($material['blueprint'])) {
                continue;
            }

            $blueprint = $material['blueprint'];
            $compTypeId = (int) $material['typeId'];
            $quantity = (int) $material['quantity'];

            // Collect the materials needed to build this component
            $compMaterials = [];
            $compMaterialTypeIds = [];
            foreach ($blueprint['materials'] as $subMat) {
                $compMaterials[] = [
                    'materialTypeId' => (int) $subMat['typeId'],
                    'adjustedQuantity' => (int) $subMat['quantity'],
                ];
                $compMaterialTypeIds[] = (int) $subMat['typeId'];
            }

            $activityType = (string) ($blueprint['activityType'] ?? 'manufacturing');
            $compRuns = (int) $blueprint['runs'];
            $jobInstallCost = $this->estimateJobInstallCost(
                (int) $blueprint['blueprintTypeId'],
                $activityType,
                $compRuns,
                $solarSystemId,
                $reactionSolarSystemId,
            );

            // ME: 10 for manufacturing, 0 for reactions (matches IndustryTreeService logic)
            $meLevel = $activityType === 'manufacturing' ? 10 : 0;

            // Classify stage
            $stage = $this->classifyStage($activityType, $blueprint);

            $components[] = [
                'typeId' => $compTypeId,
                'quantity' => $quantity,
                'materials' => $compMaterials,
                'materialTypeIds' => $compMaterialTypeIds,
                'jobInstallCost' => $jobInstallCost,
                'stage' => $stage,
                'meLevel' => $meLevel,
                'runs' => $compRuns,
            ];

            // Do NOT recurse deeper: sub-component costs (T1 parts, reactions) are already
            // included in this component's build cost via its material prices at Jita.
            // Recursing would double-count those costs in optimalMixCost.
        }
    }

    /**
     * @return array{cost: float|null, unknownReason: string|null, missingAdjustedPriceTypeIds: list<int>}
     */
    private function estimateJobInstallCost(int $blueprintTypeId, string $activityType, int $runs, int $solarSystemId, int $reactionSolarSystemId): array
    {
        $isReaction = $activityType === 'reaction';
        $me0Materials = $this->materialRepository->findMaterialsForBlueprints(
            [$blueprintTypeId],
            [$isReaction ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value],
        );

        return $this->esiCostIndexService->estimateJobInstallCost(
            $me0Materials[$blueprintTypeId] ?? [],
            $runs,
            $isReaction ? $reactionSolarSystemId : $solarSystemId,
            $activityType,
            null,
        );
    }

    /**
     * Collect raw (leaf) materials from the tree that are NOT buildable.
     * Aggregates by typeId.
     *
     * @param array<string, mixed> $node
     * @param array<int, int> $leafMaterials typeId => totalQuantity
     */
    private function collectLeafMaterialsFromTree(array $node, array &$leafMaterials): void
    {
        foreach ($node['materials'] as $material) {
            if (($material['isBuildable'] ?? false) && isset($material['blueprint'])) {
                // Skip buildable components, their leaf materials are handled by the component analysis
                continue;
            }

            $typeId = (int) $material['typeId'];
            $quantity = (int) $material['quantity'];
            $leafMaterials[$typeId] = ($leafMaterials[$typeId] ?? 0) + $quantity;
        }
    }

    /**
     * Classify the production stage of a component.
     *
     * @param array<string, mixed> $blueprint
     */
    private function classifyStage(string $activityType, array $blueprint): string
    {
        if ($activityType === 'reaction') {
            return 'Reaction';
        }

        foreach ($blueprint['materials'] as $material) {
            if (isset($material['blueprint']) && $material['blueprint']['activityType'] === 'reaction') {
                return 'T2 Component';
            }
        }

        return 'T1 Component';
    }
}
