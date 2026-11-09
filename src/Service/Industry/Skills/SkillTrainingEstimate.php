<?php

declare(strict_types=1);

namespace App\Service\Industry\Skills;

use App\Enum\SkillTrainingUnknownReason;

final readonly class SkillTrainingEstimate
{
    public function __construct(
        public ?int $seconds,
        public ?SkillTrainingUnknownReason $unknownReason,
    ) {
    }
}
