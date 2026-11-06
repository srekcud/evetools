<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectCockpitCalculator
{
    public function calculate(ProjectCockpitInput $input): ProjectCockpit
    {
        return new ProjectCockpit($input->status, [], new CockpitNextAction('', false, null, null, null, null, null, null));
    }
}
