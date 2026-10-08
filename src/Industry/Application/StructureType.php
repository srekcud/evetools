<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;

/**
 * Family of an Upwell production structure: the activities it can run (spec R4, D3).
 */
enum StructureType
{
    /** Raitaru / Azbel / Sotiyo. */
    case EngineeringComplex;
    /** Athanor / Tatara. */
    case Refinery;

    public function canRun(ActivityKind $activity): bool
    {
        return match ($this) {
            self::EngineeringComplex => ActivityKind::Reaction !== $activity,
            self::Refinery => ActivityKind::Reaction === $activity,
        };
    }
}
