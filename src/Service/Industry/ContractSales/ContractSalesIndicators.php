<?php

declare(strict_types=1);

namespace App\Service\Industry\ContractSales;

final readonly class ContractSalesIndicators
{
    public function __construct(
        public int $typeId,
        public int $estimatedSales,
        public int $soldQuantity,
        public ?float $sellThroughRate,
        public ?float $medianSoldUnitPrice,
        public ?float $medianDaysToSell,
        public ContractSalesConfidence $confidence,
    ) {
    }
}
