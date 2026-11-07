<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class ScheduledJob
{
    public function __construct(
        public string $jobId,
        public string $workerId,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }
}
