<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Number of units of an item. Never a number of runs: see Runs.
 */
final readonly class Quantity
{
    public function __construct(public int $value)
    {
        if ($value < 0) {
            throw new \InvalidArgumentException(\sprintf('A quantity cannot be negative, got %d.', $value));
        }
    }
}
