<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Enum\ProjectStatus;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\WorkType;
use App\Industry\Application\WorkTypeAdvice;

final readonly class ProjectWorkStationChoices
{
    public function __construct(
        private IndustryProjectService $projectService,
        private ProjectWorkStations $workStations,
    ) {
    }

    /**
     * @param array<int|string, string|null> $requested structure id by WorkType value, null removes the choice
     *
     * @throws \InvalidArgumentException when a choice is refused: nothing is changed
     */
    public function change(IndustryProject $project, array $requested): void
    {
        if ([] === $requested) {
            return;
        }

        $owner = $project->getOwner();
        try {
            $planned = $this->projectService->planOf($project, $owner);
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $advicesByType = [];
        foreach ($this->workStations->adviceOf($project, $planned) as $advice) {
            $advicesByType[$advice->workType->value] = $advice;
        }
        $structuresById = [];
        foreach ($this->projectService->structuresOf($owner) as $structure) {
            $structuresById[$structure->id] = $structure;
        }

        $previous = $project->getWorkStations();
        $updated = $previous;
        foreach ($requested as $key => $structureId) {
            $workType = WorkType::tryFrom((string) $key)
                ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a type of work.', $key));
            $advice = $advicesByType[$workType->value]
                ?? throw new \InvalidArgumentException(\sprintf('The plan of the project has no %s work.', $workType->value));

            if (($previous[$workType->value]['structureId'] ?? null) === $structureId) {
                continue;
            }
            if (null !== $this->workStations->lockOf($project, $workType, $planned)) {
                throw new \InvalidArgumentException(\sprintf('The station of the %s work cannot change once a job is launched or a purchase is made.', $workType->value));
            }
            if (null === $structureId) {
                unset($updated[$workType->value]);

                continue;
            }
            $updated[$workType->value] = $this->choiceOf($structureId, $advice, $structuresById);
        }

        if ($updated === $previous) {
            return;
        }

        $project->setWorkStations($updated);
        try {
            $this->projectService->buildBom($project, $owner, refuseToDropWorkedLines: ProjectStatus::Draft !== $project->getStatus());
        } catch (\RuntimeException $e) {
            $project->setWorkStations($previous);

            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, ProductionStructure> $structuresById
     *
     * @return array{structureId: string, recommendedStructureId: string|null}
     */
    private function choiceOf(string $structureId, WorkTypeAdvice $advice, array $structuresById): array
    {
        $structure = $structuresById[$structureId]
            ?? throw new \InvalidArgumentException('The structure is not one of the structures of the owner of the project.');
        if (!$structure->canRun($advice->activity)) {
            throw new \InvalidArgumentException(\sprintf('The structure cannot run the %s work.', $advice->workType->value));
        }

        return ['structureId' => $structure->id, 'recommendedStructureId' => $advice->recommendedStructureId];
    }
}
