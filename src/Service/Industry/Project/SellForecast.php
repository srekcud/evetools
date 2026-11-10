<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class SellForecast
{
    /**
     * @param list<ForecastedProduct> $products
     */
    public function __construct(
        public ?SellStructure $sellStructure,
        public array $products,
        public ?float $structureNetRevenue,
        public ?float $jitaNetRevenue,
        public int $structureUnknownProductCount,
        public int $jitaUnknownProductCount,
        public ?float $remainingCost = null,
        public int $remainingCostUnknownLineCount = 0,
        public ?float $structureProfit = null,
        public ?float $jitaProfit = null,
    ) {
    }
}
