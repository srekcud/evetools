<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\Plan;

final readonly class ProductionPlanning
{
    public function __construct(
        private BlueprintCatalog $blueprints,
        private SystemCostIndices $costIndices,
        private RigCategories $rigCategories,
    ) {
    }

    public function planOf(ProductionPlanRequest $request): Plan
    {
        return new Plan([], [], []);
    }
}
