<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;

final readonly class CourierHub
{
    public function __construct(
        public int $solarSystemId,
        public float $iskPerCubicMeterFromJita,
        public float $jumpBridgeIskPerCubicMeter,
    ) {
    }

    public function transportCostOf(float $cubicMeters, ?int $siteSolarSystemId): Cost
    {
        if (null === $siteSolarSystemId) {
            return Cost::unknown(MissingData::jumpRoute());
        }
        if (JitaPurchase::JITA_SOLAR_SYSTEM_ID === $siteSolarSystemId) {
            return Cost::known(new Isk(0.0));
        }

        $iskPerCubicMeter = $siteSolarSystemId === $this->solarSystemId
            ? $this->iskPerCubicMeterFromJita
            : $this->iskPerCubicMeterFromJita + $this->jumpBridgeIskPerCubicMeter;

        return Cost::known(new Isk($cubicMeters * $iskPerCubicMeter));
    }

    public function bridgeCostOf(float $cubicMeters, ?int $fromSolarSystemId, ?int $toSolarSystemId): Cost
    {
        if (null === $fromSolarSystemId || null === $toSolarSystemId) {
            return Cost::unknown(MissingData::jumpRoute());
        }
        if ($fromSolarSystemId === $toSolarSystemId) {
            return Cost::known(new Isk(0.0));
        }

        return Cost::known(new Isk($cubicMeters * $this->jumpBridgeIskPerCubicMeter));
    }
}
