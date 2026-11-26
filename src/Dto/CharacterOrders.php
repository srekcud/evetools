<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class CharacterOrders
{
    /**
     * @param list<CharacterSellOrder> $sellOrders
     * @param list<CharacterBuyOrder>  $buyOrders
     */
    public function __construct(
        public array $sellOrders,
        public array $buyOrders,
    ) {
    }
}
