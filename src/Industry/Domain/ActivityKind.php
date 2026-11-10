<?php

declare(strict_types=1);

namespace App\Industry\Domain;

enum ActivityKind
{
    case Manufacturing;
    case Reaction;
    case Copying;
    case Invention;
    case ResearchMaterial;
    case ResearchTime;

    public function isLaboratory(): bool
    {
        return match ($this) {
            self::Copying, self::Invention, self::ResearchMaterial, self::ResearchTime => true,
            self::Manufacturing, self::Reaction => false,
        };
    }
}
