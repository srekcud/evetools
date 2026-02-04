<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

class MiningDailyStatsResource
{
    public string $date;

    public float $totalValue = 0.0;

    public int $totalQuantity = 0;
}
