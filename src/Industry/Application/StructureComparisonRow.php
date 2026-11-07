<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Multiplier;

final readonly class StructureComparisonRow
{
    public function __construct(
        public ProductionStructure $structure,
        public bool $inFavoriteSystem,
        public Multiplier $materialMultiplier,
        public StructureCostBreakdown $breakdown,
        public ?float $delta,
    ) {
    }
}
