<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Material quantity consumed by one job (spec R1, R2).
 */
final readonly class MaterialQuantity
{
    /** The game rounds to 2 decimals before ceiling, so float noise like n + 1e-7 does not cost one more unit. */
    private const int GAME_ROUNDING_PRECISION = 2;

    private const int PERCENT = 100;

    /** Reaction formulas cannot be researched, whatever the ME of the consuming job. */
    private const int REACTION_ME_LEVEL = 0;

    /**
     * The game rounds once per job, never per run, and never drops below one unit per run.
     */
    public static function forManufacturingJob(
        Quantity $baseQuantityPerRun,
        Runs $runs,
        MaterialEfficiency $materialEfficiency,
        Multiplier $materialModifier,
    ): Quantity {
        $exactQuantity = $runs->value
            * $baseQuantityPerRun->value
            * (1 - $materialEfficiency->value / self::PERCENT)
            * $materialModifier->value;
        $roundedUpQuantity = (int) ceil(round($exactQuantity, self::GAME_ROUNDING_PRECISION));

        return new Quantity(max($runs->value, $roundedUpQuantity));
    }

    public static function forReactionJob(Quantity $baseQuantityPerRun, Runs $runs, Multiplier $materialModifier): Quantity
    {
        return self::forManufacturingJob($baseQuantityPerRun, $runs, new MaterialEfficiency(self::REACTION_ME_LEVEL), $materialModifier);
    }
}
