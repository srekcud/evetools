<?php

declare(strict_types=1);

namespace App\Enum;

enum ReprocessingLocation: string
{
    case NpcStation = 'npc_station';
    case Athanor = 'athanor';
    case Tatara = 'tatara';
}
