<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Input of the ProductionPlanner (spec R3, R9, R12).
 */
final readonly class PlanRequest
{
    /**
     * @param list<PlanTarget>                $targets
     * @param array<int, Quantity>            $startingStock            by typeId, consumed before the runs are computed (R9)
     * @param list<int>                       $blacklist                typeIds bought even when a recipe exists
     * @param array<int, Runs>                $blueprintCopyMaxRuns     run cap of the BPC used, by product; absent for a BPO
     * @param array<int, BlueprintEfficiency> $intermediateEfficiencies overrides the default ME/TE of an intermediate
     */
    public function __construct(
        public array $targets,
        public JobMaterialModifiers $materialModifiers,
        public array $startingStock = [],
        public array $blacklist = [],
        public array $blueprintCopyMaxRuns = [],
        public array $intermediateEfficiencies = [],
    ) {
    }
}
