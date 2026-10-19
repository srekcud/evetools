<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityMaterial;
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

            $changeOfNeed = SplitGroupMaterials::materialsOfJobs($this->calculationService, $material->getQuantity(), $jobRunsAfter, $consumer->getMeLevel(), $materialBonus)
                - SplitGroupMaterials::materialsOfJobs($this->calculationService, $material->getQuantity(), $jobRunsBefore, $consumer->getMeLevel(), $materialBonus);
            if ($changeOfNeed === 0) {
                continue;
            }

            $newNeeds[] = [
                'steps' => $materialSteps,
                'need' => array_sum(array_map(static fn (IndustryProjectStep $step): int => $step->getQuantity(), $materialSteps)) + $changeOfNeed,
                'outputPerRun' => SplitGroupMaterials::outputPerRunOf($this->productRepository, $materialSteps[0]->getBlueprintTypeId(), $materialSteps[0]->getActivityType()),
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
        $activityId = SplitGroupMaterials::activityIdOf($consumer->getActivityType());
        $materialsByBlueprintAndActivity = $this->materialRepository->findMaterialEntitiesForBlueprints([$consumer->getBlueprintTypeId()], [$activityId]);

        return $materialsByBlueprintAndActivity[$consumer->getBlueprintTypeId().'-'.$activityId] ?? [];
    }
}
