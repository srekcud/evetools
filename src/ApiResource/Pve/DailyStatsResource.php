<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

class DailyStatsResource
{
    public string $date = '';

    public float $income = 0.0;

    public float $bounties = 0.0;

    public float $lootSales = 0.0;

    public float $expenses = 0.0;

    public float $profit = 0.0;
}
