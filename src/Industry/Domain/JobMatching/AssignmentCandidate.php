<?php

declare(strict_types=1);

namespace App\Industry\Domain\JobMatching;

final readonly class AssignmentCandidate
{
    /**
     * @param list<MatchSignal> $signals
     */
    public function __construct(
        public string $projectId,
        public string $lineId,
        public int $score,
        public array $signals,
        public bool $oversized,
    ) {
    }
}
