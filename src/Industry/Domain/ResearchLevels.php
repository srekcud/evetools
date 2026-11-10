<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class ResearchLevels
{
    public const int MINIMUM_LEVEL = 1;
    public const int MAXIMUM_LEVEL = 10;

    private const array RANK_ONE_SECONDS_BY_LEVEL = [
        1 => 105,
        2 => 250,
        3 => 595,
        4 => 1_414,
        5 => 3_360,
        6 => 8_000,
        7 => 19_000,
        8 => 45_255,
        9 => 107_700,
        10 => 256_000,
    ];

    public static function rankOneSeconds(int $targetLevel): int
    {
        if ($targetLevel < self::MINIMUM_LEVEL || $targetLevel > self::MAXIMUM_LEVEL) {
            throw new \InvalidArgumentException(\sprintf('A research job targets a level between %d and %d, got %d.', self::MINIMUM_LEVEL, self::MAXIMUM_LEVEL, $targetLevel));
        }

        return self::RANK_ONE_SECONDS_BY_LEVEL[$targetLevel];
    }

    public static function costMultiplier(int $targetLevel): float
    {
        return self::rankOneSeconds($targetLevel) / self::RANK_ONE_SECONDS_BY_LEVEL[self::MINIMUM_LEVEL];
    }
}
