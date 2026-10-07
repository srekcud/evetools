<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncCharacterWalletTransactions
{
    public function __construct(
        public string $characterId,
    ) {
    }
}
