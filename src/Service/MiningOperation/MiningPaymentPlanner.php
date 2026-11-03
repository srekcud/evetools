<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningPaymentPlanner
{
    /**
     * @param list<int> $minedValues
     *
     * @return list<MiningPlannedPayment>
     */
    public function plan(array $minedValues, MiningShareSplit $split, ?int $boosterPosition, bool $hasCorporationCut): array
    {
        return [];
    }
}
