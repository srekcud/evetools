<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectProduct;
use App\Industry\Application\PlannedProduction;
use App\Industry\Application\WorkStationAdvisor;
use App\Industry\Application\WorkType;
use App\Industry\Application\WorkTypeAdvice;
use App\Industry\Application\WorkTypeClassifier;
use App\Industry\Domain\ActivityKind;

final readonly class ProjectWorkStations
{
    private const string BLUEPRINT_JOB_GROUP = 'blueprint';

    private const string REACTION_ACTIVITY_TYPE = 'reaction';

    public function __construct(
        private IndustryProjectService $projectService,
        private WorkStationAdvisor $advisor,
        private WorkTypeClassifier $classifier,
    ) {
    }

    /**
     * @return list<WorkTypeAdvice>
     */
    public function adviceOf(IndustryProject $project, PlannedProduction $planned): array
    {
        $owner = $project->getOwner();

        return $this->advisor->advise(
            $planned,
            $this->projectService->structuresOf($owner),
            $this->projectService->favoriteSystemsOf($owner),
            $this->targetProductTypeIdsOf($project),
            array_map(static fn (array $choice): string => $choice['structureId'], $project->getWorkStations()),
            $this->hasBlueprintLines($project),
        );
    }

    public function lockOf(IndustryProject $project, WorkType $workType, PlannedProduction $planned): ?ProjectWorkStationLock
    {
        $targetProductTypeIds = $this->targetProductTypeIdsOf($project);
        $bomLines = $project->getBomItems()->toArray();

        $hasLinkedJob = array_any(
            $bomLines,
            fn (IndustryProjectBomLine $line): bool => $line->isJob()
                && $this->workTypeOf($line, $targetProductTypeIds) === $workType
                && !$line->getJobMatches()->isEmpty(),
        );
        if ($hasLinkedJob) {
            return ProjectWorkStationLock::JobLaunched;
        }

        $consumedTypeIds = $this->consumedMaterialTypeIdsOf($planned, $workType, $targetProductTypeIds);
        $hasPurchase = array_any(
            $bomLines,
            static fn (IndustryProjectBomLine $line): bool => !$line->isJob()
                && isset($consumedTypeIds[$line->getTypeId()])
                && !$line->getPurchases()->isEmpty(),
        );

        return $hasPurchase ? ProjectWorkStationLock::PurchaseMade : null;
    }

    /**
     * @param list<int> $targetProductTypeIds
     */
    private function workTypeOf(IndustryProjectBomLine $line, array $targetProductTypeIds): WorkType
    {
        if (self::BLUEPRINT_JOB_GROUP === $line->getJobGroup()) {
            return WorkType::Blueprints;
        }

        $activity = self::REACTION_ACTIVITY_TYPE === $line->getActivityType() ? ActivityKind::Reaction : ActivityKind::Manufacturing;

        return $this->classifier->classify($line->getTypeId(), $activity, $targetProductTypeIds);
    }

    /**
     * @param list<int> $targetProductTypeIds
     *
     * @return array<int, true> by material typeId
     */
    private function consumedMaterialTypeIdsOf(PlannedProduction $planned, WorkType $workType, array $targetProductTypeIds): array
    {
        $consumed = [];
        foreach ($planned->plan->jobs as $job) {
            if ($this->classifier->classify($job->productTypeId, $job->activity, $targetProductTypeIds) === $workType) {
                $consumed += array_fill_keys(array_keys($job->materials), true);
            }
        }

        return $consumed;
    }

    private function hasBlueprintLines(IndustryProject $project): bool
    {
        return array_any(
            $project->getBomItems()->toArray(),
            static fn (IndustryProjectBomLine $line): bool => $line->isJob() && self::BLUEPRINT_JOB_GROUP === $line->getJobGroup(),
        );
    }

    /**
     * @return list<int>
     */
    private function targetProductTypeIdsOf(IndustryProject $project): array
    {
        return array_values(array_map(static fn (IndustryProjectProduct $item): int => $item->getTypeId(), $project->getItems()->toArray()));
    }
}
