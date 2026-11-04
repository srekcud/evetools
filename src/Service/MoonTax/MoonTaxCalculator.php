<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Enum\MoonOreRarity;

final readonly class MoonTaxCalculator
{
    private const int MONEY_DECIMALS = 2;
    private const float PERCENT_DIVISOR = 100.0;

    /**
     * @param array<int, int>            $quantitiesByTypeId
     * @param array<int, ?MoonOreRarity> $rarityByTypeId
     * @param array<int, ?float>         $unitPriceByTypeId
     * @param array<string, float>       $ratePercentByRarity keyed by MoonOreRarity::value
     */
    public function calculate(array $quantitiesByTypeId, array $rarityByTypeId, array $unitPriceByTypeId, array $ratePercentByRarity): MoonTax
    {
        $lines = [];
        $unknownTypeIds = [];
        $total = 0.0;

        foreach ($quantitiesByTypeId as $typeId => $quantity) {
            $rarity = $rarityByTypeId[$typeId] ?? null;
            $unitPrice = $unitPriceByTypeId[$typeId] ?? null;
            if ($rarity === null || $unitPrice === null) {
                $unknownTypeIds[] = $typeId;

                continue;
            }

            $ratePercent = $ratePercentByRarity[$rarity->value];
            $tax = round($quantity * $unitPrice * $ratePercent / self::PERCENT_DIVISOR, self::MONEY_DECIMALS);
            $lines[] = ['typeId' => $typeId, 'quantity' => $quantity, 'unitPrice' => $unitPrice, 'ratePercent' => $ratePercent, 'tax' => $tax];
            $total += $tax;
        }

        if ($unknownTypeIds !== []) {
            sort($unknownTypeIds);

            return new MoonTax($lines, null, $unknownTypeIds);
        }

        return new MoonTax($lines, round($total, self::MONEY_DECIMALS), []);
    }
}
