<?php

declare(strict_types=1);

namespace App\Service\GroupIndustry;

class MemberDistribution
{
    public function __construct(
        public readonly string $memberId,
        public readonly string $characterName,
        public readonly float $totalCostsEngaged,
        public readonly float $materialCosts,
        public readonly float $jobInstallCosts,
        public readonly float $bpcCosts,
        public readonly float $lineRentalCosts,
        public readonly float $sharePercent,
        public readonly float $profitPart,
        public readonly float $payoutTotal,
    ) {}
}
