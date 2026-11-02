<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Repository\Sde\IndustryActivityMaterialRepository;

class StepMaterialTypeIdsReader
{
    /** @var array<string, list<int>> */
    private array $materialTypeIdsByBlueprintKey = [];

    public function __construct(
        private readonly IndustryActivityMaterialRepository $materialRepository,
    ) {
    }

    /**
     * @return list<int>
     */
    public function materialTypeIdsOf(IndustryProjectStep $step): array
    {
        $blueprintKey = ProductionStepGraph::blueprintKeyOf($step);

        if (!isset($this->materialTypeIdsByBlueprintKey[$blueprintKey])) {
            $this->readMissingMaterialsOfProjectOf($step);
        }

        return $this->materialTypeIdsByBlueprintKey[$blueprintKey];
    }

    private function readMissingMaterialsOfProjectOf(IndustryProjectStep $step): void
    {
        $missingSteps = [];
        foreach (ProductionStepGraph::productionStepsByProduct($step->getProject()) as $productionSteps) {
            foreach ($productionSteps as $productionStep) {
                $blueprintKey = ProductionStepGraph::blueprintKeyOf($productionStep);
                if (!isset($this->materialTypeIdsByBlueprintKey[$blueprintKey])) {
                    $missingSteps[$blueprintKey] = $productionStep;
                }
            }
        }
        $missingSteps[ProductionStepGraph::blueprintKeyOf($step)] = $step;

        $materialsByKey = $this->materialRepository->findMaterialEntitiesForBlueprints(
            array_values(array_map(static fn (IndustryProjectStep $missingStep): int => $missingStep->getBlueprintTypeId(), $missingSteps)),
            array_values(array_map(static fn (IndustryProjectStep $missingStep): int => SplitGroupMaterials::activityIdOf($missingStep->getActivityType()), $missingSteps)),
        );

        foreach (array_keys($missingSteps) as $blueprintKey) {
            $this->materialTypeIdsByBlueprintKey[$blueprintKey] = array_map(
                static fn (IndustryActivityMaterial $material): int => $material->getMaterialTypeId(),
                $materialsByKey[$blueprintKey] ?? [],
            );
        }
    }
}
