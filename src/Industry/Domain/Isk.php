<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * An ISK amount, kept unrounded: rounding to 2 decimals happens at display only (spec R11).
 */
final readonly class Isk
{
    /** Float sums over many jobs drift by fractions of ISK; one ISK is below anything a player can act on. */
    private const float EQUALITY_TOLERANCE = 1.0;

    public function __construct(public float $amount)
    {
        if (!is_finite($amount) || $amount < 0.0) {
            throw new \InvalidArgumentException(\sprintf('An ISK amount must be finite and not negative, got %F.', $amount));
        }
    }

    public function equals(self $other): bool
    {
        return abs($this->amount - $other->amount) <= self::EQUALITY_TOLERANCE;
    }
}
