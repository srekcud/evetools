<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;

final class Delivery
{
    public static function bridgeCostOf(int $typeId, int $quantity, ?float $volumePerUnit, ?int $fromSolarSystemId, ?int $toSolarSystemId, ?CourierHub $hub): Cost
    {
        $missingData = [];
        if (null === $volumePerUnit) {
            $missingData[] = MissingData::itemVolume($typeId);
        }
        if (null === $fromSolarSystemId || null === $toSolarSystemId) {
            $missingData[] = MissingData::jumpRoute();
        } elseif (null === $hub && $fromSolarSystemId !== $toSolarSystemId) {
            $missingData[] = MissingData::courierHub();
        }
        if ([] !== $missingData) {
            return Cost::unknown(...$missingData);
        }
        if (null === $hub) {
            return Cost::known(new Isk(0.0));
        }

        return $hub->bridgeCostOf($volumePerUnit * $quantity, $fromSolarSystemId, $toSolarSystemId);
    }
}
