<?php

declare(strict_types=1);

namespace App\Industry\Application;

/**
 * Market unit prices of the items bought (spec R10). Implemented outside the Application.
 */
interface MarketPrices
{
    /**
     * @param array<int, float> $quantitiesByTypeId quantity to buy by typeId, for prices that depend on the depth
     *
     * @return array<int, WeightedPrice> unit price and order book coverage by typeId; a typeId without price is absent, never priced 0
     */
    public function unitPricesFor(array $quantitiesByTypeId): array;
}
