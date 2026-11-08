<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class LineRentalRates
{
    public const string DAY_FACTOR_KEY = 'location_factor';

    /**
     * @param array<string, float> $ratePerRunByCategory
     */
    private function __construct(
        private float $dayFactor,
        private array $ratePerRunByCategory,
    ) {
    }

    /**
     * @param array<string, int|float>|null $projectOverride
     * @param array<string, int|float> $userRates
     */
    public static function resolve(?array $projectOverride, array $userRates): self
    {
        return new self(0.0, []);
    }

    public function dayFactor(): float
    {
        return $this->dayFactor;
    }

    public function ratePerRun(LineRentalCategory $category): float
    {
        return $this->ratePerRunByCategory[$category->value] ?? 0.0;
    }

    public function valueOf(LineRentalCategory $category, float $days, int $runs): float
    {
        return 0.0;
    }
}
