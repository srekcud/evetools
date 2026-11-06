<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\Plan;
use App\Industry\Domain\PlanRequest;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\ProductionPlanner;
use App\Industry\Domain\RecipeMemory;
use App\Industry\Domain\Runs;

final readonly class ProductionPlanning
{
    public function __construct(
        private BlueprintCatalog $blueprints,
        private SystemCostIndices $costIndices,
        private RigCategories $rigCategories,
        private ?StructureTotalCostComparator $totalCostComparator = null,
    ) {
    }

    public function planOf(ProductionPlanRequest $request): Plan
    {
        $recipes = new RecipeMemory($this->blueprints);
        $planner = new ProductionPlanner($this->blueprints);
        $selector = new StructureSelector($request->structures, $request->favoriteSystems, $this->costIndices, $this->totalCostComparator, null === $this->totalCostComparator ? StructureSelectionPolicy::favoriteSystemOnly() : $request->selectionPolicy);
        $materialModifiers = new StructureMaterialModifiers($selector, $this->rigCategories, [], self::referenceRunsOf($request->targets));

        $uncappedPlan = $planner->plan(new PlanRequest($request->targets, $materialModifiers, $request->startingStock, $request->blacklist), $recipes);

        $maxRunsByProduct = [];
        foreach ($uncappedPlan->jobs as $job) {
            $maxRunsByProduct[$job->productTypeId] ??= $recipes->recipeFor($job->productTypeId)->maxProductionLimit
                ?? throw new \LogicException(\sprintf('Job of product %d has no recipe.', $job->productTypeId));
        }

        return $planner->plan(
            new PlanRequest($request->targets, $materialModifiers, $request->startingStock, $request->blacklist, $maxRunsByProduct),
            $recipes,
        );
    }

    /**
     * @param list<PlanTarget> $targets
     */
    private static function referenceRunsOf(array $targets): ?Runs
    {
        return 1 === \count($targets) ? $targets[0]->runs : null;
    }
}
