<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\IntermediateBuildCosts;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Cost;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Runs;

final class FixedIntermediateBuildCosts implements IntermediateBuildCosts
{
    /** @var list<array{typeId: int, runs: Runs, efficiency: BlueprintEfficiency, boughtTypeIds: list<int>, structures: array, favoriteSystems: FavoriteSystems}> */
    public array $calls = [];

    /**
     * @param array<int, Cost> $totalCostsByTypeId
     */
    public function __construct(private readonly array $totalCostsByTypeId = [])
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
        $this->calls[] = [
            'typeId' => $typeId,
            'runs' => $runs,
            'efficiency' => $efficiency,
            'boughtTypeIds' => $boughtTypeIds,
            'structures' => $structures,
            'favoriteSystems' => $favoriteSystems,
        ];

        return $this->totalCostsByTypeId[$typeId] ?? Cost::unknown(MissingData::adjustedPrice($typeId));
    }
}
