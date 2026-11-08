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

    public static function importCostOf(int $typeId, int $quantity, ?float $volumePerUnit, ?int $jumps): Cost
    {
        $missingData = [];
        if (null === $jumps) {
            $missingData[] = MissingData::jumpRoute();
        }
        if (null === $volumePerUnit) {
            $missingData[] = MissingData::itemVolume($typeId);
        }
        if ([] !== $missingData) {
            return Cost::unknown(...$missingData);
        }

        return Cost::known(new Isk($volumePerUnit * $quantity * $jumps * self::IMPORT_ISK_PER_CUBIC_METER_JUMP));
    }
}
