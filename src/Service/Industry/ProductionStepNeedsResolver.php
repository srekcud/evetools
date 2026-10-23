<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Exception\TypeMissingFromSdeException;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;

class ProductionStepNeedsResolver
{
    public function __construct(
        private readonly IndustryBlacklistService $blacklistService,
        private readonly IndustryCalculationService $calculationService,
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly InvTypeRepository $typeRepository,
    ) {
    }

    public function resolve(IndustryProject $project): ProductionStepNeeds
    {
        $stepsByProduct = ProductionStepGraph::productionStepsByProduct($project);
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

        $structureByStep = [];
        foreach ($productionSteps as $step) {
            $structureBonus = $this->calculationService->getStructureBonusForStep($step);
            $structureByStep[spl_object_id($step)] = ['materialBonus' => $structureBonus['materialBonus'], 'name' => $structureBonus['name']];
        }

        $blacklistedTypeIds = array_flip($this->blacklistService->resolveBlacklistedTypeIds($project->getUser()));
        $changeOfNeedByProduct = [];
        $needsByProduct = [];

        foreach (ProductionStepGraph::productsFromConsumersToMaterials($project, $stepsByProduct, $materialsByKey) as $productTypeId) {
            $jobs = $stepsByProduct[$productTypeId];
            usort($jobs, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());
            $isRoot = !\array_key_exists($productTypeId, $changeOfNeedByProduct);
            $changeOfNeed = $isRoot ? 0 : $changeOfNeedByProduct[$productTypeId];
            $stock = $isRoot ? 0 : self::inStockQuantityOf($jobs);
            $need = self::persistedNeedOf($jobs) + $changeOfNeed;
            $quantityToProvide = max(0, $need - $stock);
            $isFailed = isset($failedProducts[$productTypeId]);
            $isBlacklisted = isset($blacklistedTypeIds[$productTypeId]);
            $isPurchased = self::hasPurchasedJob($jobs);
            $isBought = !$isFailed && ($isBlacklisted || $isPurchased);

            $effectiveRunsOfJobs = match (true) {
                $isBought => array_fill(0, \count($jobs), 0),
                $isFailed, $changeOfNeed === 0 && $stock === 0 => array_map(static fn (IndustryProjectStep $job): int => $job->getRuns(), $jobs),
                default => SplitGroupQuantities::runsBySplitIndex(
                    (int) ceil($quantityToProvide / $productsByKey[ProductionStepGraph::blueprintKeyOf($jobs[0])]->getQuantity()),
                    \count($jobs),
                ),
            };

            foreach ($jobs as $index => $job) {
                $materialBonus = $structureByStep[spl_object_id($job)]['materialBonus'];

                foreach (ProductionStepGraph::stepMaterialsOf($job, $stepsByProduct, $materialsByKey) as $material) {
                    $materialTypeId = $material->getMaterialTypeId();
                    $changeOfNeedByProduct[$materialTypeId] = ($changeOfNeedByProduct[$materialTypeId] ?? 0)
                        + $this->materialQuantityOf($material, $job, $effectiveRunsOfJobs[$index], $materialBonus)
                        - $this->materialQuantityOf($material, $job, $job->getRuns(), $materialBonus);
                }
            }

            $needsByProduct[$productTypeId] = new StepProductNeed($productTypeId, $jobs, $need, $quantityToProvide, $isBlacklisted, $isPurchased, $isFailed, $effectiveRunsOfJobs);
        }

        return new ProductionStepNeeds($productionSteps, $stepsByProduct, $materialsByKey, $productsByKey, $typeNames, $failedProducts, $needsByProduct, $structureByStep);
    }

    /**
     * @param array{total: float, base: float, rig: float} $materialBonus
     */
    public function materialQuantityOf(IndustryActivityMaterial $material, IndustryProjectStep $job, int $runs, array $materialBonus): int
    {
        return $this->calculationService->calculateMaterialQuantity($material->getQuantity(), $runs, $job->getMeLevel(), $materialBonus['base'], $materialBonus['rig']);
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
