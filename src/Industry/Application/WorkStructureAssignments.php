<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Recipe;

final readonly class WorkStructureAssignments
{
    /**
     * @param array<string, ProductionStructure> $structuresByWorkType by WorkType value
     * @param list<int>                          $targetProductTypeIds
     */
    public function __construct(
        private WorkTypeClassifier $classifier,
        private array $structuresByWorkType,
        private array $targetProductTypeIds,
    ) {
    }

    public function structureFor(Recipe $recipe): ?ProductionStructure
    {
        $workType = $this->classifier->classify($recipe->productTypeId, $recipe->activity, $this->targetProductTypeIds);

        return $this->structuresByWorkType[$workType->value] ?? null;
    }
}
