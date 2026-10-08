<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Cost;
use App\Industry\Domain\Quantity;

/**
 * Production cost of a product: bought items, install costs, invention, and the cost of one unit.
 */
final readonly class ProductionCost
{
    /**
     * @param array<int, LeafCost> $leaves by typeId
     * @param list<JobCost>        $jobs   manufacturing and reaction jobs
     */
    public function __construct(
        public array $leaves,
        public array $jobs,
        public Cost $materialCost,
        public Cost $installCost,
        public ?InventionCost $invention,
        public Cost $totalCost,
        public Quantity $producedQuantity,
        public Cost $costPerUnit,
    ) {
    }
}
