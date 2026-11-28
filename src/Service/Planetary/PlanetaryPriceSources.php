<?php

declare(strict_types=1);

namespace App\Service\Planetary;

final class PlanetaryPriceSources
{
    public static function purchase(string $source, ?float $paid, ?float $hub, ?float $jitaSell, ?float $jitaBuy): ?float
    {
        return null;
    }

    public static function sale(string $source, ?float $hub, ?float $jitaSell, ?float $jitaBuy): ?float
    {
        return null;
    }
}
