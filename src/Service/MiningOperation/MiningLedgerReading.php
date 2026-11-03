<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningLedgerReading
{
    /**
     * @param list<MiningLedgerEntry> $entries
     */
    public function __construct(
        public array $entries,
        public ?\DateTimeImmutable $expiresAt,
    ) {
    }
}
