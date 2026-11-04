<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

final readonly class MiningSiteHistorySummary
{
    public function __construct(
        public int $observedSeconds,
        public ?float $firstVolumeM3,
        public ?float $firstValue,
        public ?float $averageTrendRate,
    ) {
    }
}
