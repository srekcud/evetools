<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class PlanetaryStockPriceCalculator
{
    /**
     * @param list<PlanetaryStockPriceReading> $readings
     *
     * @return list<PlanetaryStockPriceRow>
     */
    public function calculate(array $readings): array
    {
        return [];
    }
}
