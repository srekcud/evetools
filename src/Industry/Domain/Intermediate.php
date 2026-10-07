<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * An item built in the plan to be consumed by another job (spec R3), with its demand, stock and surplus.
 */
final readonly class Intermediate
{
    /**
     * @param list<Job> $jobs empty when the stock covers the whole demand
     */
    public function __construct(
        public Quantity $demand,
        public Quantity $consumedStock,
        public Quantity $quantityProduced,
        public Quantity $surplus,
        public array $jobs,
    ) {
    }

    public function totalRuns(): int
    {
        return array_sum(array_map(static fn (Job $job): int => $job->runs->value, $this->jobs));
    }
}
