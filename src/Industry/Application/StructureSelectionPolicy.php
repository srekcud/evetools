<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Isk;

final readonly class StructureSelectionPolicy
{
    public function __construct(public bool $acceptsOutsideFavoriteSystem, public Isk $minimumSaving)
    {
    }

    public static function favoriteSystemOnly(): self
    {
        return new self(false, new Isk(0.0));
    }
}
