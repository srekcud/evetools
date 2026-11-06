<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class CockpitPhase
{
    public function __construct(
        public string $key,
        public string $state,
        public int $counterDone,
        public int $counterTotal,
        public ?CockpitBlockedReason $blockedReason,
    ) {
    }
}
