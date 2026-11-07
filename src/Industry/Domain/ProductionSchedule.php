<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final class ProductionSchedule
{
    /**
     * @param list<ScheduleJob>                         $jobs
     * @param list<ScheduleWorker>                      $workers
     * @param array<string, \DateTimeImmutable>         $fixedEnds job id => imposed end; such a job is not scheduled
     */
    public static function earliest(array $jobs, array $workers, \DateTimeImmutable $start, array $fixedEnds = []): Schedule
    {
        return new Schedule([], [], null);
    }
}
