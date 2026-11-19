<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PurchasesToDeliver
{
    /**
     * @param list<PurchaseToDeliver> $rows
     */
    public function __construct(
        public ContainerStockState $stockState,
        public int $purchaseCount,
        public array $rows,
    ) {
    }
}
