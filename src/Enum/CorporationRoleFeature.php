<?php

declare(strict_types=1);

namespace App\Enum;

enum CorporationRoleFeature: string
{
    case MoonTax = 'moon-tax';
    case Extractions = 'extractions';

    public function requiredRole(): string
    {
        return match ($this) {
            self::MoonTax => 'Accountant',
            self::Extractions => 'Station_Manager',
        };
    }
}
