<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;

final readonly class WorkTypeAdvice
{
    /**
     * @param list<WorkStationRow> $rows
     */
    public function __construct(
        public WorkType $workType,
        public ActivityKind $activity,
        public int $jobs,
        public int $runs,
        public ?string $recommendedStructureId,
        public ?string $chosenStructureId,
        public bool $choiceStale,
        public ?float $savingIfRecommended,
        public bool $costsCompared,
        public array $rows,
    ) {
    }
}
