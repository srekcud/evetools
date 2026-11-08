<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Plan;

final class ConsumingActivities
{
    /**
     * @return array<int, ActivityKind>
     */
    public static function of(Plan $plan): array
    {
        $activities = [];
        foreach ($plan->jobs as $job) {
            foreach (array_keys($job->materials) as $materialTypeId) {
                $activities[$materialTypeId] = ActivityKind::Reaction === $job->activity && (ActivityKind::Reaction === ($activities[$materialTypeId] ?? ActivityKind::Reaction))
                    ? ActivityKind::Reaction
                    : ActivityKind::Manufacturing;
            }
        }

        return $activities;
    }
}
