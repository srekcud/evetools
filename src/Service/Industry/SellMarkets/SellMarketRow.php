<?php

declare(strict_types=1);

namespace App\Service\Industry\SellMarkets;

final readonly class SellMarketRow
{
    public function __construct(
        public string $key,
        public string $name,
        public ?float $unitPrice,
        public ?float $coverage,
        public ?float $dailyVolume,
        public string $volumeKind,
        public ?float $feeRate,
        public ?float $netUnitPrice,
        public ?float $daysToSell,
    ) {
    }
}
