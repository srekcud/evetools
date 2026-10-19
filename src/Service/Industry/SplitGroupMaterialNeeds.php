<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Enum\IndustryActivityType;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Exception\BlueprintProductMissingFromSdeException;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;

final class SplitGroupMaterialNeeds
{
    public function __construct(
        private readonly IndustryCalculationService $calculationService,
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly IndustryActivityProductRepository $productRepository,
    ) {
    }

    /**
     * @param list<int> $jobRunsBefore
     * @param list<int> $jobRunsAfter
     * @return list<IndustryProjectStep>
     */
    public function applyChangeOfJobs(IndustryProject $project, IndustryProjectStep $consumer, array $jobRunsBefore, array $jobRunsAfter): array
    {
        if ($consumer->getActivityType() === 'copy') {
            return [];
        }

        $materialStepsByProduct = $this->materialStepsByProduct($project);
        $materialBonus = $this->calculationService->getStructureBonusForStep($consumer)['materialBonus'];

        $newNeeds = [];
        foreach ($this->materialsOf($consumer) as $material) {
            $materialSteps = $materialStepsByProduct[$material->getMaterialTypeId()] ?? null;
            if ($materialSteps === null) {
                continue;
            }

            $changeOfNeed = $this->materialsOfJobs($material->getQuantity(), $jobRunsAfter, $consumer->getMeLevel(), $materialBonus)
                - $this->materialsOfJobs($material->getQuantity(), $jobRunsBefore, $consumer->getMeLevel(), $materialBonus);
            if ($changeOfNeed === 0) {
                continue;
            }

            $newNeeds[] = [
                'steps' => $materialSteps,
                'need' => array_sum(array_map(static fn (IndustryProjectStep $step): int => $step->getQuantity(), $materialSteps)) + $changeOfNeed,
                'outputPerRun' => $this->outputPerRunOf($materialSteps[0]),
            ];
        }

        $changedSteps = [];
        foreach ($newNeeds as $newNeed) {
            array_push($changedSteps, ...SplitGroupQuantities::applyNeedToSteps($newNeed['steps'], $newNeed['need'], $newNeed['outputPerRun']));
        }

        return $changedSteps;
    }

    /**
     * @return array<int, list<IndustryProjectStep>>
     */
    private function materialStepsByProduct(IndustryProject $project): array
    {
        $stepsByProduct = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getActivityType() !== 'copy') {
                $stepsByProduct[$step->getProductTypeId()][] = $step;
            }
        }

        return $stepsByProduct;
    }

    /**
     * @return list<IndustryActivityMaterial>
     */
    private function materialsOf(IndustryProjectStep $consumer): array
    {
        $activityId = self::activityIdOf($consumer);
        $materialsByBlueprintAndActivity = $this->materialRepository->findMaterialEntitiesForBlueprints([$consumer->getBlueprintTypeId()], [$activityId]);

        return $materialsByBlueprintAndActivity[$consumer->getBlueprintTypeId().'-'.$activityId] ?? [];
    }

    /**
     * @param list<int> $jobRuns
     * @param array{total: float, base: float, rig: float} $materialBonus
     */
    private function materialsOfJobs(int $baseQuantityPerRun, array $jobRuns, int $meLevel, array $materialBonus): int
    {
        return array_sum(array_map(
            fn (int $runs): int => $this->calculationService->calculateMaterialQuantity($baseQuantityPerRun, $runs, $meLevel, $materialBonus['base'], $materialBonus['rig']),
            $jobRuns,
        ));
    }

    private function outputPerRunOf(IndustryProjectStep $materialStep): int
    {
        $product = $this->productRepository->findOneBy([
            'typeId' => $materialStep->getBlueprintTypeId(),
            'activityId' => self::activityIdOf($materialStep),
        ]);

        if ($product === null) {
            throw new BlueprintProductMissingFromSdeException($materialStep->getBlueprintTypeId());
        }

        return $product->getQuantity();
    }

    private static function activityIdOf(IndustryProjectStep $step): int
    {
        return $step->getActivityType() === 'reaction'
            ? IndustryActivityType::Reaction->value
            : IndustryActivityType::Manufacturing->value;
    }
}
