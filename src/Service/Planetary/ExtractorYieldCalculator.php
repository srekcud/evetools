<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Repository\Sde\DgmAttributeTypeRepository;

class ExtractorYieldCalculator
{
    private const DECAY_FACTOR_ATTRIBUTE_ID = 1683;
    private const NOISE_FACTOR_ATTRIBUTE_ID = 1687;
    private const BAR_SECONDS = 900;
    private const SECONDS_PER_DAY = 86400;
    private const PHASE_SHIFT_EXPONENT = 0.7;

    public function __construct(
        private readonly DgmAttributeTypeRepository $attributeTypes,
    ) {
    }

    /**
     * @return list<float>|null
     */
    public function cycleYields(int $quantityPerCycle, int $cycleTime, int $cycleCount): ?array
    {
        $decayFactor = $this->defaultValueOf(self::DECAY_FACTOR_ATTRIBUTE_ID);
        $noiseFactor = $this->defaultValueOf(self::NOISE_FACTOR_ATTRIBUTE_ID);
        if ($decayFactor === null || $noiseFactor === null) {
            return null;
        }

        $barWidth = $cycleTime / self::BAR_SECONDS;
        $phaseShift = $quantityPerCycle ** self::PHASE_SHIFT_EXPONENT;

        $yields = [];
        for ($cycle = 0; $cycle < $cycleCount; ++$cycle) {
            $elapsed = ($cycle + 0.5) * $barWidth;
            $decayed = $quantityPerCycle / (1 + $elapsed * $decayFactor);
            $noise = max(
                (cos($phaseShift + $elapsed / 12) + cos($phaseShift / 2 + $elapsed * 0.2) + cos($elapsed * 0.5)) / 3,
                0.0,
            );
            $yields[] = $barWidth * $decayed * (1 + $noiseFactor * $noise);
        }

        return $yields;
    }

    public function programDailyAverage(int $quantityPerCycle, int $cycleTime, ?\DateTimeImmutable $installTime, ?\DateTimeImmutable $expiryTime): ?float
    {
        if ($installTime === null || $expiryTime === null || $cycleTime <= 0) {
            return null;
        }

        $durationSeconds = $expiryTime->getTimestamp() - $installTime->getTimestamp();
        if ($durationSeconds <= 0) {
            return null;
        }

        $yields = $this->cycleYields($quantityPerCycle, $cycleTime, intdiv($durationSeconds, $cycleTime));
        if ($yields === null) {
            return null;
        }

        return array_sum($yields) / ($durationSeconds / self::SECONDS_PER_DAY);
    }

    public function yieldBetween(int $quantityPerCycle, int $cycleTime, ?\DateTimeImmutable $installTime, ?\DateTimeImmutable $expiryTime, \DateTimeImmutable $from, \DateTimeImmutable $to): ?float
    {
        if ($installTime === null || $expiryTime === null || $cycleTime <= 0) {
            return null;
        }

        $durationSeconds = $expiryTime->getTimestamp() - $installTime->getTimestamp();
        if ($durationSeconds <= 0) {
            return null;
        }

        $yields = $this->cycleYields($quantityPerCycle, $cycleTime, intdiv($durationSeconds, $cycleTime));
        if ($yields === null) {
            return null;
        }

        $total = 0.0;
        foreach ($yields as $cycle => $cycleYield) {
            $cycleEnd = $installTime->getTimestamp() + ($cycle + 1) * $cycleTime;
            if ($cycleEnd > $from->getTimestamp() && $cycleEnd <= $to->getTimestamp()) {
                $total += $cycleYield;
            }
        }

        return $total;
    }

    private function defaultValueOf(int $attributeId): ?float
    {
        return $this->attributeTypes->find($attributeId)?->getDefaultValue();
    }
}
