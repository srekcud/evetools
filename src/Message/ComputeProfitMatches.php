<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ComputeProfitMatches
{
    public function __construct(
        public string $userId,
        public int $days = 30,
    ) {
    }
}
