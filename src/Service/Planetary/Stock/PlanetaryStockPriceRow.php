<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class PlanetaryStockPriceRow
{
    public function __construct(
        public int $typeId,
        public ?string $typeName,
        public ?float $paidPrice,
        public ?float $hubSell,
        public ?float $jitaSell,
        public ?float $paidVsHubPercent,
        public ?float $myBuyOrder,
        public ?float $buyOrderVsHubPercent,
        public ?float $trend7dPercent,
        public ?float $hubBestBuy,
        public ?PlanetaryStockPriceSignal $signal,
    ) {
    }
}
