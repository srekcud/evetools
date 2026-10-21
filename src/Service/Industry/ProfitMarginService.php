<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;

/**
 * Computes prospective profit margins for manufacturing/reaction items:
 * production cost (materials + job install + invention/copy) vs sell price at Jita, a player structure, and public contracts.
 */
class ProfitMarginService
{
    private const float CONTRACT_FEE_RATE = 0.0;

    public function __construct(
        private readonly IndustryTreeService $treeService,
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
        private readonly EsiCostIndexService $esiCostIndexService,
        private readonly InventionService $inventionService,
        private readonly PublicContractPriceService $publicContractPriceService,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly IndustryBlacklistService $blacklistService,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly IndustryActivityMaterialRepository $materialRepository,
    ) {
    }

    /**
     * Analyze profit margins for producing a given item.
     *
     * A leaf material without a Jita weighted price has a null unitPrice and totalPrice and is listed in
     * missingPriceTypeIds (sorted); materialCost, totalCost, costPerUnit, margins and every decryptor option's
     * totalProductionCost and bestMargin are then null, as when the invention cost is unknown.
     *
     * @return array<string, mixed>
     */
    public function analyze(
        int $typeId,
        int $runs,
        int $meLevel,
        int $teLevel,
        int $sellStructureId,
        ?int $solarSystemId,
        ?int $decryptorTypeId,
        float $brokerFeeRate,
        float $salesTaxRate,
        ?User $user,
    ): array {
        $typeName = $this->resolveTypeName($typeId);
        $isT2 = $this->inventionService->isT2($typeId);

        // Resolve excluded type IDs from user blacklist
        $excludedTypeIds = $user !== null ? $this->blacklistService->resolveBlacklistedTypeIds($user) : [];

        // 1. Build production tree
        $tree = $this->treeService->buildProductionTree($typeId, $runs, $meLevel, $excludedTypeIds, $user);

        // 2. Calculate output quantity
        $outputPerRun = (int) $tree['outputPerRun'];
        $outputQuantity = $runs * $outputPerRun;

        // 3. Collect leaf materials (items that are bought, not built)
        $rawMaterials = [];
        $this->collectLeafMaterials($rawMaterials, $tree);

        // 4. Price materials at Jita weighted
        $typeQuantities = [];
        foreach ($rawMaterials as &$mat) {
            $typeQuantities[$mat['typeId']] = $mat['quantity'];
        }
        unset($mat);

        $weightedPrices = $this->jitaMarketService->getWeightedSellPricesWithFallback($typeQuantities);

        $pricedMaterialCost = 0.0;
        $materialDetails = [];
        $missingPriceTypeIds = [];
        foreach ($rawMaterials as $mat) {
            $tid = $mat['typeId'];
            $unitPrice = isset($weightedPrices[$tid]) ? $weightedPrices[$tid]['weightedPrice'] : null;
            $totalPrice = null;
            if ($unitPrice === null) {
                $missingPriceTypeIds[] = $tid;
            } else {
                $totalPrice = $unitPrice * $mat['quantity'];
                $pricedMaterialCost += $totalPrice;
            }

            $materialDetails[] = [
                'typeId' => $tid,
                'typeName' => $mat['typeName'],
                'quantity' => $mat['quantity'],
                'unitPrice' => $unitPrice,
                'totalPrice' => $totalPrice,
            ];
        }
        sort($missingPriceTypeIds);
        $materialCost = $missingPriceTypeIds === [] ? $pricedMaterialCost : null;

        // 5. Resolve facility tax from user's structure config
        $facilityTaxRate = $this->resolveFacilityTaxRate($user, $solarSystemId);

        // 6. Calculate job install costs for all manufacturing/reaction nodes
        $jobInstallSteps = [];
        if ($solarSystemId !== null) {
            $this->collectJobInstallCosts($tree, $solarSystemId, $facilityTaxRate, $jobInstallSteps);
        }
        $unknownInstallCostReasons = array_values(array_unique(array_filter(
            array_column($jobInstallSteps, 'unknownReason'),
            static fn (?string $unknownReason): bool => $unknownReason !== null,
        )));
        sort($unknownInstallCostReasons);
        $jobInstallCost = $unknownInstallCostReasons === [] ? (float) array_sum(array_column($jobInstallSteps, 'installCost')) : null;

        // 7. Sell prices (needed before invention for decryptor margin comparison)
        $jitaSellData = $this->jitaMarketService->getWeightedSellPrice($typeId, $outputQuantity);
        $jitaSellPrice = $jitaSellData['weightedPrice'] ?? null;

        $structureSellPrice = $this->structureMarketService->getLowestSellPrice($sellStructureId, $typeId);
        $structureBuyPrice = $this->structureMarketService->getHighestBuyPrice($sellStructureId, $typeId);

        $contractSellPrice = $this->publicContractPriceService->getLowestUnitPrice($typeId);
        $contractCount = $this->publicContractPriceService->getContractCount($typeId);

        $structureName = $this->resolveStructureName($sellStructureId);

        // 8. Invention cost (T2 only); null when the invention success probability is unknown
        $inventionCost = 0.0;
        $copyCost = 0.0;
        $inventionDetails = null;

        if ($isT2 && $solarSystemId !== null) {
            // Calculate invention cost with selected decryptor
            $inventionResult = $this->inventionService->calculateInventionCost(
                $typeId,
                $solarSystemId,
                $decryptorTypeId,
                1, // 1 successful invention
                $facilityTaxRate,
            );

            if ($inventionResult !== null) {
                $inventionCost = $inventionResult['totalCost'];

                // Copy cost for intermediate T1 BPCs in the tree
                $this->collectCopyCosts($tree, $solarSystemId, $facilityTaxRate, $copyCost);

                // Build all decryptor options for comparison
                $decryptorOptions = $this->inventionService->buildDecryptorOptions(
                    $typeId,
                    $solarSystemId,
                    1,
                    $facilityTaxRate,
                );

                // Resolve selected decryptor name
                $selectedDecryptorName = $inventionResult['decryptorName'] ?? InventionService::NO_DECRYPTOR_NAME;

                // Transform decryptor options to match frontend contract
                $options = [];
                foreach ($decryptorOptions as $option) {
                    $optionInventionCost = $option['totalCost'];
                    $optionTotalProductionCost = null;
                    $optionBestMargin = null;

                    if ($optionInventionCost !== null && $materialCost !== null && $jobInstallCost !== null) {
                        $optionTotalProductionCost = $materialCost + $jobInstallCost + $optionInventionCost + $copyCost;

                        // Find best margin across all sell venues for this option
                        $optionBestMargin = $this->findBestMarginForCost(
                            $jitaSellPrice,
                            $structureSellPrice,
                            $structureBuyPrice,
                            $contractSellPrice,
                            $outputQuantity,
                            $optionTotalProductionCost,
                            $brokerFeeRate,
                            $salesTaxRate,
                        );
                    }

                    $options[] = [
                        'decryptorTypeId' => $option['decryptorTypeId'],
                        'decryptorName' => $option['decryptorName'],
                        'me' => $option['me'],
                        'te' => $option['te'],
                        'runs' => $option['runs'],
                        'probability' => $option['probability'],
                        'inventionCost' => $optionInventionCost,
                        'totalProductionCost' => $optionTotalProductionCost,
                        'bestMargin' => $optionBestMargin,
                    ];
                }

                $inventionDetails = [
                    'baseProbability' => $inventionResult['baseProbability'],
                    'unknownReason' => $inventionResult['unknownReason'] ?? null,
                    'datacores' => array_map(fn (array $d) => $d['typeName'], $inventionResult['datacores']),
                    'selectedDecryptorTypeId' => $decryptorTypeId,
                    'selectedDecryptorName' => $selectedDecryptorName,
                    'options' => $options,
                ];
            } else {
                // Copy cost for intermediate T1 BPCs in the tree (even if invention data is null)
                $this->collectCopyCosts($tree, $solarSystemId, $facilityTaxRate, $copyCost);
            }
        }

        // 9. Total cost (unknown as soon as the invention cost is unknown)
        $totalCost = null;
        $costPerUnit = null;
        $margins = ['jitaSell' => null, 'structureSell' => null, 'structureBuy' => null, 'contractSell' => null];

        if ($inventionCost !== null && $materialCost !== null && $jobInstallCost !== null) {
            $totalCost = $materialCost + $jobInstallCost + $inventionCost + $copyCost;
            $costPerUnit = $outputQuantity > 0 ? $totalCost / $outputQuantity : 0.0;

            // 10. Calculate margins
            $margins = $this->calculateMargins(
                $jitaSellPrice,
                $structureSellPrice,
                $structureBuyPrice,
                $contractSellPrice,
                $outputQuantity,
                $totalCost,
                $brokerFeeRate,
                $salesTaxRate,
            );
        }

        // 11. Daily volume
        $volumes = $this->jitaMarketService->getAverageDailyVolumes([$typeId]);
        $dailyVolume = $volumes[$typeId] ?? null;

        return [
            'typeId' => $typeId,
            'typeName' => $typeName,
            'isT2' => $isT2,
            'runs' => $runs,
            'outputQuantity' => $outputQuantity,
            'outputPerRun' => $outputPerRun,
            'materialCost' => $materialCost,
            'materials' => $materialDetails,
            'jobInstallCost' => $jobInstallCost,
            'jobInstallSteps' => $jobInstallSteps,
            'inventionCost' => $inventionCost,
            'copyCost' => $copyCost,
            'totalCost' => $totalCost,
            'costPerUnit' => $costPerUnit,
            'invention' => $inventionDetails,
            'sellPrices' => [
                'jitaSell' => $jitaSellPrice,
                'structureSell' => $structureSellPrice,
                'structureBuy' => $structureBuyPrice,
                'contractSell' => $contractSellPrice,
                'contractCount' => $contractCount,
                'structureId' => $sellStructureId,
                'structureName' => $structureName,
            ],
            'brokerFeeRate' => $brokerFeeRate,
            'salesTaxRate' => $salesTaxRate,
            'margins' => $margins,
            'dailyVolume' => $dailyVolume,
            'missingPriceTypeIds' => $missingPriceTypeIds,
            'unknownInstallCostReasons' => $unknownInstallCostReasons,
        ];
    }

    /**
     * Recursively collect leaf materials (not buildable) from the production tree.
     *
     * @param list<array{typeId: int, typeName: string, quantity: int}> $materials
     * @param array<string, mixed> $node
     */
    private function collectLeafMaterials(array &$materials, array $node): void
    {
        foreach ($node['materials'] as $material) {
            if (($material['isBuildable'] ?? false) && isset($material['blueprint'])) {
                $this->collectLeafMaterials($materials, $material['blueprint']);
            } else {
                $this->addToMaterialList($materials, (int) $material['typeId'], (string) $material['typeName'], (int) $material['quantity']);
            }
        }
    }

    /**
     * @param list<array{typeId: int, typeName: string, quantity: int}> $materials
     */
    private function addToMaterialList(array &$materials, int $typeId, string $typeName, int $quantity): void
    {
        foreach ($materials as &$mat) {
            if ($mat['typeId'] === $typeId) {
                $mat['quantity'] += $quantity;
                return;
            }
        }
        $materials[] = [
            'typeId' => $typeId,
            'typeName' => $typeName,
            'quantity' => $quantity,
        ];
    }

    /**
     * Recursively collect job install costs for manufacturing/reaction nodes.
     *
     * @param array<string, mixed> $node
     * @param list<array{productTypeId: int, productName: string, activityType: string, runs: int, installCost: float|null, unknownReason: string|null}> $steps
     */
    private function collectJobInstallCosts(array $node, int $solarSystemId, ?float $facilityTaxRate, array &$steps): void
    {
        $activityType = (string) ($node['activityType'] ?? 'manufacturing');

        if ($activityType === 'manufacturing' || $activityType === 'reaction') {
            $productTypeId = (int) $node['productTypeId'];
            $nodeRuns = (int) $node['runs'];
            $blueprintTypeId = (int) $node['blueprintTypeId'];

            // Compute EIV from ME0 materials for this node's blueprint
            $activityIdValue = $activityType === 'reaction' ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
            $me0Materials = $this->materialRepository->findMaterialsForBlueprints(
                [$blueprintTypeId],
                [$activityIdValue],
            );
            $installCost = $this->esiCostIndexService->estimateJobInstallCost(
                $me0Materials[$blueprintTypeId] ?? [],
                $nodeRuns,
                $solarSystemId,
                $activityType,
                $facilityTaxRate,
            );

            $steps[] = [
                'productTypeId' => $productTypeId,
                'productName' => (string) $node['productTypeName'],
                'activityType' => $activityType,
                'runs' => $nodeRuns,
                'installCost' => $installCost['cost'],
                'unknownReason' => $installCost['unknownReason'],
            ];
        }

        // Recurse into children
        foreach ($node['materials'] as $material) {
            if (($material['isBuildable'] ?? false) && isset($material['blueprint'])) {
                $this->collectJobInstallCosts($material['blueprint'], $solarSystemId, $facilityTaxRate, $steps);
            }
        }
    }

    /**
     * Recursively collect copy costs for nodes that have a copy activity.
     *
     * @param array<string, mixed> $node
     */
    private function collectCopyCosts(array $node, int $solarSystemId, ?float $facilityTaxRate, float &$totalCost): void
    {
        if ($node['hasCopy'] ?? false) {
            $copyCost = $this->inventionService->getCopyJobCost(
                $node['blueprintTypeId'],
                $node['runs'],
                $solarSystemId,
                $facilityTaxRate,
            );
            $totalCost += $copyCost;
        }

        foreach ($node['materials'] as $material) {
            if (($material['isBuildable'] ?? false) && isset($material['blueprint'])) {
                $this->collectCopyCosts($material['blueprint'], $solarSystemId, $facilityTaxRate, $totalCost);
            }
        }
    }

    /**
     * Calculate margins for each sell venue.
     *
     * @return array<string, array{revenue: float, fees: float, profit: float, margin: float|null}|null>
     */
    private function calculateMargins(
        ?float $jitaSellPrice,
        ?float $structureSellPrice,
        ?float $structureBuyPrice,
        ?float $contractSellPrice,
        int $outputQuantity,
        float $totalCost,
        float $brokerFeeRate,
        float $salesTaxRate,
    ): array {
        $sellOrderFeeRate = $brokerFeeRate + $salesTaxRate;

        return [
            'jitaSell' => $this->computeMarginForPrice($jitaSellPrice, $outputQuantity, $totalCost, $sellOrderFeeRate),
            'structureSell' => $this->computeMarginForPrice($structureSellPrice, $outputQuantity, $totalCost, $sellOrderFeeRate),
            'structureBuy' => $this->computeMarginForPrice($structureBuyPrice, $outputQuantity, $totalCost, $salesTaxRate),
            'contractSell' => $this->computeMarginForPrice($contractSellPrice, $outputQuantity, $totalCost, self::CONTRACT_FEE_RATE),
        ];
    }

    /**
     * Compute margin for a single sell price point.
     *
     * @return array{revenue: float, fees: float, profit: float, margin: float|null}|null
     */
    private function computeMarginForPrice(
        ?float $unitPrice,
        int $outputQuantity,
        float $totalCost,
        float $feeRate,
    ): ?array {
        if ($unitPrice === null || $unitPrice <= 0.0) {
            return null;
        }

        $grossRevenue = $unitPrice * $outputQuantity;
        $fees = $grossRevenue * $feeRate;
        $netRevenue = $grossRevenue - $fees;
        $profit = $netRevenue - $totalCost;
        $margin = $totalCost > 0 ? ($profit / $totalCost) * 100.0 : null;

        return [
            'revenue' => $grossRevenue,
            'fees' => $fees,
            'profit' => $profit,
            'margin' => $margin,
        ];
    }

    /**
     * Find the best margin percentage across all sell venues for a given total production cost.
     */
    private function findBestMarginForCost(
        ?float $jitaSellPrice,
        ?float $structureSellPrice,
        ?float $structureBuyPrice,
        ?float $contractSellPrice,
        int $outputQuantity,
        float $totalProductionCost,
        float $brokerFeeRate,
        float $salesTaxRate,
    ): ?float {
        $venueMargins = $this->calculateMargins(
            $jitaSellPrice,
            $structureSellPrice,
            $structureBuyPrice,
            $contractSellPrice,
            $outputQuantity,
            $totalProductionCost,
            $brokerFeeRate,
            $salesTaxRate,
        );
        $knownMargins = array_filter(
            array_map(static fn (?array $venueMargin): ?float => $venueMargin === null ? null : $venueMargin['margin'], $venueMargins),
            static fn (?float $margin): bool => $margin !== null,
        );

        return $knownMargins === [] ? null : max($knownMargins);
    }

    /**
     * Resolve the facility tax rate from the user's structure config for the given solar system.
     * Falls back to default structure config, then null (0% tax).
     */
    private function resolveFacilityTaxRate(?User $user, ?int $solarSystemId): ?float
    {
        if ($user === null) {
            return null;
        }

        $structures = $this->structureConfigRepository->findByUser($user);

        if (empty($structures)) {
            return null;
        }

        // Try to find a structure in the requested solar system
        if ($solarSystemId !== null) {
            foreach ($structures as $structure) {
                if ($structure->getSolarSystemId() === $solarSystemId) {
                    return $structure->getFacilityTaxRate();
                }
            }
        }

        // Fall back to the user's default structure
        foreach ($structures as $structure) {
            if ($structure->isDefault()) {
                return $structure->getFacilityTaxRate();
            }
        }

        return null;
    }

    private function resolveTypeName(int $typeId): ?string
    {
        return $this->typeNameResolver->findName($typeId);
    }

    private function resolveStructureName(int $structureId): string
    {
        $structure = $this->cachedStructureRepository->findByStructureId($structureId);

        return $structure?->getName() ?? "Structure #{$structureId}";
    }
}
