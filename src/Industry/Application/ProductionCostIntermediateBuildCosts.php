<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Runs;

final readonly class ProductionCostIntermediateBuildCosts implements IntermediateBuildCosts
{
    private const int INVENTION_SKILL_LEVEL_WITHOUT_SKILLS = 0;

    public function __construct(private ProductionCostCalculator $productionCosts)
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
        return $this->productionCosts->costOf(new ProductionCostRequest(
            $typeId,
            $runs,
            $efficiency,
            CostMode::Plan,
            $structures,
            $favoriteSystems,
            $boughtTypeIds,
            new InventionSettings(
                self::INVENTION_SKILL_LEVEL_WITHOUT_SKILLS,
                self::INVENTION_SKILL_LEVEL_WITHOUT_SKILLS,
                self::INVENTION_SKILL_LEVEL_WITHOUT_SKILLS,
                null,
            ),
        ))->totalCost;
    }
}
