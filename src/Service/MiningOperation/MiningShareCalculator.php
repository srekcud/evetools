<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningShareCalculator
{
    /**
     * @param list<?int> $minedValues
     */
    public function split(array $minedValues, int $boosterCutPercent, int $corporationCutPercent): MiningShareSplit
    {
        return new MiningShareSplit(null, null, null, [], [], null, null, null);
    }
}
