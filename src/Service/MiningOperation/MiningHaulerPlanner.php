<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningHaulerPlanner
{
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
        return new MiningHaulerPlan();
    }
}
