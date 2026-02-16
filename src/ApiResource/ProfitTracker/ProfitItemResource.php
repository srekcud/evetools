<?php

declare(strict_types=1);

namespace App\ApiResource\ProfitTracker;

class ProfitItemResource
{
    public int $productTypeId = 0;

    public string $typeName = '';

    public int $quantitySold = 0;

    public float $materialCost = 0.0;

    public float $jobInstallCost = 0.0;

    public float $taxAmount = 0.0;

    public float $totalCost = 0.0;

    public float $revenue = 0.0;

    public float $profit = 0.0;

    public float $marginPercent = 0.0;

    public ?string $lastSaleDate = null;
}
