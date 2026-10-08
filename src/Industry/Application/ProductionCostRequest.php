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
     * @param list<ProductionStructure> $structures        structures of the user
     * @param array<int, ?string>       $productCategories IndustryRigCategory of each job product, null when none
     * @param list<int>                 $blacklist         typeIds bought even when a recipe exists
     * @param ?InventionSettings        $invention         required when the product is invented
     */
    public function __construct(
        public int $productTypeId,
        public Runs $runs,
        public BlueprintEfficiency $efficiency,
        public CostMode $mode,
        public array $structures,
        public FavoriteSystems $favoriteSystems,
        public array $productCategories,
        public array $blacklist = [],
        public ?InventionSettings $invention = null,
        public bool $alphaClone = false,
    ) {
    }
}
