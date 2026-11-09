<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Service\Industry\SellMarkets\SellMarketRow;

final readonly class ForecastedProduct
{
    public function __construct(
        public int $typeId,
        public string $typeName,
        public int $quantity,
        public ?SellMarketRow $structure,
        public SellMarketRow $jita,
    ) {
    }

    public function structureNetRevenue(): ?float
    {
        return $this->netRevenueOf($this->structure);
    }

    public function jitaNetRevenue(): ?float
    {
        return $this->netRevenueOf($this->jita);
    }

    private function netRevenueOf(?SellMarketRow $row): ?float
    {
        return null === $row || null === $row->netUnitPrice ? null : $this->quantity * $row->netUnitPrice;
    }
}
