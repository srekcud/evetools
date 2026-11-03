<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningCompressorKind: string
{
    case Asteroid = 'asteroid';
    case Gas = 'gas';
    case Ice = 'ice';
    case Mercoxit = 'mercoxit';
    case Moon = 'moon';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
