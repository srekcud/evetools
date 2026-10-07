<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Result of the marginal cost mode (spec R12, D4b): fractional runs and quantities, no rounding, no surplus.
 */
final readonly class MarginalCostPlan
{
    /**
     * @param array<int, float> $leaves         fractional quantity to buy by typeId, after stock; absent when nothing is left to buy
     * @param array<int, float> $fractionalRuns runs of each intermediate, by product typeId
     */
    public function __construct(public array $leaves, public array $fractionalRuns)
    {
    }
}
