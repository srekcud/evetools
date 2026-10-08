<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Optional item consumed by an invention attempt (spec R8): its modifiers come from the SDE dogma attributes.
 */
final readonly class Decryptor
{
    public function __construct(
        public int $typeId,
        public Multiplier $probabilityMultiplier,
        public int $runsModifier,
        public int $materialEfficiencyModifier,
        public int $timeEfficiencyModifier,
    ) {
    }
}
