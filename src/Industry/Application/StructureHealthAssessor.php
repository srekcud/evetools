<?php

declare(strict_types=1);

namespace App\Industry\Application;

final readonly class StructureHealthAssessor
{
    public const float LOW_FUEL_THRESHOLD_DAYS = 3.0;

    private const int SECONDS_PER_DAY = 86_400;
    private const int FUEL_DAYS_DECIMALS = 1;

    /**
     * @param list<string> $offlineServiceNames
     */
    public function assess(?\DateTimeImmutable $fuelExpiresAt, array $offlineServiceNames, \DateTimeImmutable $now): StructureHealth
    {
        $fuelDays = null === $fuelExpiresAt ? null : $this->fuelDaysUntil($fuelExpiresAt, $now);

        return new StructureHealth($this->stateOf($fuelDays, $offlineServiceNames), $fuelDays, $offlineServiceNames);
    }

    /**
     * @param list<string> $offlineServiceNames
     */
    private function stateOf(?float $fuelDays, array $offlineServiceNames): StructureHealthState
    {
        if ([] !== $offlineServiceNames) {
            return StructureHealthState::OfflineService;
        }
        if (null === $fuelDays) {
            return StructureHealthState::Unknown;
        }

        return $fuelDays < self::LOW_FUEL_THRESHOLD_DAYS ? StructureHealthState::LowFuel : StructureHealthState::Ok;
    }

    private function fuelDaysUntil(\DateTimeImmutable $fuelExpiresAt, \DateTimeImmutable $now): float
    {
        $days = ($fuelExpiresAt->getTimestamp() - $now->getTimestamp()) / self::SECONDS_PER_DAY;

        return max(0.0, round($days, self::FUEL_DAYS_DECIMALS));
    }
}
