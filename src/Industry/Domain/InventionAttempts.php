<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class InventionAttempts
{
    private const int NO_SKILL_LEVEL = 0;
    private const int FLOATING_POINT_NOISE_DECIMALS = 9;

    public static function needed(Runs $runsToInvent, int $baseRuns, float $baseProbability, ?Decryptor $decryptor): int
    {
        $chance = InventionChance::of($baseProbability, self::NO_SKILL_LEVEL, self::NO_SKILL_LEVEL, self::NO_SKILL_LEVEL, $decryptor);
        $outcome = InventionOutcome::of(new Runs($baseRuns), $decryptor);

        return (int) ceil(round($runsToInvent->value / ($chance->value * $outcome->runs->value), self::FLOATING_POINT_NOISE_DECIMALS));
    }
}
