<?php

declare(strict_types=1);

namespace App\Service\Industry\ContractSales;

final readonly class HullAttribution
{
    public function __construct(
        public int $typeId,
        public float $price,
    ) {
    }
}
