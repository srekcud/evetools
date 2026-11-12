<?php

declare(strict_types=1);

namespace App\Service\ESI;

final readonly class EsiRateLimitDelay
{
    public function __construct(
        public string $group,
        public float $seconds,
        public float $estimatedRemaining,
        public bool $isPause,
    ) {
    }
}
