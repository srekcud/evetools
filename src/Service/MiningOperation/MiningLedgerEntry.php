<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningLedgerEntry
{
    public function __construct(
        public \DateTimeImmutable $date,
        public int $typeId,
        public int $solarSystemId,
        public int $quantity,
    ) {
    }
}
