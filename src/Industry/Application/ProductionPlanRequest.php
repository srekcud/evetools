<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Isk;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\Quantity;

final readonly class ProductionPlanRequest
{
    /**
     * @param list<PlanTarget>          $targets
     * @param list<ProductionStructure> $structures    structures of the user
     * @param list<int>                 $blacklist     typeIds bought even when a recipe exists
     * @param array<int, Quantity>      $startingStock by typeId, consumed before the runs are computed (R9)
     * @param array<int, OwnedBlueprintUse> $ownedBlueprints by product typeId
     */
    public function __construct(
        public array $targets,
        public array $structures,
        public FavoriteSystems $favoriteSystems,
        public array $blacklist = [],
        public array $startingStock = [],
        public StructureSelectionPolicy $selectionPolicy = new StructureSelectionPolicy(false, new Isk(0.0)),
        public array $ownedBlueprints = [],
    ) {
    }
}
