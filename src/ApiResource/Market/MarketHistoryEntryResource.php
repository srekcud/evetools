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
}
