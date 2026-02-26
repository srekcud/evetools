<?php

declare(strict_types=1);

namespace App\Service\GroupIndustry;

class DistributionResult
{
    /**
     * @param MemberDistribution[] $members
     */
    public function __construct(
        public readonly float $totalRevenue,
        public readonly float $brokerFee,
        public readonly float $salesTax,
        public readonly float $netRevenue,
        public readonly float $totalProjectCost,
        public readonly float $marginPercent,
        public readonly array $members,
    ) {}
}
