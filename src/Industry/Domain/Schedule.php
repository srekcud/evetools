<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class Schedule
{
    public const string NO_ELIGIBLE_WORKER = 'no_eligible_worker';
    public const string DEPENDENCY_UNSCHEDULED = 'dependency_unscheduled';

    /**
     * @param list<ScheduledJob>    $assignments
     * @param array<string, string> $unscheduled job id => reason
     * @param \DateTimeImmutable|null $end latest end of the assignments, null when a job stays unscheduled or nothing is scheduled
     */
    public function __construct(
        public array $assignments,
        public array $unscheduled,
        public ?\DateTimeImmutable $end,
    ) {
    }
}
