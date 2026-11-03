<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningBoostShip: int
{
    case Porpoise = 42244;
    case Orca = 28606;
    case Rorqual = 28352;

    /**
     * @return list<int>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
