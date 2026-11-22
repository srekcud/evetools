<?php

declare(strict_types=1);

namespace App\Industry\Domain\JobMatching;

final readonly class JobToAssign
{
    /**
     * @param list<AssignmentCandidate> $candidates
     */
    public function __construct(
        public int $jobId,
        public array $candidates,
    ) {
    }
}
