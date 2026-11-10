<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningFleetStorageShareCalculator
{
    /**
     * @param list<MiningFleetStorageContribution> $contributions
     */
    public function calculate(array $contributions): MiningFleetStorageShare
    {
        return new MiningFleetStorageShare();
    }
}
