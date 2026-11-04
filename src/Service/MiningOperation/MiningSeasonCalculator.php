<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final class MiningSeasonCalculator
{
    /**
     * @param list<MiningSeasonOperation> $operations
     */
    public function summarise(array $operations): MiningSeasonSummary
    {
        return new MiningSeasonSummary(-1, 0, 0, 0, 0, 0, []);
    }
}
