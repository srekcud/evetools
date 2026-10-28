<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\JobMaterialModifiers;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Recipe;

/**
 * Material modifier of each job from the structure chosen by the StructureSelector (spec R2, R4, D3).
 */
final readonly class StructureMaterialModifiers implements JobMaterialModifiers
{
    /**
     * @param array<int, ProductionStructure> $assignedStructures structure assigned to the step, by product typeId
     */
    public function __construct(
        private StructureSelector $selector,
        private RigCategories $rigCategories,
        private array $assignedStructures = [],
    ) {
    }

    public function forJob(Recipe $recipe): Multiplier
    {
        return $this->choiceFor($recipe)->materialMultiplier;
    }

    public function choiceFor(Recipe $recipe): StructureChoice
    {
        $assignedStructure = \array_key_exists($recipe->productTypeId, $this->assignedStructures)
            ? $this->assignedStructures[$recipe->productTypeId]
            : null;

        return $this->selector->select(
            $recipe->activity,
            $this->rigCategories->categoryOf($recipe->productTypeId, $recipe->activity),
            $assignedStructure,
        );
    }
}
