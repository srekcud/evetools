<?php

declare(strict_types=1);

namespace App\ApiResource\Ledger;

class MiningTypeStatsResource
{
    public int $typeId;

    public string $typeName;

    public float $totalValue = 0.0;

    public int $totalQuantity = 0;
}
