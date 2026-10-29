<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Enum\ProjectCorrectionStatus;

final readonly class ProjectCorrectionOutcome
{
    public function __construct(
        public string $projectId,
        public string $label,
        public int $runs,
        public ProjectCorrectionStatus $status,
        public ?string $change = null,
        public ?string $reason = null,
    ) {
    }
}
