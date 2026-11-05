<?php

declare(strict_types=1);

namespace App\Service\Industry\SellMarkets;

final readonly class ImmediateSale
{
    public function __construct(
        public ?float $averageUnitPrice,
        public ?float $coverage,
        public ?float $netUnitPrice,
    ) {
    }
}
