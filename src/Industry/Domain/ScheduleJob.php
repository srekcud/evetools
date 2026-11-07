<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class ScheduleJob
{
    /**
     * @param array<string, int> $durationSecondsByWorker worker id => duration; a worker without entry cannot run the job
     * @param list<string>       $dependsOn               ids of the jobs that must end first
     */
    public function __construct(
        public string $id,
        public ActivityKind $activity,
        public array $durationSecondsByWorker,
        public array $dependsOn = [],
    ) {
    }
}
