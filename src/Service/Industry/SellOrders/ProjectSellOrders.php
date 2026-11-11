<?php

declare(strict_types=1);

namespace App\Service\Industry\SellOrders;

final readonly class ProjectSellOrders
{
    /**
     * @param list<ProjectSellOrder> $orders
     * @param list<string>           $charactersToReconnect
     */
    public function __construct(
        public array $orders,
        public array $charactersToReconnect,
        public ?\DateTimeImmutable $syncedAt,
    ) {
    }
}
