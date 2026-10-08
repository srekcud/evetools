<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Multiplier;

/**
 * Structure chosen for a job and its material multiplier. No structure: neutral multiplier, NotConfigured.
 */
final readonly class StructureChoice
{
    public function __construct(
        public ?ProductionStructure $structure,
        public StructureChoiceStatus $status,
        public Multiplier $materialMultiplier,
    ) {
    }

    public static function notConfigured(): self
    {
        return new self(null, StructureChoiceStatus::NotConfigured, Multiplier::one());
    }
}
