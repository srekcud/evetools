<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\IntermediateBuildCosts;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Cost;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Runs;

final class BuildCostsByMaterialEfficiency implements IntermediateBuildCosts
{
    public array $calls = [];

    public function __construct(private readonly array $totalCostsByTypeIdThenMaterialEfficiency = [])
    {
    }

    public function totalCostOf(
        int $typeId,
        Runs $runs,
        BlueprintEfficiency $efficiency,
        array $boughtTypeIds,
        array $structures,
        FavoriteSystems $favoriteSystems,
    ): Cost {
        $materialEfficiency = $efficiency->materialEfficiency->value;
        $this->calls[] = [
            'typeId' => $typeId,
            'runs' => $runs->value,
            'materialEfficiency' => $materialEfficiency,
            'timeEfficiency' => $efficiency->timeEfficiency->value,
            'boughtTypeIds' => $boughtTypeIds,
        ];

        return $this->totalCostsByTypeIdThenMaterialEfficiency[$typeId][$materialEfficiency] ?? Cost::unknown(MissingData::marketPrice($typeId));
    }
}
