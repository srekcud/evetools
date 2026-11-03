<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningFleetStorageCalculator
{
    /**
     * @param list<int> $minedValues
     * @param list<int> $keptValues
     * @param list<int> $shares
     */
    public function settle(array $minedValues, array $keptValues, ?int $recipientPosition, array $shares): MiningFleetStorageSettlement
    {
        return new MiningFleetStorageSettlement([], []);
    }
}
