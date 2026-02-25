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
    public function __construct(
        private readonly IndustryTreeService $treeService,
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
        private readonly EsiCostIndexService $esiCostIndexService,
        private readonly InventionService $inventionService,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly IndustryBlacklistService $blacklistService,
        private readonly IndustryActivityMaterialRepository $materialRepository,
    ) {
    }

    /**
     * Analyze buy-vs-build for all intermediate components of a product.
     *
     * @return array{
     *     typeId: int,
     *     typeName: string,
     *     isT2: bool,
     *     runs: int,
     *     totalProductionCost: float,
     *     sellPrice: float|null,
     *     marginPercent: float|null,
     *     components: list<array{typeId: int, typeName: string, quantity: int, stage: string, buildCost: float, buildMaterials: list<array{typeId: int, typeName: string, quantity: int, unitPrice: float, totalPrice: float}>, buildJobInstallCost: float, buyCostJita: float|null, buyCostStructure: float|null, verdict: string, savings: float, savingsPercent: float, meUsed: int, runs: int}>,
     *     buildAllCost: float,
     *     buyAllCost: float,
     *     optimalMixCost: float,
     *     buildTypeIds: list<int>,
     *     buyTypeIds: list<int>,
     * }
     */
    public function analyze(
        int $typeId,
        int $runs,
        int $meLevel,
        int $solarSystemId,
        int $sellStructureId,
        float $brokerFeeRate,
        float $salesTaxRate,
        ?User $user,
    ): array {
        $typeName = $this->typeNameResolver->resolve($typeId);
        $isT2 = $this->inventionService->isT2($typeId);

        // Build the full production tree WITHOUT blacklist to decompose everything
        $tree = $this->treeService->buildProductionTree($typeId, $runs, $meLevel, [], $user);

        $outputPerRun = (int) $tree['outputPerRun'];
        $outputQuantity = $runs * $outputPerRun;

        // Collect all buildable intermediate components from the tree
        $components = [];
        $this->collectBuildableComponents($tree, $components, $solarSystemId);

        // Collect all type IDs for batch pricing
        $allTypeIds = [$typeId];
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
        $structureSellPrice = $this->structureMarketService->getLowestSellPrice($sellStructureId, $typeId);
        $sellPrice = $structureSellPrice ?? ($jitaPrices[$typeId] ?? null);

        // Analyze each buildable component
        $analyzedComponents = [];
        $buildAllCost = 0.0;
        $buyAllCost = 0.0;
        $optimalMixCost = 0.0;
        $buildTypeIds = [];
        $buyTypeIds = [];

        // Batch structure prices for all component type IDs
        $componentTypeIds = array_map(static fn (array $c) => $c['typeId'], $components);
        $structurePrices = $componentTypeIds !== []
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

            // Best buy cost: prefer structure, fall back to Jita
            $buyCost = $buyCostStructure ?? $buyCostJita ?? 0.0;

            // Build materials with resolved names and prices
            $buildMaterials = [];
            $matCost = 0.0;
            foreach ($comp['materials'] as $mat) {
                $matTypeId = $mat['materialTypeId'];
                $matQty = $mat['adjustedQuantity'];
                $matUnitPrice = $jitaPrices[$matTypeId] ?? 0.0;
                $matTotalPrice = $matUnitPrice * $matQty;
                $matCost += $matTotalPrice;

                $buildMaterials[] = [
                    'typeId' => $matTypeId,
                    'typeName' => $this->typeNameResolver->resolve($matTypeId),
                    'quantity' => $matQty,
                    'unitPrice' => round($matUnitPrice, 2),
                    'totalPrice' => round($matTotalPrice, 2),
                ];
            }

            $jobInstallCost = $comp['jobInstallCost'];
            $buildCost = $matCost + $jobInstallCost;

            // Savings: difference between buy and build
            $savings = $buyCost - $buildCost;

            // Savings percent relative to the more expensive option
            $maxCost = max($buildCost, $buyCost);
            $savingsPercent = $maxCost > 0 ? round(abs($savings) / $maxCost * 100, 2) : 0.0;

            // Determine verdict (lowercase to match frontend types)
            if ($buyCost <= 0.0 && $buildCost <= 0.0) {
                $verdict = 'buy'; // fallback
            } elseif ($buyCost <= 0.0) {
                $verdict = 'build'; // No market price, must build
            } elseif ($buildCost <= $buyCost) {
                $verdict = 'build';
            } else {
                $verdict = 'buy';
            }

            $buildAllCost += $buildCost;
            $buyAllCost += $buyCost;

            if ($verdict === 'build') {
                $optimalMixCost += $buildCost;
                $buildTypeIds[] = $compTypeId;
            } else {
                $optimalMixCost += $buyCost;
                $buyTypeIds[] = $compTypeId;
            }

            $analyzedComponents[] = [
                'typeId' => $compTypeId,
                'typeName' => $this->typeNameResolver->resolve($compTypeId),
                'quantity' => $quantity,
                'stage' => $comp['stage'],
                'buildCost' => round($buildCost, 2),
                'buildMaterials' => $buildMaterials,
                'buildJobInstallCost' => round($jobInstallCost, 2),
                'buyCostJita' => $buyCostJita !== null ? round($buyCostJita, 2) : null,
                'buyCostStructure' => $buyCostStructure !== null ? round($buyCostStructure, 2) : null,
                'verdict' => $verdict,
                'savings' => round($savings, 2),
                'savingsPercent' => $savingsPercent,
                'meUsed' => $comp['meLevel'],
                'runs' => $comp['runs'],
            ];
        }

        // Sort by savings descending
        usort($analyzedComponents, static fn (array $a, array $b) => $b['savings'] <=> $a['savings']);

        // Calculate margin for the final product using optimal mix cost
        // Add leaf material costs + final product job install cost
        $leafMaterialCost = 0.0;
        $leafMaterials = [];
        $this->collectLeafMaterialsFromTree($tree, $leafMaterials);
        foreach ($leafMaterials as $matTypeId => $matQty) {
            $leafMaterialCost += ($jitaPrices[$matTypeId] ?? 0.0) * $matQty;
        }

        // Compute EIV from ME0 materials for the final product blueprint
        $finalBpId = (int) $tree['blueprintTypeId'];
        $finalActivityIdValue = $tree['activityType'] === 'reaction' ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
        $finalMe0Materials = $this->materialRepository->findMaterialsForBlueprints(
            [$finalBpId],
            [$finalActivityIdValue],
        );
        $finalEiv = $this->esiCostIndexService->calculateEiv($finalMe0Materials[$finalBpId] ?? []);

        $finalJobCost = $this->esiCostIndexService->calculateJobInstallCost(
            $finalEiv,
            $runs,
            $solarSystemId,
            $tree['activityType'],
        );

        $totalProductionCost = $optimalMixCost + $leafMaterialCost + $finalJobCost;

        $marginPercent = null;
        if ($sellPrice !== null && $sellPrice > 0 && $totalProductionCost > 0) {
            $grossRevenue = $sellPrice * $outputQuantity;
            $fees = $grossRevenue * ($brokerFeeRate + $salesTaxRate);
            $netRevenue = $grossRevenue - $fees;
            $profit = $netRevenue - $totalProductionCost;
            $marginPercent = round(($profit / $totalProductionCost) * 100, 2);
        }

        return [
            'typeId' => $typeId,
            'typeName' => $typeName,
            'isT2' => $isT2,
            'runs' => $runs,
            'totalProductionCost' => round($totalProductionCost, 2),
            'sellPrice' => $sellPrice !== null ? round($sellPrice, 2) : null,
            'marginPercent' => $marginPercent,
            'components' => $analyzedComponents,
            'buildAllCost' => round($buildAllCost, 2),
            'buyAllCost' => round($buyAllCost, 2),
            'optimalMixCost' => round($optimalMixCost, 2),
            'buildTypeIds' => array_values(array_unique($buildTypeIds)),
            'buyTypeIds' => array_values(array_unique($buyTypeIds)),
        ];
    }

    /**
     * Recursively collect buildable intermediate components from the production tree.
     * A buildable component is a material node that has isBuildable=true.
     *
     * @param array<string, mixed> $node
     * @param list<array{typeId: int, quantity: int, materials: list<array{materialTypeId: int, adjustedQuantity: int}>, materialTypeIds: list<int>, jobInstallCost: float, stage: string, meLevel: int, runs: int}> $components
     */
    private function collectBuildableComponents(array $node, array &$components, int $solarSystemId): void
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

            // Job install cost for this component — compute EIV from ME0 materials
            $activityType = (string) ($blueprint['activityType'] ?? 'manufacturing');
            $compRuns = (int) $blueprint['runs'];
            $compBpId = (int) $blueprint['blueprintTypeId'];
            $activityIdValue = $activityType === 'reaction' ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
            $compMe0Materials = $this->materialRepository->findMaterialsForBlueprints(
                [$compBpId],
                [$activityIdValue],
            );
            $compEiv = $this->esiCostIndexService->calculateEiv($compMe0Materials[$compBpId] ?? []);

            $jobInstallCost = $this->esiCostIndexService->calculateJobInstallCost(
                $compEiv,
                $compRuns,
                $solarSystemId,
                $activityType,
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

            // Recurse deeper
            $this->collectBuildableComponents($blueprint, $components, $solarSystemId);
        }
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

        $depth = (int) ($blueprint['depth'] ?? 0);

        if ($depth >= 2) {
            return 'T1 Component';
        }

        return 'T2 Component';
    }
}
