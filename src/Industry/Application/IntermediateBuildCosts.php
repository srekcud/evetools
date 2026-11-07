<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Runs;

interface IntermediateBuildCosts
{
    /**
     * @param list<ProductionStructure> $structures
     * @param list<int>                 $boughtTypeIds materials of the job, priced at the market
     */
    public function totalCostOf(
        int $typeId,
        Runs $runs,
        BlueprintEfficiency $efficiency,
        array $boughtTypeIds,
        array $structures,
        FavoriteSystems $favoriteSystems,
    ): Cost;
}
