<?php

declare(strict_types=1);

namespace App\Message;

final readonly class TakeMiningLedgerSnapshot
{
    public function __construct(
        public string $snapshotId,
    ) {
    }
}
