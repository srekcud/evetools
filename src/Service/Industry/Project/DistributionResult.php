<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

class DistributionResult
{
    /**
     * @param ParticipantDistribution[] $members
     * @param list<UnsoldProduct> $unsoldProducts
     */
    public function __construct(
        public readonly float $totalRevenue,
        public readonly ?float $brokerFee,
        public readonly ?float $salesTax,
        public readonly ?float $netRevenue,
        public readonly ?float $totalProjectCost,
        public readonly ?float $marginPercent,
        public readonly array $members,
        public readonly bool $awaitingSales = false,
        public readonly array $unsoldProducts = [],
        public readonly ?float $projectedMarginPercent = null,
        public readonly float $marketRevenue = 0.0,
        public readonly float $contractRevenue = 0.0,
        public readonly ?float $contractFees = 0.0,
        public readonly ?float $breakEvenPerUnit = null,
        public readonly ?string $breakEvenUnknownReason = null,
        public readonly ?float $costPerUnit = null,
        public readonly ?string $costPerUnitUnknownReason = null,
    ) {}
}
