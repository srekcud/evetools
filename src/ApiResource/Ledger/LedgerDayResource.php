<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

class LedgerDayResource
{
    public string $date;

    public float $total = 0.0;

    public float $pve = 0.0;

    public float $mining = 0.0;

    public float $expenses = 0.0;

    public float $profit = 0.0;
}
