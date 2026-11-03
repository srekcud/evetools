<?php

declare(strict_types=1);

namespace App\Enum;

enum ReprocessingRigTier: string
{
    case None = 'none';
    case T1 = 't1';
    case T2 = 't2';

    public function basePercentBonus(): int
    {
        return match ($this) {
            self::None => 0,
            self::T1 => 1,
            self::T2 => 3,
        };
    }
}
