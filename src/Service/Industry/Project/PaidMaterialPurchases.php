<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PaidMaterialPurchases
{
    public function __construct(
        public int $quantity,
        public float $totalPrice,
    ) {
    }

    public function averageUnitPrice(): float
    {
        return $this->totalPrice / $this->quantity;
    }
}
