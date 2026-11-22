<?php

declare(strict_types=1);

namespace App\Industry\Domain\JobMatching;

final readonly class JobMatchPlan
{
    /**
     * @param list<JobAssignment> $assignments
     * @param list<JobToAssign>   $unassignedJobs
     */
    public function __construct(
        public array $assignments,
        public array $unassignedJobs,
    ) {
    }
}
