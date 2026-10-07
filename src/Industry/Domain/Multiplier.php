<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * A bonus applied as a factor (structure, rig, security). Bonuses compose by multiplication, never by addition.
 */
final readonly class Multiplier
{
    private const float NEUTRAL = 1.0;

    public function __construct(public float $value)
    {
        if (!is_finite($value) || $value <= 0.0) {
            throw new \InvalidArgumentException(\sprintf('A multiplier must be finite and strictly positive, got %F.', $value));
        }
    }

    public static function one(): self
    {
        return new self(self::NEUTRAL);
    }

    public function times(self $other): self
    {
        return new self($this->value * $other->value);
    }
}
