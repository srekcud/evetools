<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Result of the plan mode (spec R3, R12): whole runs, explicit surplus.
 */
final readonly class Plan
{
    /**
     * @param list<Job>                 $jobs          jobs of the targets, then of the intermediates
     * @param array<int, Quantity>      $leaves        quantity to buy by typeId, after stock; absent when nothing is left to buy
     * @param array<int, Intermediate>  $intermediates by product typeId; neither the targets nor the blacklisted items
     */
    public function __construct(public array $jobs, public array $leaves, public array $intermediates)
    {
    }

    /**
     * @return list<Job>
     */
    public function jobsFor(int $productTypeId): array
    {
        return array_values(array_filter($this->jobs, static fn (Job $job): bool => $job->productTypeId === $productTypeId));
    }
}
