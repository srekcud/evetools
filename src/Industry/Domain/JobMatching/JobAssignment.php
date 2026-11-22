<?php

declare(strict_types=1);

namespace App\Industry\Domain\JobMatching;

final readonly class JobAssignment
{
    /**
     * @param list<MatchSignal> $signals
     */
    public function __construct(
        public int $jobId,
        public string $lineId,
        public array $signals,
    ) {
    }
}
