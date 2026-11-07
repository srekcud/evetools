<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ActionQueueEntry
{
    public function __construct(
        public string $projectId,
        public ?string $projectName,
        public CockpitNextAction $nextAction,
    ) {
    }
}
