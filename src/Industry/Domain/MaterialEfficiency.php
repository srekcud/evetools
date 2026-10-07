<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class MaterialEfficiency
{
    private const int MINIMUM_LEVEL = 0;
    private const int MAXIMUM_LEVEL = 10;

    public function __construct(public int $value)
    {
        if ($value < self::MINIMUM_LEVEL || $value > self::MAXIMUM_LEVEL) {
            throw new \InvalidArgumentException(\sprintf('ME must be between %d and %d, got %d.', self::MINIMUM_LEVEL, self::MAXIMUM_LEVEL, $value));
        }
    }
}
