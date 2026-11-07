<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PlanningUnscheduled
{
    public const string DURATION_UNKNOWN = 'duration_unknown';

    public function __construct(public string $lineId, public string $typeName, public string $reason)
    {
    }
}
