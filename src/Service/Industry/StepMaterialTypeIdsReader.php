<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Exception\StepMaterialsMissingFromSdeException;
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

        $this->readMissingMaterialsOfProjectOf($step);

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
        $stepBlueprintKey = ProductionStepGraph::blueprintKeyOf($step);
        if (!isset($this->materialTypeIdsByBlueprintKey[$stepBlueprintKey])) {
            $missingSteps[$stepBlueprintKey] = $step;
        }

        if ($missingSteps === []) {
            return;
        }

        $materialsByKey = $this->materialRepository->findMaterialEntitiesForBlueprints(
            array_values(array_map(static fn (IndustryProjectStep $missingStep): int => $missingStep->getBlueprintTypeId(), $missingSteps)),
            array_values(array_map(static fn (IndustryProjectStep $missingStep): int => SplitGroupMaterials::activityIdOf($missingStep->getActivityType()), $missingSteps)),
        );

        $blueprintKeysAbsentFromSde = [];
        foreach (array_keys($missingSteps) as $blueprintKey) {
            if (!isset($materialsByKey[$blueprintKey])) {
                $blueprintKeysAbsentFromSde[] = $blueprintKey;

                continue;
            }

            $this->materialTypeIdsByBlueprintKey[$blueprintKey] = array_map(
                static fn (IndustryActivityMaterial $material): int => $material->getMaterialTypeId(),
                $materialsByKey[$blueprintKey],
            );
        }

        if ($blueprintKeysAbsentFromSde !== []) {
            throw new StepMaterialsMissingFromSdeException($blueprintKeysAbsentFromSde[0]);
        }
    }
}
