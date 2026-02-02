<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

class MatchJobsResultResource
{
    /** @var ProjectStepResource[] */
    public array $steps = [];

    public ?float $jobsCost = null;

    public int $syncedCharacters = 0;

    public ?string $warning = null;
}
