<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class ScheduleWorker
{
    public function __construct(
        public string $id,
        public int $manufacturingSlots,
        public int $reactionSlots,
    ) {
    }
}
