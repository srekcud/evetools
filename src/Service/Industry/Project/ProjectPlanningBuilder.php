<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectPlanningBuilder
{
    public function build(ProjectPlanningInput $input, \DateTimeImmutable $now): ProjectPlanning
    {
        return new ProjectPlanning($input->anchor, true, null, null, null, null, [], []);
    }
}
