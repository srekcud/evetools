<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningFleetStorageSettlement
{
    /**
     * @param list<int> $heldValues
     * @param list<int> $debts
     */
    public function __construct(
        public array $heldValues,
        public array $debts,
    ) {
    }
}
