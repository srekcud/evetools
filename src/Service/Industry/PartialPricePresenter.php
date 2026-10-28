<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Industry\Domain\Cost;
use App\Industry\Domain\PartialPrice;

final class PartialPricePresenter
{
    private const float SMALLEST_DISPLAYED_PERCENT = 0.1;
    private const float LARGEST_DISPLAYED_PERCENT = 99.9;
    private const float PERCENT_PER_FRACTION = 100.0;
    private const int DISPLAYED_DECIMALS = 1;

    /**
     * @return list<array{reason: string, typeId: int, coveragePercent: float}>
     */
    public static function of(Cost $cost): array
    {
        return array_map(
            static fn (PartialPrice $partialPrice): array => [
                'reason' => $partialPrice->reason,
                'typeId' => $partialPrice->typeId,
                'coveragePercent' => self::coveragePercentOfPartialPrice($partialPrice),
            ],
            $cost->partialPrices,
        );
    }

    public static function coveragePercentOf(Cost $cost, int $typeId): ?float
    {
        foreach ($cost->partialPrices as $partialPrice) {
            if ($partialPrice->typeId === $typeId) {
                return self::coveragePercentOfPartialPrice($partialPrice);
            }
        }

        return null;
    }

    private static function coveragePercentOfPartialPrice(PartialPrice $partialPrice): float
    {
        $roundedPercent = round($partialPrice->coverage->fraction * self::PERCENT_PER_FRACTION, self::DISPLAYED_DECIMALS);

        return min(self::LARGEST_DISPLAYED_PERCENT, max(self::SMALLEST_DISPLAYED_PERCENT, $roundedPercent));
    }
}
