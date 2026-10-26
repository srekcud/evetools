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
        public ?ProductionStructure $ignoredAssignedStructure = null,
    ) {
    }

    public function ignoring(ProductionStructure $ignoredAssignedStructure): self
    {
        return new self($this->structure, $this->status, $this->materialMultiplier, $ignoredAssignedStructure);
    }

    public static function notConfigured(): self
    {
        return new self(null, StructureChoiceStatus::NotConfigured, Multiplier::one());
    }
}
