<?php

declare(strict_types=1);

namespace App\Service\Planetary;

final class PlanetaryPriceSources
{
    public const string JITA_SELL = 'jita-sell';
    public const string JITA_BUY = 'jita-buy';
    public const string HUB = 'hub';
    public const string PAID = 'paid';
    public const string BEST = 'best';
    public const string DEFAULT_PURCHASE = self::PAID;
    public const string DEFAULT_SALE = self::HUB;

    public static function purchase(string $source, ?float $paid, ?float $hub, ?float $jitaSell, ?float $jitaBuy): ?float
    {
        return match ($source) {
            self::JITA_SELL => $jitaSell,
            self::JITA_BUY => $jitaBuy,
            self::HUB => $hub,
            self::PAID => $paid,
            self::BEST => self::lowestKnown([$paid, $hub, $jitaSell]),
            default => throw new \InvalidArgumentException(sprintf('Unknown purchase source "%s"', $source)),
        };
    }

    public static function sale(string $source, ?float $hub, ?float $jitaSell, ?float $jitaBuy): ?float
    {
        return match ($source) {
            self::JITA_SELL => $jitaSell,
            self::JITA_BUY => $jitaBuy,
            self::HUB => $hub,
            default => throw new \InvalidArgumentException(sprintf('Unknown sale source "%s"', $source)),
        };
    }

    /**
     * @param list<float|null> $prices
     */
    private static function lowestKnown(array $prices): ?float
    {
        $known = array_filter($prices, static fn (?float $price): bool => $price !== null);

        return $known === [] ? null : min($known);
    }
}
