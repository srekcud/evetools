<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class OutputPerRun
{
    private const int MINIMUM = 1;

    public function __construct(public int $value)
    {
        if ($value < self::MINIMUM) {
            throw new \InvalidArgumentException(\sprintf('A recipe yields at least %d unit per run, got %d.', self::MINIMUM, $value));
        }
    }
}
