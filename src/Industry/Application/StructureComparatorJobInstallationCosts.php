<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Cost;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\Runs;

final readonly class StructureComparatorJobInstallationCosts implements JobInstallationCosts
{
    private const bool ALPHA_CLONE = false;

    public function __construct(private StructureTotalCostComparator $comparator)
    {
    }

    public function installationOf(Recipe $recipe, Runs $runs, ?ProductionStructure $structure): Cost
    {
        return $this->comparator->installationOf($recipe, $runs, $structure, self::ALPHA_CLONE);
    }
}
