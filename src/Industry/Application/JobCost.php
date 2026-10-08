<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\InstallCost;

/**
 * A manufacturing or reaction job of the production, where it runs and its install cost (spec R4, R6).
 */
final readonly class JobCost
{
    /**
     * @param float $runs fractional in marginal cost mode (D4b)
     */
    public function __construct(
        public int $productTypeId,
        public ActivityKind $activity,
        public float $runs,
        public StructureChoice $structureChoice,
        public ?int $solarSystemId,
        public InstallCost $installCost,
    ) {
    }
}
