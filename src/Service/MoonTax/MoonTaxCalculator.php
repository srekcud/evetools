<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Enum\MoonOreRarity;

final readonly class MoonTaxCalculator
{
    /**
     * @param array<int, int>             $quantitiesByTypeId
     * @param array<int, ?MoonOreRarity>  $rarityByTypeId
     * @param array<int, ?float>          $unitPriceByTypeId
     * @param array<string, float>        $ratePercentByRarity keyed by MoonOreRarity::value
     */
    public function calculate(array $quantitiesByTypeId, array $rarityByTypeId, array $unitPriceByTypeId, array $ratePercentByRarity): MoonTax
    {
        return new MoonTax([], null, []);
    }
}
