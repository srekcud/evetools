<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningSeasonPeriod: string
{
    case ThirtyDays = '30d';
    case NinetyDays = '90d';
    case All = 'all';

    public function days(): ?int
    {
        return match ($this) {
            self::ThirtyDays => 30,
            self::NinetyDays => 90,
            self::All => null,
        };
    }
}
