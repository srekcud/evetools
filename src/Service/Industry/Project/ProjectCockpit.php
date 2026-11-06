<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Enum\ProjectStatus;

final readonly class ProjectCockpit
{
    /**
     * @param list<CockpitPhase> $phases
     */
    public function __construct(
        public ProjectStatus $status,
        public array $phases,
        public CockpitNextAction $nextAction,
    ) {
    }
}
