<?php

declare(strict_types=1);

namespace App\Service\Sync;

final readonly class WalletRefreshRequest
{
    public function __construct(
        public int $queuedCharacters,
        public int $notDueCharacters,
    ) {
    }
}
