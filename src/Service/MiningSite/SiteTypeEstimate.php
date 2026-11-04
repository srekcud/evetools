<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Enum\MiningSiteEtaUnknownReason;

final readonly class SiteTypeEstimate
{
    public function __construct(
        public int $typeId,
        public int $units,
        public ?float $volumeM3 = null,
        public ?float $unitPrice = null,
        public ?float $remainingValue = null,
        public ?float $recentRate = null,
        public ?\DateTimeImmutable $recentEta = null,
        public ?float $trendRate = null,
        public ?\DateTimeImmutable $trendEta = null,
        public ?float $maxDeviationUnits = null,
        public ?float $maxDeviationPercent = null,
        public bool $irregular = false,
        public ?MiningSiteEtaUnknownReason $etaUnknownReason = null,
        public ?\DateTimeImmutable $lastPresentAt = null,
        public ?\DateTimeImmutable $firstAbsentAt = null,
    ) {
    }

    public function isDepleted(): bool
    {
        return $this->firstAbsentAt !== null;
    }
}
