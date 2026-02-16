<?php

declare(strict_types=1);

namespace App\ApiResource\ShoppingList;

class AppraisalItemResource
{
    public int $typeId = 0;

    public string $typeName = '';

    public int $quantity = 0;

    public float $volume = 0.0;

    public float $totalVolume = 0.0;

    public ?float $sellPrice = null;

    public ?float $sellTotal = null;

    public ?float $buyPrice = null;

    public ?float $buyTotal = null;

    public ?float $splitPrice = null;

    public ?float $splitTotal = null;

    /** Weighted average sell price for the requested quantity */
    public ?float $sellPriceWeighted = null;

    /** Weighted average sell total for the requested quantity */
    public ?float $sellTotalWeighted = null;

    /** Weighted average buy price for the requested quantity */
    public ?float $buyPriceWeighted = null;

    /** Weighted average buy total for the requested quantity */
    public ?float $buyTotalWeighted = null;

    /** Weighted split price (average of weighted sell and buy) */
    public ?float $splitPriceWeighted = null;

    /** Weighted split total */
    public ?float $splitTotalWeighted = null;

    /** Sell order book coverage ratio (0.0 to 1.0) */
    public ?float $sellCoverage = null;

    /** Buy order book coverage ratio (0.0 to 1.0) */
    public ?float $buyCoverage = null;

    /** Average daily volume traded on Jita market (last 30 days) */
    public ?float $avgDailyVolume = null;
}
