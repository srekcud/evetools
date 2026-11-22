<?php

declare(strict_types=1);

namespace App\Industry\Domain\JobMatching;

final readonly class CandidateJob
{
    public function __construct(
        public int $jobId,
        public int $runs,
        public string $installerId,
        public ?int $blueprintLocationId = null,
        public ?int $outputLocationId = null,
        public ?int $stationId = null,
    ) {
    }
}
