<?php

declare(strict_types=1);

namespace App\Service\Industry\Revenue;

final readonly class IndustryRevenueTotals
{
    public function __construct(
        public float $profit,
        public int $projectCount,
        public int $unknownProjectCount,
        public float $capital,
        public ?float $slotHours,
        public ?float $iskPerHour,
    ) {
    }
}
