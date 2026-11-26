<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class PlanetaryStockPriceReading
{
    /**
     * @param list<float|null> $hubSellHistory sellMin of the hub snapshots of the last 7 days, oldest first
     */
    public function __construct(
        public int $typeId,
        public ?string $typeName,
        public ?float $paidPrice,
        public ?float $hubSell,
        public ?float $jitaSell,
        public ?float $myBuyOrder,
        public ?float $hubBestBuy,
        public array $hubSellHistory,
    ) {
    }
}
