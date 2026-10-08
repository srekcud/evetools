<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * The T2 BPC obtained by a successful invention (spec R8).
 */
final readonly class InventionOutcome
{
    /** Game rule: an invented T2 BPC starts at ME 2 and TE 4, before the decryptor modifiers. */
    private const int BASE_MATERIAL_EFFICIENCY = 2;
    private const int BASE_TIME_EFFICIENCY = 4;

    private function __construct(
        public Runs $runs,
        public MaterialEfficiency $materialEfficiency,
        public TimeEfficiency $timeEfficiency,
    ) {
    }

    /**
     * @param Runs $baseRuns SDE quantity of the invention product
     */
    public static function of(Runs $baseRuns, ?Decryptor $decryptor): self
    {
        if (null === $decryptor) {
            return new self(
                $baseRuns,
                new MaterialEfficiency(self::BASE_MATERIAL_EFFICIENCY),
                new TimeEfficiency(self::BASE_TIME_EFFICIENCY),
            );
        }

        return new self(
            new Runs($baseRuns->value + $decryptor->runsModifier),
            new MaterialEfficiency(self::BASE_MATERIAL_EFFICIENCY + $decryptor->materialEfficiencyModifier),
            new TimeEfficiency(self::BASE_TIME_EFFICIENCY + $decryptor->timeEfficiencyModifier),
        );
    }
}
