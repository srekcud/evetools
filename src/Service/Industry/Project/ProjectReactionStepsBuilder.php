<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProjectBomLine;
use App\Industry\Domain\Recipe;

final readonly class ProjectReactionStepsBuilder
{
    /**
     * @param array<IndustryProjectBomLine> $bomLines
     * @param array<int, Recipe|null>       $recipesByTypeId
     * @param array<int, string>            $typeNames
     * @param list<int>                     $fuelBlockTypeIds
     * @return list<ProjectReactionStep>
     */
    public function build(array $bomLines, array $recipesByTypeId, array $typeNames, array $fuelBlockTypeIds): array
    {
        return [];
    }
}
