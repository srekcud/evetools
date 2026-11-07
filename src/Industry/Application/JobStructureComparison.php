<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Isk;

final readonly class JobStructureComparison
{
    /**
     * @param list<StructureComparisonRow> $rows
     */
    public function __construct(
        public int $productTypeId,
        public ActivityKind $activity,
        public int $jobs,
        public int $runs,
        public ?string $chosenStructureId,
        public StructureChoiceStatus $choiceStatus,
        public array $rows,
        public ?string $cheapestStructureId,
        public ?Isk $savingIfCheapest,
    ) {
    }
}
