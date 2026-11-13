<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncWalletTransactions
{
    public function __construct(
        public bool $dueOnly = true,
    ) {
    }
}
