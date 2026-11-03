<?php

declare(strict_types=1);

namespace App\Enum;

enum ReprocessingLocation: string
{
    case NpcStation = 'npc_station';
    case Athanor = 'athanor';
    case Tatara = 'tatara';

    public function isStructure(): bool
    {
        return $this !== self::NpcStation;
    }

    public function structureBonus(): float
    {
        return match ($this) {
            self::NpcStation => 0.0,
            self::Athanor => 0.02,
            self::Tatara => 0.055,
        };
    }
}
