<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;

final readonly class ProjectReactionStepsLoader
{
    /**
     * @return list<ProjectReactionStep>
     */
    public function load(IndustryProject $project): array
    {
        return [];
    }
}
