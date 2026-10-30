<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use Doctrine\ORM\EntityManagerInterface;

class IndustryStepCalculator
{
    public function __construct(
        private readonly IndustryCalculationService $calculationService,
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return IndustryProjectStep[] Steps whose quantity/runs changed
     */
    public function recalculateStepQuantities(IndustryProject $project): array
    {
        $updatedSteps = [];
        $this->walkNeedsFromConsumersToMaterials($project, static function (array $steps, int $need, int $outputPerRun) use ($project, &$updatedSteps): array {
            $appliedNeed = SplitGroupQuantities::applyNeedToSteps($steps, $need, $outputPerRun);
            array_push($updatedSteps, ...$appliedNeed->changedSteps);
            foreach ($appliedNeed->surplusJobs as $surplusJob) {
                $project->removeStep($surplusJob);
            }

            return $appliedNeed->surplusJobs;
        });

        if ($updatedSteps !== []) {
            $this->entityManager->flush();
        }

        return $updatedSteps;
    }

    public function needRunsOf(IndustryProject $project, IndustryProjectStep $step): ?int
    {
        $needRuns = null;
        $this->walkNeedsFromConsumersToMaterials($project, static function (array $steps, int $need, int $outputPerRun) use ($step, &$needRuns): array {
            if (\in_array($step, $steps, true)) {
                $needRuns = SplitGroupQuantities::runsOfNeed($need, $outputPerRun);
            }

            return [];
        });

        return $needRuns;
    }

    /**
     * @param callable(list<IndustryProjectStep>, int, int): list<IndustryProjectStep> $onNeedOfSteps
     */
    private function walkNeedsFromConsumersToMaterials(IndustryProject $project, callable $onNeedOfSteps): void
    {
        $stepsByProduct = ProductionStepGraph::productionStepsByProduct($project);
        $productionSteps = array_merge(...$stepsByProduct);
        $blueprintTypeIds = array_map(static fn (IndustryProjectStep $step): int => $step->getBlueprintTypeId(), $productionSteps);
        $activityIds = array_map(static fn (IndustryProjectStep $step): int => SplitGroupMaterials::activityIdOf($step->getActivityType()), $productionSteps);
        $materialsByKey = $this->materialRepository->findMaterialEntitiesForBlueprints($blueprintTypeIds, $activityIds);
        $productsByKey = $this->productRepository->findProductsForBlueprints($blueprintTypeIds, $activityIds);

        $needByProduct = [];

        foreach (ProductionStepGraph::productsFromConsumersToMaterials($project, $stepsByProduct, $materialsByKey) as $productTypeId) {
            if (isset($needByProduct[$productTypeId])) {
                $surplusJobs = $onNeedOfSteps(
                    $stepsByProduct[$productTypeId],
                    $needByProduct[$productTypeId],
                    SplitGroupMaterials::outputPerRunOfProduct(
                        $productsByKey[ProductionStepGraph::blueprintKeyOf($stepsByProduct[$productTypeId][0])] ?? null,
                        $stepsByProduct[$productTypeId][0]->getBlueprintTypeId(),
                    ),
                );
                $stepsByProduct[$productTypeId] = array_values(array_filter(
                    $stepsByProduct[$productTypeId],
                    static fn (IndustryProjectStep $step): bool => !\in_array($step, $surplusJobs, true),
                ));
            }

            foreach ($stepsByProduct[$productTypeId] as $consumer) {
                foreach (ProductionStepGraph::stepMaterialsOf($consumer, $stepsByProduct, $materialsByKey) as $material) {
                    $materialTypeId = $material->getMaterialTypeId();
                    $needByProduct[$materialTypeId] = ($needByProduct[$materialTypeId] ?? 0) + $this->needOfConsumer($consumer, $material);
                }
            }
        }
    }

    private function needOfConsumer(IndustryProjectStep $consumer, IndustryActivityMaterial $material): int
    {
        $materialBonus = $this->calculationService->getStructureBonusForStep($consumer)['materialBonus'];

        return $this->calculationService->calculateMaterialQuantity(
            $material->getQuantity(),
            $consumer->getRuns(),
            $consumer->getMeLevel(),
            $materialBonus['base'],
            $materialBonus['rig'],
        );
    }
}
