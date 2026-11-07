<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Service\Industry\Project\ProjectCockpitInput;

final readonly class ProjectCockpitInputFactory
{
    public function create(IndustryProject $project, User $user): ProjectCockpitInput
    {
        return new ProjectCockpitInput(ProjectStatus::Draft, 0, [], [], 0, false);
    }
}
