<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Exception\TypeMissingFromSdeException;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;

class IndustryShoppingListBuilder
{
    private const float BONUS_TOLERANCE = 0.001;

    public function __construct(
        private readonly IndustryBlacklistService $blacklistService,
        private readonly IndustryBonusService $bonusService,
        private readonly IndustryCalculationService $calculationService,
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly InvTypeRepository $typeRepository,
    ) {
    }

    /**
     * @return array{materials: list<array<string, mixed>>, failedProducts: list<array{typeId: int, reason: string}>}
     */
    public function getShoppingList(IndustryProject $project): array
    {
        $stepsByProduct = ProductionStepGraph::productionStepsByProduct($project);
        $user = $project->getUser();
        $productionSteps = array_merge(...$stepsByProduct);
        $blueprintTypeIds = array_map(static fn (IndustryProjectStep $step): int => $step->getBlueprintTypeId(), $productionSteps);
        $activityIds = array_map(static fn (IndustryProjectStep $step): int => SplitGroupMaterials::activityIdOf($step->getActivityType()), $productionSteps);
        $materialsByKey = $this->materialRepository->findMaterialEntitiesForBlueprints($blueprintTypeIds, $activityIds);
        $productsByKey = $this->productRepository->findProductsForBlueprints($blueprintTypeIds, $activityIds);

        $failedProducts = [];
        foreach ($stepsByProduct as $productTypeId => $steps) {
            if (array_any($steps, static fn (IndustryProjectStep $step): bool => !isset($productsByKey[ProductionStepGraph::blueprintKeyOf($step)]))) {
                $failedProducts[$productTypeId] = ['typeId' => $productTypeId, 'reason' => "No blueprint or reaction found for product type ID {$productTypeId}"];
                unset($stepsByProduct[$productTypeId]);
            }
        }

        $typeNames = $this->typeNamesOf($stepsByProduct, $materialsByKey);
        foreach ($stepsByProduct as $productTypeId => $steps) {
            $missingTypeIds = array_keys(array_diff_key(array_flip($this->typeIdsOfJobs($productTypeId, $steps, $materialsByKey)), $typeNames));
            if ($missingTypeIds !== []) {
                $failedProducts[$productTypeId] = ['typeId' => $productTypeId, 'reason' => (new TypeMissingFromSdeException($missingTypeIds))->getMessage()];
            }
        }
        ksort($failedProducts);

        $materialBonusByStep = [];
        $bestMaterialBonusByStep = [];
        $hasSuboptimalStructure = false;
        foreach ($productionSteps as $step) {
            $materialBonusByStep[spl_object_id($step)] = $this->calculationService->getStructureBonusForStep($step)['materialBonus'];
            $bestMaterialBonusByStep[spl_object_id($step)] = $this->bestMaterialBonusOf($user, $step);
            if (abs($materialBonusByStep[spl_object_id($step)]['total'] - $bestMaterialBonusByStep[spl_object_id($step)]['total']) > self::BONUS_TOLERANCE) {
                $hasSuboptimalStructure = true;
            }
        }

        $blacklistedTypeIds = array_flip($this->blacklistService->resolveBlacklistedTypeIds($user));
        $changeOfNeedByProduct = [];
        $bestQuantities = [];
        $actualQuantities = [];

        foreach (ProductionStepGraph::productsFromConsumersToMaterials($project, $stepsByProduct, $materialsByKey) as $productTypeId) {
            $jobs = $stepsByProduct[$productTypeId];
            usort($jobs, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());
            $isRoot = !\array_key_exists($productTypeId, $changeOfNeedByProduct);
            $changeOfNeed = $isRoot ? 0 : $changeOfNeedByProduct[$productTypeId];
            $stock = $isRoot ? 0 : self::inStockQuantityOf($jobs);
            $quantityToProvide = max(0, self::persistedNeedOf($jobs) + $changeOfNeed - $stock);
            $isFailed = isset($failedProducts[$productTypeId]);
            $isBought = !$isFailed && (isset($blacklistedTypeIds[$productTypeId]) || self::hasPurchasedJob($jobs));

            if ($isBought && $quantityToProvide > 0) {
                $bestQuantities[$productTypeId] = $quantityToProvide;
                $actualQuantities[$productTypeId] = $quantityToProvide;
            }

            $effectiveRunsOfJobs = match (true) {
                $isBought => array_fill(0, \count($jobs), 0),
                $isFailed, $changeOfNeed === 0 && $stock === 0 => array_map(static fn (IndustryProjectStep $job): int => $job->getRuns(), $jobs),
                default => SplitGroupQuantities::runsBySplitIndex(
                    (int) ceil($quantityToProvide / $this->outputPerRunOf($jobs[0], $productsByKey)),
                    \count($jobs),
                ),
            };

            foreach ($jobs as $index => $job) {
                $effectiveRuns = $effectiveRunsOfJobs[$index];
                $materialBonus = $materialBonusByStep[spl_object_id($job)];
                $bestMaterialBonus = $bestMaterialBonusByStep[spl_object_id($job)];

                foreach ($materialsByKey[ProductionStepGraph::blueprintKeyOf($job)] ?? [] as $material) {
                    $materialTypeId = $material->getMaterialTypeId();
                    if (isset($stepsByProduct[$materialTypeId])) {
                        $changeOfNeedByProduct[$materialTypeId] = ($changeOfNeedByProduct[$materialTypeId] ?? 0)
                            + $this->materialQuantityOf($material, $job, $effectiveRuns, $materialBonus)
                            - $this->materialQuantityOf($material, $job, $job->getRuns(), $materialBonus);
                        continue;
                    }

                    if ($isFailed || $effectiveRuns === 0) {
                        continue;
                    }

                    $bestQuantities[$materialTypeId] = ($bestQuantities[$materialTypeId] ?? 0) + $this->materialQuantityOf($material, $job, $effectiveRuns, $bestMaterialBonus);
                    $actualQuantities[$materialTypeId] = ($actualQuantities[$materialTypeId] ?? 0) + $this->materialQuantityOf($material, $job, $effectiveRuns, $materialBonus);
                }
            }
        }

        $materials = [];
        foreach ($bestQuantities as $typeId => $quantity) {
            $material = ['typeId' => $typeId, 'typeName' => $typeNames[$typeId], 'quantity' => $quantity];
            if ($hasSuboptimalStructure) {
                $material['extraQuantity'] = max(0, $actualQuantities[$typeId] - $quantity);
            }
            $materials[] = $material;
        }
        usort($materials, static fn (array $a, array $b): int => strcasecmp($a['typeName'], $b['typeName']));

        return ['materials' => $materials, 'failedProducts' => array_values($failedProducts)];
    }

    /**
     * @return array<int, int> typeId => quantity
     */
    public function getPurchasedQuantities(IndustryProject $project): array
    {
        $quantities = [];
        foreach ($project->getSteps() as $step) {
            foreach ($step->getPurchases() as $purchase) {
                $typeId = $purchase->getTypeId();
                $quantities[$typeId] = ($quantities[$typeId] ?? 0) + $purchase->getQuantity();
            }
        }

        return $quantities;
    }

    /**
     * @param array<int, list<IndustryProjectStep>> $stepsByProduct
     * @param array<string, list<IndustryActivityMaterial>> $materialsByKey
     * @return array<int, string>
     */
    private function typeNamesOf(array $stepsByProduct, array $materialsByKey): array
    {
        $typeIds = [];
        foreach ($stepsByProduct as $productTypeId => $steps) {
            array_push($typeIds, ...$this->typeIdsOfJobs($productTypeId, $steps, $materialsByKey));
        }

        $typeNames = [];
        foreach ($this->typeRepository->findByTypeIds($typeIds) as $typeId => $type) {
            $typeNames[$typeId] = $type->getTypeName();
        }

        return $typeNames;
    }

    /**
     * @param list<IndustryProjectStep> $jobs
     * @param array<string, list<IndustryActivityMaterial>> $materialsByKey
     * @return list<int>
     */
    private function typeIdsOfJobs(int $productTypeId, array $jobs, array $materialsByKey): array
    {
        $typeIds = [$productTypeId];
        foreach ($jobs as $job) {
            foreach ($materialsByKey[ProductionStepGraph::blueprintKeyOf($job)] ?? [] as $material) {
                $typeIds[] = $material->getMaterialTypeId();
            }
        }

        return $typeIds;
    }

    /**
     * @return array{total: float, base: float, rig: float}
     */
    private function bestMaterialBonusOf(User $user, IndustryProjectStep $step): array
    {
        return $this->bonusService->findBestStructureForProduct($user, $step->getProductTypeId(), $step->getActivityType() === 'reaction')['bonus'];
    }

    /**
     * @param array{total: float, base: float, rig: float} $materialBonus
     */
    private function materialQuantityOf(IndustryActivityMaterial $material, IndustryProjectStep $job, int $runs, array $materialBonus): int
    {
        return $this->calculationService->calculateMaterialQuantity($material->getQuantity(), $runs, $job->getMeLevel(), $materialBonus['base'], $materialBonus['rig']);
    }

    /**
     * @param array<string, IndustryActivityProduct> $productsByKey
     */
    private function outputPerRunOf(IndustryProjectStep $job, array $productsByKey): int
    {
        return $productsByKey[ProductionStepGraph::blueprintKeyOf($job)]->getQuantity();
    }

    /**
     * @param list<IndustryProjectStep> $jobs
     */
    private static function persistedNeedOf(array $jobs): int
    {
        return array_sum(array_map(static fn (IndustryProjectStep $job): int => $job->getQuantity(), $jobs));
    }

    /**
     * @param list<IndustryProjectStep> $jobs
     */
    private static function inStockQuantityOf(array $jobs): int
    {
        return array_sum(array_map(static fn (IndustryProjectStep $job): int => $job->getInStockQuantity(), $jobs));
    }

    /**
     * @param list<IndustryProjectStep> $jobs
     */
    private static function hasPurchasedJob(array $jobs): bool
    {
        return array_any($jobs, static fn (IndustryProjectStep $job): bool => $job->isPurchased());
    }
}
