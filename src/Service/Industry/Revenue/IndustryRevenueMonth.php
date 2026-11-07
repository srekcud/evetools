<?php

declare(strict_types=1);

namespace App\Service\Industry\Revenue;

final readonly class IndustryRevenueMonth
{
    public function __construct(
        public string $month,
        public int $projectCount,
        public int $unknownProjectCount,
        public float $profit,
        public float $capital,
        public ?float $marginPercent,
        public ?float $slotHours,
        public ?float $iskPerHour,
    ) {
    }
}
