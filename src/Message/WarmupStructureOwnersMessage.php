<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message to warmup structure owner cache for a user's structures.
 */
class WarmupStructureOwnersMessage
{
    public function __construct(
        public readonly string $userId,
    ) {
    }
}
