<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Number of runs of a job. Never a number of units: see Quantity.
 */
final readonly class Runs
{
    private const int MINIMUM = 1;

    public function __construct(public int $value)
    {
        if ($value < self::MINIMUM) {
            throw new \InvalidArgumentException(\sprintf('A job has at least %d run, got %d.', self::MINIMUM, $value));
        }
    }
}
