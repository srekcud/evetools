<?php

declare(strict_types=1);

namespace App\Service\Industry\Opportunities;

use App\Service\Industry\SellMarkets\SellMarketComparison;

final readonly class ProductionCapacityCalculator
{
    private const int DAYS_PER_WEEK = 7;
    private const int SLOT_HOURS_PER_WEEK = 168;
    private const int SECONDS_PER_HOUR = 3600;
    private const int ROUNDING_PRECISION = 6;

    public function weekly(float $dailyVolume, float $unitCost, ?int $capitalCap, int $freeSlots, float $secondsPerUnit): WeeklyCapacity
    {
        $marketLimit = $this->marketLimit($dailyVolume);
        $capitalLimit = $this->capitalLimit($capitalCap, $unitCost);
        $slotsLimit = $this->wholeUnits($freeSlots * self::SLOT_HOURS_PER_WEEK * self::SECONDS_PER_HOUR / $this->positive($secondsPerUnit));

        $quantity = $marketLimit;
        $limitedBy = CapacityLimit::Market;
        if (null !== $capitalLimit && $capitalLimit < $quantity) {
            $quantity = $capitalLimit;
            $limitedBy = CapacityLimit::Capital;
        }
        if ($slotsLimit < $quantity) {
            $quantity = $slotsLimit;
            $limitedBy = CapacityLimit::Slots;
        }

        return new WeeklyCapacity($quantity, $limitedBy, $marketLimit, $capitalLimit, $slotsLimit);
    }

    public function marketLimit(float $dailyVolume): int
    {
        return $this->wholeUnits($dailyVolume * SellMarketComparison::SELL_SHARE_OF_DAILY_VOLUME * self::DAYS_PER_WEEK);
    }

    public function capitalLimit(?int $capitalCap, float $unitCost): ?int
    {
        return null === $capitalCap ? null : $this->wholeUnits($capitalCap / $this->positive($unitCost));
    }

    public function iskPerSlotHour(float $profitPerUnit, float $secondsPerUnit): float
    {
        return $profitPerUnit * self::SECONDS_PER_HOUR / $this->positive($secondsPerUnit);
    }

    private function wholeUnits(float $units): int
    {
        return (int) floor(round($units, self::ROUNDING_PRECISION));
    }

    private function positive(float $value): float
    {
        if ($value <= 0.0) {
            throw new \InvalidArgumentException(\sprintf('A cost or a duration per unit must be positive, got %F.', $value));
        }

        return $value;
    }
}
