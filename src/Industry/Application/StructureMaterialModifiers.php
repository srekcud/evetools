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
     * @param array<int, ?string>             $productCategories  IndustryRigCategory of each job product, null when none
     * @param array<int, ProductionStructure> $assignedStructures structure assigned to the step, by product typeId
     */
    public function __construct(
        private StructureSelector $selector,
        private array $productCategories,
        private array $assignedStructures = [],
    ) {
    }

    public function forJob(Recipe $recipe): Multiplier
    {
        if (!\array_key_exists($recipe->productTypeId, $this->productCategories)) {
            throw new \DomainException(\sprintf('The rig category of product %d is unknown.', $recipe->productTypeId));
        }

        $assignedStructure = \array_key_exists($recipe->productTypeId, $this->assignedStructures)
            ? $this->assignedStructures[$recipe->productTypeId]
            : null;

        return $this->selector
            ->select($recipe->activity, $this->productCategories[$recipe->productTypeId], $assignedStructure)
            ->materialMultiplier;
    }
}
