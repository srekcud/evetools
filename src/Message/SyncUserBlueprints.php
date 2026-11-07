<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncUserBlueprints
{
    public function __construct(
        public string $userId,
    ) {
    }
}
