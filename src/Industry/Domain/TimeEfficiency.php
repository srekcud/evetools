<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class TimeEfficiency
{
    private const int MINIMUM_LEVEL = 0;
    private const int MAXIMUM_LEVEL = 20;

    /** TE is researched by steps of 2 levels. */
    private const int LEVEL_STEP = 2;

    public function __construct(public int $value)
    {
        if ($value < self::MINIMUM_LEVEL || $value > self::MAXIMUM_LEVEL || 0 !== $value % self::LEVEL_STEP) {
            throw new \InvalidArgumentException(\sprintf('TE must be an even level between %d and %d, got %d.', self::MINIMUM_LEVEL, self::MAXIMUM_LEVEL, $value));
        }
    }
}
