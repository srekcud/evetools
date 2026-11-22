<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\PartialPrice;

final class JitaPurchase
{
    public const float IMPORT_ISK_PER_CUBIC_METER_JUMP = StructureTotalCostComparator::TRANSPORT_ISK_PER_CUBIC_METER_JUMP;
    public const int JITA_SOLAR_SYSTEM_ID = 30_000_142;

    public static function priceCostOf(int $typeId, int $quantity, ?WeightedPrice $jitaPrice): Cost
    {
        if (null === $jitaPrice) {
            return Cost::unknown(MissingData::marketPrice($typeId));
        }

        $cost = Cost::known(new Isk($quantity * $jitaPrice->unitPrice->amount));

        return $jitaPrice->coverage->isPartial()
            ? $cost->withPartialPrice(new PartialPrice($typeId, $jitaPrice->coverage))
            : $cost;
    }

    public static function importCostOf(int $typeId, int $quantity, ?float $volumePerUnit, ?int $destinationSolarSystemId, ?CourierHub $hub): Cost
    {
        $missingData = [];
        if (null === $destinationSolarSystemId) {
            $missingData[] = MissingData::jumpRoute();
        }
        if (null === $volumePerUnit) {
            $missingData[] = MissingData::itemVolume($typeId);
        }
        $needsNoCourier = self::JITA_SOLAR_SYSTEM_ID === $destinationSolarSystemId;
        if (null === $hub && !$needsNoCourier) {
            $missingData[] = MissingData::courierHub();
        }
        if ([] !== $missingData) {
            return Cost::unknown(...$missingData);
        }
        if (null === $hub) {
            return Cost::known(new Isk(0.0));
        }

        return $hub->transportCostOf($volumePerUnit * $quantity, $destinationSolarSystemId);
    }
}
