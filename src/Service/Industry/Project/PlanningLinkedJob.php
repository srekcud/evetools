<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PlanningLinkedJob
{
    public function __construct(
        public string $status,
        public int $runs,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public string $characterName,
    ) {
    }
}
