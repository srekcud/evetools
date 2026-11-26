<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class PlanetaryStockRow
{
    public function __construct(
        public int $typeId,
        public ?string $typeName,
        public ?float $volumePerUnit,
        public ?float $weeklyConsumption,
        public ?int $home,
        public int $hub,
        public ?int $corp,
        public int $openOrders,
        public ?float $coverageWeeks,
        public ?float $coverageWithOrdersWeeks,
        public ?int $toBuy,
    ) {
    }
}
