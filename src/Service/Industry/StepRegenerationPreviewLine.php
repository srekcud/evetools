<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProjectStep;

final readonly class StepRegenerationPreviewLine
{
    public function __construct(
        public ?IndustryProjectStep $existingStep,
        public int $blueprintTypeId,
        public string $activityType,
        public int $depth,
        public int $splitIndex,
        public ?StepJobValues $before,
        public StepJobValues $after,
    ) {
    }
}
