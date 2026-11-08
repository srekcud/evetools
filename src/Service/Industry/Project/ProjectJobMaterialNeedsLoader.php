<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;

final readonly class ProjectJobMaterialNeedsLoader
{
    public function __construct(private IndustryProjectService $projectService)
    {
    }

    /**
     * @param array<IndustryProjectBomLine> $bomLines
     * @return array<string, array<int, int>>|null null when the plan of the owner cannot be computed
     */
    public function load(IndustryProject $project, array $bomLines): ?array
    {
        try {
            $plannedProduction = $this->projectService->planOf($project, $project->getOwner());
        } catch (\RuntimeException) {
            return null;
        }

        return JobMaterialNeedsMapper::map($bomLines, $plannedProduction->plan);
    }
}
