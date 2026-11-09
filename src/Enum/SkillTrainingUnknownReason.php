<?php

declare(strict_types=1);

namespace App\Enum;

enum SkillTrainingUnknownReason: string
{
    case AttributesNotSynchronized = 'attributes_not_synchronized';
    case SkillNotInSde = 'skill_not_in_sde';
    case MaxLevelReached = 'max_level_reached';
    case SkillPointsUnknown = 'skill_points_unknown';
    case ActiveLevelBelowTrainedLevel = 'active_level_below_trained_level';
}
