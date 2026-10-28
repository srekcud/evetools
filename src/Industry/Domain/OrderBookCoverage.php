<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class OrderBookCoverage
{
    private const float FULL_COVERAGE = 1.0;

    public function __construct(public float $fraction)
    {
        if (!($fraction > 0.0 && $fraction <= self::FULL_COVERAGE)) {
            throw new \InvalidArgumentException(\sprintf('An order book coverage is in ]0, 1], %s given.', var_export($fraction, true)));
        }
    }

    public function isPartial(): bool
    {
        return $this->fraction < self::FULL_COVERAGE;
    }
}
