<?php

declare(strict_types=1);

namespace App\Enum;

enum ReprocessingSecurity: string
{
    case Highsec = 'highsec';
    case Lowsec = 'lowsec';
    case Nullsec = 'nullsec';

    public function bonus(): float
    {
        return match ($this) {
            self::Highsec => 0.0,
            self::Lowsec => 0.06,
            self::Nullsec => 0.12,
        };
    }
}
