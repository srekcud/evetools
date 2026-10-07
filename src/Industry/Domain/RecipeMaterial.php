<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * One material of a recipe: its SDE base quantity per run, before ME and bonuses.
 */
final readonly class RecipeMaterial
{
    public function __construct(public int $typeId, public Quantity $baseQuantityPerRun)
    {
    }
}
