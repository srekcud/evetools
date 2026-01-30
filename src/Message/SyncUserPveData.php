<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncUserPveData
{
    public function __construct(
        public string $userId,
    ) {
    }
}
