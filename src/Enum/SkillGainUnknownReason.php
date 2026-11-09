<?php

declare(strict_types=1);

namespace App\Enum;

enum SkillGainUnknownReason: string
{
    case SkillsNotSynchronized = 'skills_not_synchronized';
    case ProjectJobsNotCached = 'project_jobs_not_cached';
    case IskPerHourUnknown = 'isk_per_hour_unknown';
}
