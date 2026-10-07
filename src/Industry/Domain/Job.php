<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * One launch of an activity for a number of runs, with the materials it consumes (R1 on the runs of this job).
 */
final readonly class Job
{
    /**
     * @param array<int, Quantity> $materials by material typeId
     */
    public function __construct(
        public int $productTypeId,
        public ActivityKind $activity,
        public Runs $runs,
        public MaterialEfficiency $materialEfficiency,
        public TimeEfficiency $timeEfficiency,
        public array $materials,
    ) {
    }
}
