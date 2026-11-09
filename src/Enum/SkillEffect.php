<?php

declare(strict_types=1);

namespace App\Enum;

enum SkillEffect: string
{
    case ManufacturingTime = 'manufacturing_time';
    case ReactionTime = 'reaction_time';
    case InventionChance = 'invention_chance';
    case ManufacturingSlot = 'manufacturing_slot';
    case ReactionSlot = 'reaction_slot';
    case ScienceSlot = 'science_slot';
}
