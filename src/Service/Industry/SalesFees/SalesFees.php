<?php

declare(strict_types=1);

namespace App\Service\Industry\SalesFees;

final readonly class SalesFees
{
    public function __construct(
        public float $brokerFeeRate,
        public float $salesTaxRate,
    ) {
    }
}
