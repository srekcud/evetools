<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class OrderBookCoverage
{
    public function __construct(public float $fraction)
    {
    }

    public function isPartial(): bool
    {
        return false;
    }
}
