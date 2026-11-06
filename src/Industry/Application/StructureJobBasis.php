<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Recipe;
use App\Industry\Domain\Runs;

final readonly class StructureJobBasis
{
    public function __construct(public Recipe $recipe, public Runs $referenceRuns)
    {
    }
}
