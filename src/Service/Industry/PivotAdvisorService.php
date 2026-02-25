<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedAssetRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\JitaMarketService;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds alternative products sharing intermediate components with a source product.
 * Helps pivot production when a product becomes unprofitable.
 */
class PivotAdvisorService
{
    private const MAX_CANDIDATES = 6;

    public function __construct(
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly CachedAssetRepository $assetRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly EsiCostIndexService $esiCostIndexService,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly InventionService $inventionService,
    ) {
    }

    /**
     * Analyze pivot options for a source product.
     *
     * @return array{
     *     typeId: int,
     *     sourceProduct: array<string, mixed>,
     *     matrix: list<array<string, mixed>>,
     *     candidates: list<array<string, mixed>>,
     *     matrixProductIds: list<int>,
     * }
     */
    public function analyze(
        int $sourceTypeId,
        int $runs,
        int $solarSystemId,
        float $brokerFeeRate,
        float $salesTaxRate,
        User $user,
    ): array {
        $activityIds = [IndustryActivityType::Manufacturing->value, IndustryActivityType::Reaction->value];

        // 1. Find the blueprint for the source product
        $sourceBlueprint = $this->productRepository->findBlueprintForProduct($sourceTypeId, IndustryActivityType::Manufacturing->value)
            ?? $this->productRepository->findBlueprintForProduct($sourceTypeId, IndustryActivityType::Reaction->value);

        if ($sourceBlueprint === null) {
            return $this->emptyResult($sourceTypeId);
        }

        $sourceBpId = $sourceBlueprint->getTypeId();
        $sourceOutputPerRun = $sourceBlueprint->getQuantity();
        $sourceActivityId = $sourceBlueprint->getActivityId();

        // 2. Get source blueprint materials
        $materialsByBp = $this->materialRepository->findMaterialsForBlueprints([$sourceBpId], $activityIds);
        $sourceMaterials = $materialsByBp[$sourceBpId] ?? [];

        if (empty($sourceMaterials)) {
            return $this->emptyResult($sourceTypeId);
        }

        // 3. Filter buildable materials (those that have their own blueprint)
        $buildableMaterials = [];
        foreach ($sourceMaterials as $mat) {
            $matBp = $this->productRepository->findBlueprintForProduct($mat['materialTypeId'], IndustryActivityType::Manufacturing->value)
                ?? $this->productRepository->findBlueprintForProduct($mat['materialTypeId'], IndustryActivityType::Reaction->value);

            if ($matBp !== null) {
                $buildableMaterials[] = $mat;
            }
        }

        if (empty($buildableMaterials)) {
            return $this->buildResultWithSourceOnly($sourceTypeId, $sourceMaterials, $sourceOutputPerRun, $sourceActivityId, $solarSystemId, $brokerFeeRate, $salesTaxRate, $user);
        }

        $buildableMaterialTypeIds = array_map(fn (array $m) => $m['materialTypeId'], $buildableMaterials);

        // 4. Get user stock
        $userStock = $this->assetRepository->getAggregatedQuantitiesByUser($user);

        // 5. Reverse lookup: find products using these buildable materials
        $candidateProducts = $this->materialRepository->findProductsUsingMaterials($buildableMaterialTypeIds, $activityIds);

        // Exclude the source product
        $candidateProducts = array_values(array_filter(
            $candidateProducts,
            fn (array $p) => $p['productTypeId'] !== $sourceTypeId,
        ));

        if (empty($candidateProducts)) {
            return $this->buildResultWithSourceOnly($sourceTypeId, $sourceMaterials, $sourceOutputPerRun, $sourceActivityId, $solarSystemId, $brokerFeeRate, $salesTaxRate, $user);
        }

        // 6. Batch-fetch candidate materials
        $candidateBpIds = array_unique(array_map(fn (array $p) => $p['blueprintTypeId'], $candidateProducts));
        $candidateMaterialsByBp = $this->materialRepository->findMaterialsForBlueprints($candidateBpIds, $activityIds);

        // 7. Collect all type IDs for pricing
        $allTypeIds = [$sourceTypeId];
        foreach ($buildableMaterials as $mat) {
            $allTypeIds[] = $mat['materialTypeId'];
        }
        foreach ($candidateProducts as $cp) {
            $allTypeIds[] = $cp['productTypeId'];
        }
        foreach ($candidateMaterialsByBp as $mats) {
            foreach ($mats as $mat) {
                $allTypeIds[] = $mat['materialTypeId'];
            }
        }
        // Also add source raw materials
        foreach ($sourceMaterials as $mat) {
            $allTypeIds[] = $mat['materialTypeId'];
        }
        $allTypeIds = array_values(array_unique($allTypeIds));

        $jitaPrices = $this->jitaMarketService->getPrices($allTypeIds);
        $productTypeIds = array_merge([$sourceTypeId], array_map(fn (array $p) => $p['productTypeId'], $candidateProducts));
        $dailyVolumes = $this->jitaMarketService->getCachedDailyVolumes($productTypeIds);

        // 8. Identify T2 products
        $t2ProductIds = $this->inventionService->identifyT2Products($candidateProducts);

        // Also check if source is T2
        $sourceIsT2 = $this->isT2Product($sourceTypeId, $sourceBpId);
        $sourceIsReaction = $sourceActivityId === IndustryActivityType::Reaction->value;

        // 9. Resolve type names and group names
        $typeNames = $this->typeNameResolver->resolveMany($allTypeIds);
        $groupNames = $this->loadGroupNames(array_merge([$sourceTypeId], array_map(fn (array $p) => $p['productTypeId'], $candidateProducts)));

        // Build buildable material type ID set for quick lookup
        $buildableSet = array_flip($buildableMaterialTypeIds);

        // 10. Score each candidate (1 run)
        $scoredCandidates = [];
        foreach ($candidateProducts as $cp) {
            $cpTypeId = $cp['productTypeId'];
            $cpBpId = $cp['blueprintTypeId'];
            $cpOutputPerRun = $cp['outputPerRun'];
            $cpActivityId = $cp['activityId'];
            $cpIsT2 = isset($t2ProductIds[$cpTypeId]);
            $cpIsReaction = $cpActivityId === IndustryActivityType::Reaction->value;

            $me = $cpIsT2 ? 2 : ($cpIsReaction ? 0 : 10);
            $activityType = $cpIsReaction ? 'reaction' : 'manufacturing';

            $cpMaterials = $candidateMaterialsByBp[$cpBpId] ?? [];

            // Material cost (1 run)
            $materialCost = 0.0;
            foreach ($cpMaterials as $mat) {
                $meMultiplier = !$cpIsReaction && $me > 0 ? (1 - $me / 100) : 1.0;
                $adjustedQty = max(1, (int) ceil($mat['quantity'] * $meMultiplier));
                $unitPrice = $jitaPrices[$mat['materialTypeId']] ?? 0.0;
                $materialCost += $adjustedQty * $unitPrice;
            }

            // Job install cost (1 run) — EIV uses ME0 quantities from SDE
            $eiv = $this->esiCostIndexService->calculateEiv($cpMaterials);
            $jobInstallCost = $this->esiCostIndexService->calculateJobInstallCost(
                $eiv,
                1,
                $solarSystemId,
                $activityType,
            );

            $totalCost = $materialCost + $jobInstallCost;

            // Sell price
            $sellPrice = $jitaPrices[$cpTypeId] ?? null;
            if ($sellPrice === null || $sellPrice <= 0.0 || $totalCost <= 0.0) {
                continue;
            }

            $fees = $sellPrice * ($brokerFeeRate + $salesTaxRate);
            $netSellPrice = $sellPrice - $fees;
            $marginPercent = ($netSellPrice * $cpOutputPerRun - $totalCost) / $totalCost * 100;
            $profitPerUnit = ($netSellPrice * $cpOutputPerRun - $totalCost) / $cpOutputPerRun;
            $dailyVolume = $dailyVolumes[$cpTypeId] ?? 0.0;

            // Coverage (value-weighted): how much of the candidate's buildable materials
            // overlap with the source product's buildable materials, weighted by stock
            $coverageNumerator = 0.0;
            $coverageDenominator = 0.0;
            $missingComponents = [];
            $additionalCost = 0.0;

            foreach ($cpMaterials as $mat) {
                $matTypeId = $mat['materialTypeId'];
                if (!isset($buildableSet[$matTypeId])) {
                    continue;
                }

                $meMultiplier = !$cpIsReaction && $me > 0 ? (1 - $me / 100) : 1.0;
                $neededQty = max(1, (int) ceil($mat['quantity'] * $meMultiplier));
                $matPrice = $jitaPrices[$matTypeId] ?? 0.0;
                $matCost = $neededQty * $matPrice;
                $coverageDenominator += $matCost;

                $inStock = $userStock[$matTypeId] ?? 0;
                $coveredQty = min($inStock, $neededQty);
                $coverageNumerator += $coveredQty * $matPrice;

                if ($inStock < $neededQty) {
                    $missingQty = $neededQty - $inStock;
                    $missingCost = $missingQty * $matPrice;
                    $additionalCost += $missingCost;
                    $missingComponents[] = [
                        'typeId' => $matTypeId,
                        'typeName' => $typeNames[$matTypeId] ?? "Type #{$matTypeId}",
                        'quantity' => $missingQty,
                        'cost' => round($missingCost, 2),
                    ];
                }
            }

            $coveragePercent = $coverageDenominator > 0
                ? ($coverageNumerator / $coverageDenominator) * 100
                : 0.0;

            $estimatedProfit = $profitPerUnit * $runs;

            // Score: coverage * margin * log10(volume + 1)
            $score = $coveragePercent * $marginPercent * log10($dailyVolume + 1);

            $scoredCandidates[] = [
                'typeId' => $cpTypeId,
                'typeName' => $typeNames[$cpTypeId] ?? "Type #{$cpTypeId}",
                'groupName' => $groupNames[$cpTypeId] ?? '',
                'marginPercent' => round($marginPercent, 2),
                'profitPerUnit' => round($profitPerUnit, 2),
                'dailyVolume' => $dailyVolume,
                'coveragePercent' => round($coveragePercent, 2),
                'missingComponents' => $missingComponents,
                'additionalCost' => round($additionalCost, 2),
                'estimatedProfit' => round($estimatedProfit, 2),
                'score' => round($score, 2),
                'blueprintTypeId' => $cpBpId,
            ];
        }

        // Sort by score descending, keep top N
        usort($scoredCandidates, static fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $topCandidates = \array_slice($scoredCandidates, 0, self::MAX_CANDIDATES);

        // 11. Build source product summary
        $sourceProduct = $this->buildSourceProductSummary(
            $sourceTypeId,
            $sourceMaterials,
            $sourceOutputPerRun,
            $sourceActivityId,
            $sourceIsT2,
            $sourceIsReaction,
            $solarSystemId,
            $brokerFeeRate,
            $salesTaxRate,
            $jitaPrices,
            $dailyVolumes,
            $typeNames,
            $groupNames,
            $buildableMaterials,
            $userStock,
        );

        // 12. Build matrix
        $topCandidateTypeIds = array_map(fn (array $c) => $c['typeId'], $topCandidates);
        $matrixProductIds = array_merge([$sourceTypeId], $topCandidateTypeIds);

        // Collect candidate materials by product type ID for matrix
        $candidateMaterialsByProduct = [];
        foreach ($candidateProducts as $cp) {
            if (in_array($cp['productTypeId'], $topCandidateTypeIds, true)) {
                $cpBpId = $cp['blueprintTypeId'];
                $cpMats = $candidateMaterialsByBp[$cpBpId] ?? [];
                $matSet = [];
                foreach ($cpMats as $mat) {
                    $matSet[$mat['materialTypeId']] = $mat['quantity'];
                }
                $candidateMaterialsByProduct[$cp['productTypeId']] = $matSet;
            }
        }

        // Source materials by type ID
        $sourceMaterialSet = [];
        foreach ($sourceMaterials as $mat) {
            $sourceMaterialSet[$mat['materialTypeId']] = $mat['quantity'];
        }

        $matrix = [];
        foreach ($buildableMaterials as $mat) {
            $matTypeId = $mat['materialTypeId'];
            $inStock = $userStock[$matTypeId] ?? 0;

            $candidates = [];
            foreach ($matrixProductIds as $productTypeId) {
                if ($productTypeId === $sourceTypeId) {
                    $needed = $sourceMaterialSet[$matTypeId] ?? 0;
                } else {
                    $needed = $candidateMaterialsByProduct[$productTypeId][$matTypeId] ?? 0;
                }

                if ($needed === 0) {
                    $candidates[$productTypeId] = ['needed' => 0, 'status' => 'none'];
                } elseif ($inStock >= $needed) {
                    $candidates[$productTypeId] = ['needed' => $needed, 'status' => 'covered'];
                } elseif ($inStock > 0) {
                    $candidates[$productTypeId] = ['needed' => $needed, 'status' => 'partial'];
                } else {
                    $candidates[$productTypeId] = ['needed' => $needed, 'status' => 'none'];
                }
            }

            $matrix[] = [
                'typeId' => $matTypeId,
                'typeName' => $typeNames[$matTypeId] ?? "Type #{$matTypeId}",
                'inStock' => $inStock,
                'candidates' => $candidates,
            ];
        }

        // Remove internal key before returning
        $cleanCandidates = array_map(function (array $c) {
            unset($c['blueprintTypeId']);

            return $c;
        }, $topCandidates);

        return [
            'typeId' => $sourceTypeId,
            'sourceProduct' => $sourceProduct,
            'matrix' => $matrix,
            'candidates' => $cleanCandidates,
            'matrixProductIds' => $matrixProductIds,
        ];
    }

    /**
     * Check if a single product is T2 (its blueprint is produced via invention).
     */
    private function isT2Product(int $productTypeId, int $blueprintTypeId): bool
    {
        $conn = $this->entityManager->getConnection();
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM sde_industry_activity_products
            WHERE activity_id = 8
              AND product_type_id = ?
        SQL;

        return (int) $conn->fetchOne($sql, [$blueprintTypeId]) > 0;
    }

    /**
     * Load group names for product type IDs.
     *
     * @param int[] $typeIds
     * @return array<int, string>
     */
    private function loadGroupNames(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $conn = $this->entityManager->getConnection();
        $placeholders = implode(',', array_fill(0, count($typeIds), '?'));

        $sql = <<<SQL
            SELECT t.type_id, g.group_name
            FROM sde_inv_types t
            JOIN sde_inv_groups g ON g.group_id = t.group_id
            WHERE t.type_id IN ({$placeholders})
        SQL;

        $rows = $conn->fetchAllAssociative($sql, array_values($typeIds));

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['type_id']] = (string) $row['group_name'];
        }

        return $result;
    }

    /**
     * @param list<array{materialTypeId: int, quantity: int}> $sourceMaterials
     * @param list<array{materialTypeId: int, quantity: int}> $buildableMaterials
     * @param array<int, float|null> $jitaPrices
     * @param array<int, float> $dailyVolumes
     * @param array<int, string> $typeNames
     * @param array<int, string> $groupNames
     * @param array<int, int> $userStock
     * @return array<string, mixed>
     */
    private function buildSourceProductSummary(
        int $sourceTypeId,
        array $sourceMaterials,
        int $outputPerRun,
        int $activityId,
        bool $isT2,
        bool $isReaction,
        int $solarSystemId,
        float $brokerFeeRate,
        float $salesTaxRate,
        array $jitaPrices,
        array $dailyVolumes,
        array $typeNames,
        array $groupNames,
        array $buildableMaterials,
        array $userStock,
    ): array {
        $me = $isT2 ? 2 : ($isReaction ? 0 : 10);
        $activityType = $isReaction ? 'reaction' : 'manufacturing';

        // Material cost (1 run)
        $materialCost = 0.0;
        foreach ($sourceMaterials as $mat) {
            $meMultiplier = !$isReaction && $me > 0 ? (1 - $me / 100) : 1.0;
            $adjustedQty = max(1, (int) ceil($mat['quantity'] * $meMultiplier));
            $unitPrice = $jitaPrices[$mat['materialTypeId']] ?? 0.0;
            $materialCost += $adjustedQty * $unitPrice;
        }

        // EIV uses ME0 quantities from SDE (sourceMaterials are already ME0)
        $eiv = $this->esiCostIndexService->calculateEiv($sourceMaterials);
        $jobInstallCost = $this->esiCostIndexService->calculateJobInstallCost(
            $eiv,
            1,
            $solarSystemId,
            $activityType,
        );

        $totalCost = $materialCost + $jobInstallCost;

        $sellPrice = $jitaPrices[$sourceTypeId] ?? null;
        $marginPercent = null;
        if ($sellPrice !== null && $sellPrice > 0 && $totalCost > 0) {
            $fees = $sellPrice * ($brokerFeeRate + $salesTaxRate);
            $netSellPrice = $sellPrice - $fees;
            $marginPercent = round(($netSellPrice * $outputPerRun - $totalCost) / $totalCost * 100, 2);
        }

        // Key components: buildable materials with stock info
        $keyComponents = [];
        foreach ($buildableMaterials as $mat) {
            $matTypeId = $mat['materialTypeId'];
            $keyComponents[] = [
                'typeId' => $matTypeId,
                'typeName' => $typeNames[$matTypeId] ?? "Type #{$matTypeId}",
                'inStock' => $userStock[$matTypeId] ?? 0,
            ];
        }

        return [
            'typeId' => $sourceTypeId,
            'typeName' => $typeNames[$sourceTypeId] ?? "Type #{$sourceTypeId}",
            'groupName' => $groupNames[$sourceTypeId] ?? '',
            'marginPercent' => $marginPercent,
            'sellPrice' => $sellPrice !== null ? round($sellPrice, 2) : null,
            'dailyVolume' => $dailyVolumes[$sourceTypeId] ?? 0.0,
            'keyComponents' => $keyComponents,
        ];
    }

    /**
     * @return array{typeId: int, sourceProduct: array<string, mixed>, matrix: list<mixed>, candidates: list<mixed>, matrixProductIds: list<int>}
     */
    private function emptyResult(int $sourceTypeId): array
    {
        $typeName = $this->typeNameResolver->resolve($sourceTypeId);

        return [
            'typeId' => $sourceTypeId,
            'sourceProduct' => [
                'typeId' => $sourceTypeId,
                'typeName' => $typeName,
                'groupName' => '',
                'marginPercent' => null,
                'sellPrice' => null,
                'dailyVolume' => 0.0,
                'keyComponents' => [],
            ],
            'matrix' => [],
            'candidates' => [],
            'matrixProductIds' => [$sourceTypeId],
        ];
    }

    /**
     * Build result when source has no buildable materials (only raw materials).
     *
     * @param list<array{materialTypeId: int, quantity: int}> $sourceMaterials
     * @return array{typeId: int, sourceProduct: array<string, mixed>, matrix: list<mixed>, candidates: list<mixed>, matrixProductIds: list<int>}
     */
    private function buildResultWithSourceOnly(
        int $sourceTypeId,
        array $sourceMaterials,
        int $outputPerRun,
        int $activityId,
        int $solarSystemId,
        float $brokerFeeRate,
        float $salesTaxRate,
        User $user,
    ): array {
        $isReaction = $activityId === IndustryActivityType::Reaction->value;
        $isT2 = false;

        // Find blueprint to check T2
        $bp = $this->productRepository->findBlueprintForProduct($sourceTypeId, IndustryActivityType::Manufacturing->value);
        if ($bp !== null) {
            $isT2 = $this->isT2Product($sourceTypeId, $bp->getTypeId());
        }

        $allTypeIds = [$sourceTypeId];
        foreach ($sourceMaterials as $mat) {
            $allTypeIds[] = $mat['materialTypeId'];
        }
        $allTypeIds = array_values(array_unique($allTypeIds));

        $jitaPrices = $this->jitaMarketService->getPrices($allTypeIds);
        $dailyVolumes = $this->jitaMarketService->getCachedDailyVolumes([$sourceTypeId]);
        $typeNames = $this->typeNameResolver->resolveMany($allTypeIds);
        $groupNames = $this->loadGroupNames([$sourceTypeId]);
        $userStock = $this->assetRepository->getAggregatedQuantitiesByUser($user);

        $sourceProduct = $this->buildSourceProductSummary(
            $sourceTypeId,
            $sourceMaterials,
            $outputPerRun,
            $activityId,
            $isT2,
            $isReaction,
            $solarSystemId,
            $brokerFeeRate,
            $salesTaxRate,
            $jitaPrices,
            $dailyVolumes,
            $typeNames,
            $groupNames,
            [], // no buildable materials
            $userStock,
        );

        return [
            'typeId' => $sourceTypeId,
            'sourceProduct' => $sourceProduct,
            'matrix' => [],
            'candidates' => [],
            'matrixProductIds' => [$sourceTypeId],
        ];
    }
}
