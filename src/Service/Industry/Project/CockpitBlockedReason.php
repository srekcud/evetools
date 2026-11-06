<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class CockpitBlockedReason
{
    public function __construct(
        public string $code,
        public int $count,
    ) {
    }
}
