<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class LineRentalRates
{
    public const string DAY_FACTOR_KEY = 'location_factor';
    public const float DEFAULT_DAY_FACTOR = 1_000_000.0;
    public const array DEFAULT_RATE_PER_RUN_BY_CATEGORY = [
        'component_t1' => 250.0,
        'module_t1' => 10_000.0,
        'ship_t1' => 100_000.0,
        'module_t2' => 100_000.0,
        'ship_t2' => 2_500_000.0,
        'capital_t1' => 25_000.0,
        'capital_t2' => 125_000.0,
    ];
    private const int VALUE_DECIMALS = 2;

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
        $layers = [$projectOverride ?? [], $userRates];

        $ratePerRunByCategory = [];
        foreach (self::DEFAULT_RATE_PER_RUN_BY_CATEGORY as $categoryKey => $defaultRate) {
            $ratePerRunByCategory[$categoryKey] = self::resolveKey($layers, $categoryKey, $defaultRate);
        }

        return new self(
            self::resolveKey($layers, self::DAY_FACTOR_KEY, self::DEFAULT_DAY_FACTOR),
            $ratePerRunByCategory,
        );
    }

    public function dayFactor(): float
    {
        return $this->dayFactor;
    }

    public function ratePerRun(LineRentalCategory $category): float
    {
        return $this->ratePerRunByCategory[$category->value];
    }

    public function valueOf(LineRentalCategory $category, float $days, int $runs): float
    {
        return round($this->dayFactor * $days + $this->ratePerRun($category) * $runs, self::VALUE_DECIMALS);
    }

    /**
     * @param list<array<string, mixed>> $layers
     */
    private static function resolveKey(array $layers, string $key, float $defaultValue): float
    {
        foreach ($layers as $layer) {
            if (array_key_exists($key, $layer) && (is_int($layer[$key]) || is_float($layer[$key]))) {
                return (float) $layer[$key];
            }
        }

        return $defaultValue;
    }
}
