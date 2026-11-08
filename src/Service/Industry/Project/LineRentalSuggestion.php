<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Industry\Domain\LineRentalCategory;

final readonly class LineRentalSuggestion
{
    public function __construct(
        public ?float $suggestedValue,
        public ?LineRentalUnknownReason $unknownReason,
        public ?LineRentalCategory $category,
        public ?float $days,
        public ?int $runs,
        public ?float $dayFactor,
        public ?float $ratePerRun,
    ) {
    }
}
