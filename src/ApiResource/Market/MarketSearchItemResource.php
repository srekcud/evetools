<?php

declare(strict_types=1);

namespace App\ApiResource\Market;

class MarketSearchItemResource
{
    public int $typeId;

    public string $typeName;

    public ?string $groupName = null;

    public ?string $categoryName = null;

    public ?float $jitaSell = null;

    public ?float $jitaBuy = null;

    public ?float $spread = null;

    public ?float $avgDailyVolume = null;

    public ?float $change30d = null;
}
