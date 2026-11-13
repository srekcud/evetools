<?php

declare(strict_types=1);

namespace App\Service\Sde;

final class CelestialNameFormatter
{
    private const array ROMAN_NUMERALS = [
        1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC',
        50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I',
    ];

    private const int MAXIMUM_ROMAN_VALUE = 3999;

    public static function planetName(string $solarSystemName, int $celestialIndex): string
    {
        return sprintf('%s %s', $solarSystemName, self::roman($celestialIndex));
    }

    public static function moonName(string $solarSystemName, int $celestialIndex, int $orbitIndex): string
    {
        return sprintf('%s - Moon %d', self::planetName($solarSystemName, $celestialIndex), $orbitIndex);
    }

    private static function roman(int $number): string
    {
        if ($number < 1 || $number > self::MAXIMUM_ROMAN_VALUE) {
            throw new \InvalidArgumentException(sprintf('Celestial index %d has no roman numeral', $number));
        }

        $roman = '';
        foreach (self::ROMAN_NUMERALS as $value => $symbol) {
            while ($number >= $value) {
                $roman .= $symbol;
                $number -= $value;
            }
        }

        return $roman;
    }
}
