<?php

declare(strict_types=1);

namespace App\Service\Industry\Skills;

use App\Enum\SkillEffect;
use App\Enum\SkillGainUnknownReason;
use App\Enum\SkillTrainingUnknownReason;

final readonly class SkillGainLine
{
    public function __construct(
        public int $skillId,
        public int $currentLevel,
        public int $targetLevel,
        public SkillEffect $effect,
        public ?float $hoursSavedPerMonth,
        public ?float $monthlyGain,
        public ?SkillGainUnknownReason $gainUnknownReason,
        public ?int $trainingSeconds,
        public ?SkillTrainingUnknownReason $trainingUnknownReason,
        public ?float $gainPerTrainingDay,
    ) {
    }
}
