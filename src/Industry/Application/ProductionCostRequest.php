<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Runs;

/**
 * Input of the ProductionCostCalculator.
 */
final readonly class ProductionCostRequest
{
    /**
     * @param list<ProductionStructure>       $structures           structures of the user
     * @param list<int>                       $blacklist            typeIds bought even when a recipe exists
     * @param ?InventionSettings              $invention            required when the product is invented
     * @param array<int, Runs>                $blueprintCopyMaxRuns maxRuns of the BPC by product typeId
     * @param array<int, ProductionStructure> $assignedStructures   structure assigned to the step by product typeId
     */
    public function __construct(
        public int $productTypeId,
        public Runs $runs,
        public BlueprintEfficiency $efficiency,
        public CostMode $mode,
        public array $structures,
        public FavoriteSystems $favoriteSystems,
        public array $blacklist = [],
        public ?InventionSettings $invention = null,
        public bool $alphaClone = false,
        public array $blueprintCopyMaxRuns = [],
        public array $assignedStructures = [],
    ) {
    }
}
