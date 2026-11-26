<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class PlanetaryStockCalculator
{
    public function calculate(PlanetaryStockReadings $readings): PlanetaryStock
    {
        return new PlanetaryStock([], null, false);
    }
}
