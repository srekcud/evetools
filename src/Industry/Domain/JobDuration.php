<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Duration of one job, in seconds (spec R7).
 */
final readonly class JobDuration
{
    private const int PERCENT = 100;

    /** Reaction formulas cannot be researched: no TE. */
    private const int REACTION_TE_LEVEL = 0;

    public function __construct(public int $seconds)
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException(\sprintf('A job duration cannot be negative, got %d seconds.', $seconds));
        }
    }

    /**
     * One single round on the whole job, never a ceil per run multiplied by the runs.
     *
     * @param Multiplier $timeModifier skills × structure time × rig time modifiers
     */
    public static function forManufacturingJob(int $baseTimePerRunSeconds, Runs $runs, TimeEfficiency $timeEfficiency, Multiplier $timeModifier): self
    {
        if ($baseTimePerRunSeconds < 0) {
            throw new \InvalidArgumentException(\sprintf('A base time cannot be negative, got %d seconds.', $baseTimePerRunSeconds));
        }

        return new self((int) round(
            $runs->value
            * $baseTimePerRunSeconds
            * (1 - $timeEfficiency->value / self::PERCENT)
            * $timeModifier->value,
        ));
    }

    public static function forReactionJob(int $baseTimePerRunSeconds, Runs $runs, Multiplier $timeModifier): self
    {
        return self::forManufacturingJob($baseTimePerRunSeconds, $runs, new TimeEfficiency(self::REACTION_TE_LEVEL), $timeModifier);
    }
}
