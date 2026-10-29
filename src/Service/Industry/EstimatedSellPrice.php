<?php

declare(strict_types=1);

namespace App\Service\Industry;

final readonly class EstimatedSellPrice
{
    public function __construct(
        public float $amount,
        public string $source,
        public float $taxAmount,
    ) {
    }
}
