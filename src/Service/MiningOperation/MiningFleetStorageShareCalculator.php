<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningFleetStorageDelivery;
use App\Enum\MiningHaulerPlanUnknownReason;

final readonly class MiningFleetStorageShareCalculator
{
    /**
     * @param list<MiningFleetStorageContribution> $contributions
     */
    public function calculate(array $contributions): MiningFleetStorageShare
    {
        $minedUnits = 0;
        $deliveredUnits = 0;
        foreach ($contributions as $contribution) {
            if (null === $contribution->minedByTypeId) {
                return $this->unknown(MiningHaulerPlanUnknownReason::LedgerUnknown);
            }
            $minedUnits += array_sum($contribution->minedByTypeId);
            if (!$contribution->isRecipient) {
                $deliveredUnits += $this->deliveredBy($contribution->minedByTypeId, $contribution);
            }
        }

        if (0 === $minedUnits) {
            return $this->unknown(MiningHaulerPlanUnknownReason::LedgerUnknown);
        }
        if (0 === $deliveredUnits) {
            return $this->unknown(MiningHaulerPlanUnknownReason::NothingDelivered);
        }

        return new MiningFleetStorageShare($deliveredUnits / $minedUnits, $deliveredUnits, $minedUnits);
    }

    /**
     * @param array<int, int> $minedByTypeId
     */
    private function deliveredBy(array $minedByTypeId, MiningFleetStorageContribution $contribution): int
    {
        return match ($contribution->delivery) {
            MiningFleetStorageDelivery::None => 0,
            MiningFleetStorageDelivery::All => array_sum($minedByTypeId),
            MiningFleetStorageDelivery::Partial => $this->partialDelivery($minedByTypeId, $contribution->declaredByTypeId),
        };
    }

    /**
     * @param array<int, int>      $minedByTypeId
     * @param array<int, int>|null $declaredByTypeId
     */
    private function partialDelivery(array $minedByTypeId, ?array $declaredByTypeId): int
    {
        $delivered = 0;
        foreach ($declaredByTypeId ?? [] as $typeId => $declared) {
            $delivered += min($declared, $minedByTypeId[$typeId] ?? 0);
        }

        return $delivered;
    }

    private function unknown(MiningHaulerPlanUnknownReason $reason): MiningFleetStorageShare
    {
        return new MiningFleetStorageShare(unknownReason: $reason);
    }
}
