<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class StepToLaunch
{
    public function __construct(
        public string $slotCategory,
        public string $projectId,
        public ?string $projectName,
        public int $typeId,
        public string $typeName,
        public int $runs,
    ) {
    }
}
