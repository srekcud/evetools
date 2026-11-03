<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningPaymentKind;

final readonly class MiningPaymentPlanner
{
    /**
     * @param list<int> $minedValues
     *
     * @return list<MiningPlannedPayment>
     */
    public function plan(array $minedValues, MiningShareSplit $split, ?int $boosterPosition, bool $hasCorporationCut): array
    {
        $boosterCut = $split->boosterCut ?? throw new \LogicException('Payments are planned once the shares are known');
        $corporationCut = $split->corporationCut ?? throw new \LogicException('Payments are planned once the shares are known');
        if ($boosterCut > 0 && $boosterPosition === null) {
            throw new \LogicException('A booster cut is collected by a designated booster');
        }

        $boosterCollects = $boosterPosition !== null && $boosterCut > 0;
        $payments = [];
        foreach ($minedValues as $position => $minedValue) {
            $amountDue = $minedValue - ($split->shares[$position] ?? throw new \LogicException('Payments are planned once the shares are known'));
            if ($amountDue <= 0 || ($boosterCollects && $position === $boosterPosition)) {
                continue;
            }
            $payments[] = $boosterCollects
                ? new MiningPlannedPayment(count($payments), MiningPaymentKind::BoosterCut, $position, $boosterPosition, $amountDue)
                : new MiningPlannedPayment(count($payments), MiningPaymentKind::CorporationCut, $position, null, $amountDue);
        }

        if ($boosterCollects && $hasCorporationCut && $corporationCut > 0) {
            $payments[] = new MiningPlannedPayment(count($payments), MiningPaymentKind::CollectedCorporationCut, $boosterPosition, null, $corporationCut);
        }

        return $payments;
    }
}
