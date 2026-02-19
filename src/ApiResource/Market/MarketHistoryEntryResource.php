<?php

declare(strict_types=1);

namespace App\ApiResource\Market;

class MarketHistoryEntryResource
{
    public string $date;

    public float $average;

    public float $highest;

    public float $lowest;

    public int $orderCount;

    public int $volume;

    /** Minimum sell price (structure snapshots only). */
    public ?float $sellMin = null;

    /** Maximum buy price (structure snapshots only). */
    public ?float $buyMax = null;

    /** Number of sell orders (structure snapshots only). */
    public ?int $sellOrderCount = null;

    /** Number of buy orders (structure snapshots only). */
    public ?int $buyOrderCount = null;

    /** Total sell volume (structure snapshots only). */
    public ?int $sellVolume = null;

    /** Total buy volume (structure snapshots only). */
    public ?int $buyVolume = null;
}
