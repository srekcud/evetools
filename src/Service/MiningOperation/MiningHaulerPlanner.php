<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MiningHaulerPlanUnknownReason;

final readonly class MiningHaulerPlanner
{
    private const float SECONDS_PER_MINUTE = 60.0;

    /**
     * @param list<MiningHaulerTypeRate> $rates
     */
    public function plan(
        ?float $hangarCapacityM3,
        ?float $holdCapacityM3,
        float $share,
        array $rates,
        \DateTimeImmutable $origin,
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $siteEta,
    ): MiningHaulerPlan {
        if (null === $hangarCapacityM3 || null === $holdCapacityM3) {
            return $this->unknown(MiningHaulerPlanUnknownReason::CapacityMissingFromSde);
        }

        $flowing = array_values(array_filter($rates, static fn (MiningHaulerTypeRate $rate): bool => $rate->unitsPerMinute > 0.0));
        if ([] === $flowing) {
            return $this->unknown(MiningHaulerPlanUnknownReason::SiteRateUnknown);
        }

        foreach ($flowing as $rate) {
            if (null === $rate->rawVolumeM3 || (true === $rate->compressible && null === $rate->transportedVolumeM3)) {
                return $this->unknown(MiningHaulerPlanUnknownReason::VolumeMissing);
            }
        }

        foreach ($flowing as $rate) {
            if (null === $rate->compressible) {
                return $this->unknown(MiningHaulerPlanUnknownReason::CompressionUnknown);
            }
        }

        $inflow = 0.0;
        $transport = 0.0;
        $rawTypeIds = [];
        foreach ($flowing as $rate) {
            $received = $rate->unitsPerMinute * $share;
            $inflow += $received * $rate->rawVolumeM3;
            $transport += $received * ($rate->compressible ? $rate->transportedVolumeM3 : $rate->rawVolumeM3);
            if (!$rate->compressible) {
                $rawTypeIds[] = $rate->typeId;
            }
        }

        $elapsedMinutes = max(0.0, ($now->getTimestamp() - $origin->getTimestamp()) / self::SECONDS_PER_MINUTE);
        $fill = min($hangarCapacityM3, $elapsedMinutes * $inflow);
        $isFull = $fill >= $hangarCapacityM3;

        $toMove = null;
        $trips = null;
        if (null !== $siteEta) {
            $remainingMinutes = max(0.0, ($siteEta->getTimestamp() - $now->getTimestamp()) / self::SECONDS_PER_MINUTE);
            $toMove = $elapsedMinutes * $transport + $remainingMinutes * $transport;
            $trips = max(1, (int) ceil($toMove / $holdCapacityM3));
        }

        return new MiningHaulerPlan(
            inflowM3PerMinute: $inflow,
            fillM3: $fill,
            fillRatio: $fill / $hangarCapacityM3,
            fullInMinutes: $isFull ? 0.0 : ($hangarCapacityM3 - $fill) / $inflow,
            isFull: $isFull,
            transportM3PerMinute: $transport,
            toMoveM3: $toMove,
            trips: $trips,
            rawTypeIds: $rawTypeIds,
        );
    }

    private function unknown(MiningHaulerPlanUnknownReason $reason): MiningHaulerPlan
    {
        return new MiningHaulerPlan($reason);
    }
}
