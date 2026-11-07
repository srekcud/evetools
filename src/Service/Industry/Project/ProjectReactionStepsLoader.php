<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\Recipe;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\Sde\InvTypeRepository;

final readonly class ProjectReactionStepsLoader
{
    private const int FUEL_BLOCK_GROUP_ID = 1136;
    private const string ACTIVITY_REACTION = 'reaction';

    public function __construct(
        private IndustryProjectBomLineRepository $bomLineRepository,
        private BlueprintCatalog $blueprintCatalog,
        private InvTypeRepository $typeRepository,
        private ProjectReactionStepsBuilder $stepsBuilder,
    ) {
    }

    /**
     * @return list<ProjectReactionStep>
     */
    public function load(IndustryProject $project): array
    {
        $bomLines = $this->bomLineRepository->findBy(['project' => $project]);

        $recipesByTypeId = [];
        foreach ($bomLines as $line) {
            if ($this->isReactionLine($line)) {
                $recipesByTypeId[$line->getTypeId()] ??= $this->blueprintCatalog->recipeFor($line->getTypeId());
            }
        }

        $typeIds = array_keys($recipesByTypeId);
        foreach ($recipesByTypeId as $recipe) {
            if ($recipe instanceof Recipe) {
                array_push($typeIds, ...array_map(static fn ($material): int => $material->typeId, $recipe->materials));
            }
        }

        $typeNames = [];
        $fuelBlockTypeIds = [];
        foreach ([] === $typeIds ? [] : $this->typeRepository->findByTypeIds(array_values(array_unique($typeIds))) as $typeId => $type) {
            $typeNames[$typeId] = $type->getTypeName();
            if (self::FUEL_BLOCK_GROUP_ID === $type->getGroup()->getGroupId()) {
                $fuelBlockTypeIds[] = $typeId;
            }
        }

        return $this->stepsBuilder->build($bomLines, $recipesByTypeId, $typeNames, $fuelBlockTypeIds);
    }

    private function isReactionLine(IndustryProjectBomLine $line): bool
    {
        return $line->isJob() && self::ACTIVITY_REACTION === $line->getActivityType();
    }
}
