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
        $debts = array_fill(0, count($minedValues), 0);
        $heldValues = $minedValues;
        if ($recipientPosition === null) {
            return new MiningFleetStorageSettlement($heldValues, $debts);
        }

        $deliveredByDepositors = 0;
        foreach ($minedValues as $position => $minedValue) {
            $keptValue = $keptValues[$position] ?? throw new \LogicException('Every participant has a kept value');
            if ($keptValue > $minedValue) {
                throw new \LogicException('A participant cannot keep more than they mined');
            }
            if ($position === $recipientPosition || $keptValue === $minedValue) {
                continue;
            }

            $share = $shares[$position] ?? throw new \LogicException('Every participant has a share');
            $debts[$position] = max(0, $share - $keptValue);
            $heldValues[$position] = max($keptValue, $share);
            $deliveredByDepositors += $minedValue - $keptValue;
        }

        $heldValues[$recipientPosition] = $minedValues[$recipientPosition] + $deliveredByDepositors - array_sum($debts);

        return new MiningFleetStorageSettlement(array_values($heldValues), array_values($debts));
    }
}
