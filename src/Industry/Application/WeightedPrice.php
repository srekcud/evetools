<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Isk;
use App\Industry\Domain\OrderBookCoverage;

final readonly class WeightedPrice
{
    public function __construct(public Isk $unitPrice, public OrderBookCoverage $coverage)
    {
    }
}
