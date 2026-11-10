<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\User;
use App\Industry\Application\ProductionStructure;
use App\Industry\Domain\ActivityKind;
use App\Enum\IndustryActivityType;
use App\Repository\CachedAssetRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\SalesFees\SalesFees;
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
        private readonly FacilityTaxResolver $facilityTaxResolver,
    ) {
    }

    /**
     * Analyze pivot options for a source product.
     *
     * @return array{
     *     typeId: int,
     *     sourceProduct: array<string, mixed>,
     *     matrix: list<array<string, mixed>>,
     *     candidates: list<array{typeId: int, typeName: string|null, groupName: string, marginPercent: float|null, profitPerUnit: float|null, dailyVolume: float|null, coveragePercent: float|null, missingComponents: list<array{typeId: int, typeName: string|null, quantity: int, cost: float|null}>, additionalCost: float|null, estimatedProfit: float|null, score: float|null, unknownReason: string|null, missingPriceTypeIds: list<int>, priceSources: array{materials: array{market: string, side: string, method: string, coveragePercent: ?float}, sale: array{market: string, side: string, method: string, coveragePercent: ?float}|null, assumptions: array{runs: int, materialEfficiency: int}}}>,
     *     matrixProductIds: list<int>,
     * }
     */
    public function analyze(
        int $sourceTypeId,
        int $runs,
        int $solarSystemId,
        ?SalesFees $salesFees,
        User $user,
        int $reactionSolarSystemId,
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
        $solarSystemIdByActivity = ['manufacturing' => $solarSystemId, 'reaction' => $reactionSolarSystemId];
        $structureByActivity = [
            'manufacturing' => $this->facilityTaxResolver->structureFor($user, $solarSystemId, ActivityKind::Manufacturing),
            'reaction' => $this->facilityTaxResolver->structureFor($user, $reactionSolarSystemId, ActivityKind::Reaction),
        ];
        $facilityTaxByActivity = array_map(FacilityTaxResolver::percentOf(...), $structureByActivity);

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
            return $this->buildResultWithSourceOnly($sourceTypeId, $sourceMaterials, $sourceOutputPerRun, $sourceActivityId, $solarSystemIdByActivity, $salesFees, $user, $facilityTaxByActivity, $structureByActivity);
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
            return $this->buildResultWithSourceOnly($sourceTypeId, $sourceMaterials, $sourceOutputPerRun, $sourceActivityId, $solarSystemIdByActivity, $salesFees, $user, $facilityTaxByActivity, $structureByActivity);
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

        $productTypeIds = array_merge([$sourceTypeId], array_map(fn (array $p) => $p['productTypeId'], $candidateProducts));
        $dailyVolumes = $this->jitaMarketService->getCachedDailyVolumes($productTypeIds);

        // 8. Identify T2 products
        $t2ProductIds = $this->inventionService->identifyT2Products($candidateProducts);

        // Also check if source is T2
        $sourceIsT2 = $this->inventionService->isT2($sourceTypeId);
        $sourceIsReaction = $sourceActivityId === IndustryActivityType::Reaction->value;

        // Build buildable material type ID set for quick lookup
        $buildableSet = array_flip($buildableMaterialTypeIds);
        $sourceMaterialEfficiency = $this->materialEfficiencyOf($sourceIsT2, $sourceIsReaction);
        $quoteRequests = $this->productQuoteRequests($sourceTypeId, $sourceOutputPerRun, $sourceMaterials, $sourceMaterialEfficiency);
        foreach ($candidateProducts as $cp) {
            $cpMaterialEfficiency = $this->materialEfficiencyOf(isset($t2ProductIds[$cp['productTypeId']]), $cp['activityId'] === IndustryActivityType::Reaction->value);
            $cpMaterials = $candidateMaterialsByBp[$cp['blueprintTypeId']] ?? [];
            $quoteRequests = array_merge(
                $quoteRequests,
                $this->productQuoteRequests($cp['productTypeId'], $cp['outputPerRun'], $cpMaterials, $cpMaterialEfficiency),
                $this->stockNeedQuoteRequests($this->stockNeedsOf($cpMaterials, $cpMaterialEfficiency, $runs, $buildableSet, $userStock)),
            );
        }
        $quotes = WeightedSellQuotes::read($this->jitaMarketService, $quoteRequests);

        // 9. Resolve type names and group names
        $typeNames = $this->typeNameResolver->findNames($allTypeIds);
        $groupNames = $this->loadGroupNames(array_merge([$sourceTypeId], array_map(fn (array $p) => $p['productTypeId'], $candidateProducts)));

        // 10. Score each candidate (1 run)
        $scoredCandidates = [];
        foreach ($candidateProducts as $cp) {
            $cpTypeId = $cp['productTypeId'];
            $cpBpId = $cp['blueprintTypeId'];
            $cpOutputPerRun = $cp['outputPerRun'];
            $cpActivityId = $cp['activityId'];
            $cpIsT2 = isset($t2ProductIds[$cpTypeId]);
            $cpIsReaction = $cpActivityId === IndustryActivityType::Reaction->value;

            $me = $this->materialEfficiencyOf($cpIsT2, $cpIsReaction);
            $activityType = $cpIsReaction ? 'reaction' : 'manufacturing';

            $cpMaterials = $candidateMaterialsByBp[$cpBpId] ?? [];

            $materials = $this->pricedMaterialsPerRun($cpMaterials, $me, $quotes);
            $materialCost = $materials['cost'];

            $jobInstallCost = $this->esiCostIndexService->estimateJobInstallCost($cpMaterials, 1, $solarSystemIdByActivity[$activityType], $activityType, $facilityTaxByActivity[$activityType], $structureByActivity[$activityType]);
            $totalCostWithInvention = $this->totalCostWithInvention(
                $this->totalCostPerRun($materialCost, $jobInstallCost),
                $cpIsT2,
                $cpTypeId,
                $solarSystemIdByActivity['manufacturing'],
                $facilityTaxByActivity['manufacturing'],
                $structureByActivity['manufacturing'],
            );
            $totalCost = $totalCostWithInvention['totalCost'];

            // Sell price
            $sellPrice = $quotes->unitPriceOf($cpTypeId, $cpOutputPerRun);
            if ($sellPrice === null || $sellPrice <= 0.0 || ($totalCost !== null && $totalCost <= 0.0)) {
                continue;
            }

            $marginPercent = null;
            $profitPerUnit = null;
            if ($totalCost !== null && $salesFees !== null) {
                $fees = $sellPrice * ($salesFees->brokerFeeRate + $salesFees->salesTaxRate);
                $netSellPrice = $sellPrice - $fees;
                $marginPercent = ($netSellPrice * $cpOutputPerRun - $totalCost) / $totalCost * 100;
                $profitPerUnit = ($netSellPrice * $cpOutputPerRun - $totalCost) / $cpOutputPerRun;
            }
            $dailyVolume = $dailyVolumes[$cpTypeId] ?? null;

            // Coverage (value-weighted): how much of the candidate's buildable materials
            // overlap with the source product's buildable materials, weighted by stock
            $coverageNumerator = 0.0;
            $coverageDenominator = 0.0;
            $missingComponents = [];
            $additionalCost = 0.0;
            $isCoverageUnknown = false;
            $isAdditionalCostUnknown = false;
            $stockNeedPartialPercents = [];

            foreach ($this->stockNeedsOf($cpMaterials, $me, $runs, $buildableSet, $userStock) as $need) {
                $needUnitPrice = $quotes->unitPriceOf($need['typeId'], $need['neededQuantity']);

                if ($needUnitPrice === null) {
                    $isCoverageUnknown = true;
                } else {
                    $coverageDenominator += $need['neededQuantity'] * $needUnitPrice;
                    $coverageNumerator += min($need['inStock'], $need['neededQuantity']) * $needUnitPrice;
                    $stockNeedPartialPercents[] = PriceSource::partialPercentOf($quotes->coverageOf($need['typeId'], $need['neededQuantity']));
                }

                if ($need['missingQuantity'] > 0) {
                    $missingUnitPrice = $quotes->unitPriceOf($need['typeId'], $need['missingQuantity']);
                    $missingCost = null;
                    if ($missingUnitPrice === null) {
                        $isAdditionalCostUnknown = true;
                    } else {
                        $missingCost = round($need['missingQuantity'] * $missingUnitPrice, 2);
                        $additionalCost += $need['missingQuantity'] * $missingUnitPrice;
                        $stockNeedPartialPercents[] = PriceSource::partialPercentOf($quotes->coverageOf($need['typeId'], $need['missingQuantity']));
                    }
                    $missingComponents[] = [
                        'typeId' => $need['typeId'],
                        'typeName' => $typeNames[$need['typeId']] ?? null,
                        'quantity' => $need['missingQuantity'],
                        'cost' => $missingCost,
                    ];
                }
            }

            $coveragePercent = $coverageDenominator > 0
                ? ($coverageNumerator / $coverageDenominator) * 100
                : 0.0;

            $score = null;
            if ($marginPercent !== null && !$isCoverageUnknown && $dailyVolume !== null) {
                $score = $coveragePercent * $marginPercent * log10($dailyVolume + 1);
            }

            $scoredCandidates[] = [
                'typeId' => $cpTypeId,
                'typeName' => $typeNames[$cpTypeId] ?? null,
                'groupName' => $groupNames[$cpTypeId] ?? '',
                'marginPercent' => $marginPercent !== null ? round($marginPercent, 2) : null,
                'profitPerUnit' => $profitPerUnit !== null ? round($profitPerUnit, 2) : null,
                'dailyVolume' => $dailyVolume,
                'coveragePercent' => $isCoverageUnknown ? null : round($coveragePercent, 2),
                'missingComponents' => $missingComponents,
                'additionalCost' => $isCoverageUnknown || $isAdditionalCostUnknown ? null : round($additionalCost, 2),
                'estimatedProfit' => $profitPerUnit !== null ? round($profitPerUnit * $cpOutputPerRun * $runs, 2) : null,
                'score' => $score !== null ? round($score, 2) : null,
                'blueprintTypeId' => $cpBpId,
                'unknownReason' => $this->unknownCostReason($materialCost, $jobInstallCost) ?? $totalCostWithInvention['unknownReason'] ?? $this->unknownDailyVolumeReason($dailyVolume),
                'missingPriceTypeIds' => $materials['missingPriceTypeIds'],
                'priceSources' => $this->priceSourcesOf(
                    array_merge($materials['partialCoveragePercents'], $stockNeedPartialPercents),
                    $quotes,
                    $cpTypeId,
                    $cpOutputPerRun,
                    $me,
                ),
            ];
        }

        usort($scoredCandidates, static fn (array $a, array $b): int => match (true) {
            $a['score'] !== null && $b['score'] !== null => $b['score'] <=> $a['score'],
            $a['score'] === null && $b['score'] === null => $a['typeId'] <=> $b['typeId'],
            default => $a['score'] === null ? 1 : -1,
        });
        $topCandidates = \array_slice($scoredCandidates, 0, self::MAX_CANDIDATES);

        // 11. Build source product summary
        $sourceProduct = $this->buildSourceProductSummary(
            $sourceTypeId,
            $sourceMaterials,
            $sourceOutputPerRun,
            $sourceActivityId,
            $sourceIsT2,
            $sourceIsReaction,
            $solarSystemIdByActivity,
            $salesFees,
            $quotes,
            $dailyVolumes,
            $typeNames,
            $groupNames,
            $buildableMaterials,
            $userStock,
            $facilityTaxByActivity,
            $structureByActivity,
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

        $materialEfficiencyByProduct = [$sourceTypeId => $sourceMaterialEfficiency];
        foreach ($candidateProducts as $cp) {
            $materialEfficiencyByProduct[$cp['productTypeId']] = $this->materialEfficiencyOf(
                isset($t2ProductIds[$cp['productTypeId']]),
                $cp['activityId'] === IndustryActivityType::Reaction->value,
            );
        }

        $matrix = [];
        foreach ($buildableMaterials as $mat) {
            $matTypeId = $mat['materialTypeId'];
            $inStock = $userStock[$matTypeId] ?? 0;

            $candidates = [];
            foreach ($matrixProductIds as $productTypeId) {
                $baseQuantity = $productTypeId === $sourceTypeId
                    ? ($sourceMaterialSet[$matTypeId] ?? 0)
                    : ($candidateMaterialsByProduct[$productTypeId][$matTypeId] ?? 0);
                $needed = $baseQuantity === 0
                    ? 0
                    : MaterialQuantityFormula::forJob($baseQuantity, $runs, $materialEfficiencyByProduct[$productTypeId], 0.0, 0.0);

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
                'typeName' => $typeNames[$matTypeId] ?? null,
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
     * @param array<int, float> $dailyVolumes
     * @param array<int, string> $typeNames
     * @param array<int, string> $groupNames
     * @param array<int, int> $userStock
     * @param array<string, int> $solarSystemIdByActivity
     * @param array<string, float> $facilityTaxByActivity
     * @param array<string, ?ProductionStructure> $structureByActivity
     * @return array<string, mixed>
     */
    private function buildSourceProductSummary(
        int $sourceTypeId,
        array $sourceMaterials,
        int $outputPerRun,
        int $activityId,
        bool $isT2,
        bool $isReaction,
        array $solarSystemIdByActivity,
        ?SalesFees $salesFees,
        WeightedSellQuotes $quotes,
        array $dailyVolumes,
        array $typeNames,
        array $groupNames,
        array $buildableMaterials,
        array $userStock,
        array $facilityTaxByActivity,
        array $structureByActivity,
    ): array {
        $me = $this->materialEfficiencyOf($isT2, $isReaction);
        $activityType = $isReaction ? 'reaction' : 'manufacturing';

        $materials = $this->pricedMaterialsPerRun($sourceMaterials, $me, $quotes);
        $materialCost = $materials['cost'];

        $jobInstallCost = $this->esiCostIndexService->estimateJobInstallCost($sourceMaterials, 1, $solarSystemIdByActivity[$activityType], $activityType, $facilityTaxByActivity[$activityType], $structureByActivity[$activityType]);
        $totalCostWithInvention = $this->totalCostWithInvention(
            $this->totalCostPerRun($materialCost, $jobInstallCost),
            $isT2,
            $sourceTypeId,
            $solarSystemIdByActivity[$activityType],
            $facilityTaxByActivity[$activityType],
            $structureByActivity[$activityType],
        );
        $totalCost = $totalCostWithInvention['totalCost'];

        $sellPrice = $quotes->unitPriceOf($sourceTypeId, $outputPerRun);
        $dailyVolume = $dailyVolumes[$sourceTypeId] ?? null;
        $marginPercent = null;
        if ($salesFees !== null && $totalCost !== null && $sellPrice !== null && $sellPrice > 0 && $totalCost > 0) {
            $fees = $sellPrice * ($salesFees->brokerFeeRate + $salesFees->salesTaxRate);
            $netSellPrice = $sellPrice - $fees;
            $marginPercent = round(($netSellPrice * $outputPerRun - $totalCost) / $totalCost * 100, 2);
        }

        // Key components: buildable materials with stock info
        $keyComponents = [];
        foreach ($buildableMaterials as $mat) {
            $matTypeId = $mat['materialTypeId'];
            $keyComponents[] = [
                'typeId' => $matTypeId,
                'typeName' => $typeNames[$matTypeId] ?? null,
                'inStock' => $userStock[$matTypeId] ?? 0,
            ];
        }

        return [
            'typeId' => $sourceTypeId,
            'typeName' => $typeNames[$sourceTypeId] ?? null,
            'groupName' => $groupNames[$sourceTypeId] ?? '',
            'marginPercent' => $marginPercent,
            'sellPrice' => $sellPrice !== null ? round($sellPrice, 2) : null,
            'dailyVolume' => $dailyVolume,
            'keyComponents' => $keyComponents,
            'unknownReason' => $this->unknownCostReason($materialCost, $jobInstallCost) ?? $totalCostWithInvention['unknownReason'] ?? $this->unknownDailyVolumeReason($dailyVolume),
            'missingPriceTypeIds' => $materials['missingPriceTypeIds'],
            'priceSources' => $this->priceSourcesOf($materials['partialCoveragePercents'], $quotes, $sourceTypeId, $outputPerRun, $me),
        ];
    }

    /**
     * @param list<array{materialTypeId: int, quantity: int}> $materials
     * @return list<array{typeId: int, quantity: int}>
     */
    private function productQuoteRequests(int $productTypeId, int $outputPerRun, array $materials, int $materialEfficiency): array
    {
        $requests = [['typeId' => $productTypeId, 'quantity' => $outputPerRun]];
        foreach ($materials as $mat) {
            $requests[] = ['typeId' => $mat['materialTypeId'], 'quantity' => MaterialQuantityFormula::forJob($mat['quantity'], 1, $materialEfficiency, 0.0, 0.0)];
        }

        return $requests;
    }

    /**
     * @param list<array{materialTypeId: int, quantity: int}> $materials
     * @param array<int, int> $buildableSet
     * @param array<int, int> $userStock
     * @return list<array{typeId: int, neededQuantity: int, inStock: int, missingQuantity: int}>
     */
    private function stockNeedsOf(array $materials, int $materialEfficiency, int $runs, array $buildableSet, array $userStock): array
    {
        $needs = [];
        foreach ($materials as $mat) {
            $typeId = $mat['materialTypeId'];
            if (!isset($buildableSet[$typeId])) {
                continue;
            }
            $neededQuantity = MaterialQuantityFormula::forJob($mat['quantity'], $runs, $materialEfficiency, 0.0, 0.0);
            $inStock = $userStock[$typeId] ?? 0;
            $needs[] = ['typeId' => $typeId, 'neededQuantity' => $neededQuantity, 'inStock' => $inStock, 'missingQuantity' => max(0, $neededQuantity - $inStock)];
        }

        return $needs;
    }

    /**
     * @param list<array{typeId: int, neededQuantity: int, inStock: int, missingQuantity: int}> $needs
     * @return list<array{typeId: int, quantity: int}>
     */
    private function stockNeedQuoteRequests(array $needs): array
    {
        $requests = [];
        foreach ($needs as $need) {
            $requests[] = ['typeId' => $need['typeId'], 'quantity' => $need['neededQuantity']];
            if ($need['missingQuantity'] > 0) {
                $requests[] = ['typeId' => $need['typeId'], 'quantity' => $need['missingQuantity']];
            }
        }

        return $requests;
    }

    /**
     * @param list<?float> $materialPartialPercents
     * @return array{materials: array{market: string, side: string, method: string, coveragePercent: ?float}, sale: array{market: string, side: string, method: string, coveragePercent: ?float}|null, assumptions: array{runs: int, materialEfficiency: int}}
     */
    private function priceSourcesOf(array $materialPartialPercents, WeightedSellQuotes $quotes, int $productTypeId, int $outputPerRun, int $materialEfficiency): array
    {
        $partialPercents = array_filter($materialPartialPercents, static fn (?float $percent): bool => $percent !== null);
        $isSaleKnown = $quotes->unitPriceOf($productTypeId, $outputPerRun) !== null;

        return [
            'materials' => PriceSource::jitaSellWeighted($partialPercents === [] ? null : min($partialPercents))->toArray(),
            'sale' => $isSaleKnown ? PriceSource::jitaSellWeighted(PriceSource::partialPercentOf($quotes->coverageOf($productTypeId, $outputPerRun)))->toArray() : null,
            'assumptions' => ['runs' => 1, 'materialEfficiency' => $materialEfficiency],
        ];
    }

    private function materialEfficiencyOf(bool $isT2, bool $isReaction): int
    {
        if ($isReaction) {
            return 0;
        }

        return $isT2 ? InventionService::BASE_INVENTION_ME : 10;
    }

    /**
     * @return array{totalCost: ?float, unknownReason: ?string}
     */
    private function totalCostWithInvention(?float $totalCost, bool $isT2, int $typeId, int $solarSystemId, float $facilityTaxPercent, ?ProductionStructure $structure): array
    {
        if (!$isT2 || $totalCost === null) {
            return ['totalCost' => $totalCost, 'unknownReason' => null];
        }

        $inventionCost = $this->inventionService->expectedCostPerRun($typeId, $solarSystemId, $facilityTaxPercent, $structure);

        return [
            'totalCost' => $inventionCost['cost'] === null ? null : $totalCost + $inventionCost['cost'],
            'unknownReason' => $inventionCost['unknownReason'],
        ];
    }

    /**
     * @param array{cost: float|null, unknownReason: string|null, missingAdjustedPriceTypeIds: list<int>} $jobInstallCost
     */
    private function totalCostPerRun(?float $materialCost, array $jobInstallCost): ?float
    {
        if ($materialCost === null || $jobInstallCost['cost'] === null) {
            return null;
        }

        return $materialCost + $jobInstallCost['cost'];
    }

    /**
     * @param array{cost: float|null, unknownReason: string|null, missingAdjustedPriceTypeIds: list<int>} $jobInstallCost
     */
    private function unknownCostReason(?float $materialCost, array $jobInstallCost): ?string
    {
        if ($materialCost === null) {
            return BatchProfitScannerService::UNKNOWN_REASON_MISSING_MATERIAL_PRICE;
        }

        return $jobInstallCost['unknownReason'];
    }

    private function unknownDailyVolumeReason(?float $dailyVolume): ?string
    {
        return $dailyVolume === null ? BatchProfitScannerService::UNKNOWN_REASON_MISSING_DAILY_VOLUME : null;
    }

    /**
     * @param list<array{materialTypeId: int, quantity: int}> $materials
     * @return array{cost: ?float, missingPriceTypeIds: list<int>, partialCoveragePercents: list<?float>}
     */
    private function pricedMaterialsPerRun(array $materials, int $materialEfficiency, WeightedSellQuotes $quotes): array
    {
        $cost = 0.0;
        $missingPriceTypeIds = [];
        $partialCoveragePercents = [];
        foreach ($materials as $mat) {
            $quantity = MaterialQuantityFormula::forJob($mat['quantity'], 1, $materialEfficiency, 0.0, 0.0);
            $unitPrice = $quotes->unitPriceOf($mat['materialTypeId'], $quantity);
            if ($unitPrice === null) {
                $missingPriceTypeIds[$mat['materialTypeId']] = $mat['materialTypeId'];
                continue;
            }
            $cost += $quantity * $unitPrice;
            $partialCoveragePercents[] = PriceSource::partialPercentOf($quotes->coverageOf($mat['materialTypeId'], $quantity));
        }
        sort($missingPriceTypeIds);

        return [
            'cost' => $missingPriceTypeIds === [] ? $cost : null,
            'missingPriceTypeIds' => $missingPriceTypeIds,
            'partialCoveragePercents' => $partialCoveragePercents,
        ];
    }

    /**
     * @return array{typeId: int, sourceProduct: array<string, mixed>, matrix: list<mixed>, candidates: list<mixed>, matrixProductIds: list<int>}
     */
    private function emptyResult(int $sourceTypeId): array
    {
        $typeName = $this->typeNameResolver->findName($sourceTypeId);

        return [
            'typeId' => $sourceTypeId,
            'sourceProduct' => [
                'typeId' => $sourceTypeId,
                'typeName' => $typeName,
                'groupName' => '',
                'marginPercent' => null,
                'sellPrice' => null,
                'dailyVolume' => null,
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
     * @param array<string, int> $solarSystemIdByActivity
     * @param array<string, float> $facilityTaxByActivity
     * @param array<string, ?ProductionStructure> $structureByActivity
     * @return array{typeId: int, sourceProduct: array<string, mixed>, matrix: list<mixed>, candidates: list<mixed>, matrixProductIds: list<int>}
     */
    private function buildResultWithSourceOnly(
        int $sourceTypeId,
        array $sourceMaterials,
        int $outputPerRun,
        int $activityId,
        array $solarSystemIdByActivity,
        ?SalesFees $salesFees,
        User $user,
        array $facilityTaxByActivity,
        array $structureByActivity,
    ): array {
        $isReaction = $activityId === IndustryActivityType::Reaction->value;
        $isT2 = $this->inventionService->isT2($sourceTypeId);

        $allTypeIds = [$sourceTypeId];
        foreach ($sourceMaterials as $mat) {
            $allTypeIds[] = $mat['materialTypeId'];
        }
        $allTypeIds = array_values(array_unique($allTypeIds));

        $quotes = WeightedSellQuotes::read(
            $this->jitaMarketService,
            $this->productQuoteRequests($sourceTypeId, $outputPerRun, $sourceMaterials, $this->materialEfficiencyOf($isT2, $isReaction)),
        );
        $dailyVolumes = $this->jitaMarketService->getCachedDailyVolumes([$sourceTypeId]);
        $typeNames = $this->typeNameResolver->findNames($allTypeIds);
        $groupNames = $this->loadGroupNames([$sourceTypeId]);
        $userStock = $this->assetRepository->getAggregatedQuantitiesByUser($user);

        $sourceProduct = $this->buildSourceProductSummary(
            $sourceTypeId,
            $sourceMaterials,
            $outputPerRun,
            $activityId,
            $isT2,
            $isReaction,
            $solarSystemIdByActivity,
            $salesFees,
            $quotes,
            $dailyVolumes,
            $typeNames,
            $groupNames,
            [], // no buildable materials
            $userStock,
            $facilityTaxByActivity,
            $structureByActivity,
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
