<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PlanningUnscheduled
{
    public const string DURATION_UNKNOWN = 'duration_unknown';
    public const string INVENTION_NOT_PLANNED = 'invention_not_planned';

    public function __construct(public string $lineId, public string $typeName, public string $reason)
    {
    }
}
