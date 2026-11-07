<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PlanningJob
{
    public function __construct(
        public string $lineId,
        public int $typeId,
        public string $typeName,
        public string $activity,
        public int $runs,
        public ?PlanningSpan $planned,
        public ?PlanningRealSpan $real,
        public ?PlanningWait $wait,
    ) {
    }
}
