<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\BlueprintEfficiency;
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
        return $this->planWithStructuresOf($request)->plan;
    }

    public function planWithStructuresOf(ProductionPlanRequest $request): PlannedProduction
    {
        $recipes = new RecipeMemory($this->blueprints);
        $planner = new ProductionPlanner($this->blueprints);
        $selector = new StructureSelector($request->structures, $request->favoriteSystems, $this->costIndices, $this->totalCostComparator, $request->selectionPolicy);
        $targets = self::targetsWithOwnedEfficiency($request, $recipes);
        $materialModifiers = new StructureMaterialModifiers($selector, $this->rigCategories, [], self::referenceRunsOf($targets));

        $ownedEfficiencies = array_map(static fn (OwnedBlueprintUse $use): BlueprintEfficiency => $use->efficiency, $request->ownedBlueprints);

        $uncappedPlan = $planner->plan(new PlanRequest($targets, $materialModifiers, $request->startingStock, $request->blacklist, intermediateEfficiencies: $ownedEfficiencies), $recipes);

        $maxRunsByProduct = [];
        foreach ($uncappedPlan->jobs as $job) {
            if ($request->ownedBlueprints[$job->productTypeId]->original ?? false) {
                continue;
            }
            $maxRunsByProduct[$job->productTypeId] = $recipes->recipeFor($job->productTypeId)->maxProductionLimit
                ?? throw new \LogicException(\sprintf('Job of product %d has no recipe.', $job->productTypeId));
        }

        $plan = $planner->plan(
            new PlanRequest($targets, $materialModifiers, $request->startingStock, $request->blacklist, $maxRunsByProduct, $ownedEfficiencies),
            $recipes,
        );

        $structureChoices = [];
        foreach ($plan->jobs as $job) {
            $recipe = $recipes->recipeFor($job->productTypeId)
                ?? throw new \LogicException(\sprintf('Job of product %d has no recipe.', $job->productTypeId));
            $structureChoices[PlannedProduction::choiceKey($job->productTypeId, $job->activity)] = $materialModifiers->choiceFor($recipe);
        }

        return new PlannedProduction($plan, $structureChoices, $selector->select(ActivityKind::Copying, null));
    }

    /**
     * @param list<PlanTarget> $targets
     */
    private static function referenceRunsOf(array $targets): ?Runs
    {
        return 1 === \count($targets) ? $targets[0]->runs : null;
    }

    /**
     * @return list<PlanTarget>
     */
    private static function targetsWithOwnedEfficiency(ProductionPlanRequest $request, RecipeMemory $recipes): array
    {
        $targets = [];
        foreach ($request->targets as $target) {
            $owned = $request->ownedBlueprints[$target->productTypeId] ?? null;
            if (null === $owned || ActivityKind::Reaction === $recipes->recipeFor($target->productTypeId)?->activity) {
                $targets[] = $target;
                continue;
            }

            $key = \sprintf('owned:%d:%d:%d', $target->productTypeId, $owned->efficiency->materialEfficiency->value, $owned->efficiency->timeEfficiency->value);
            $runs = ($targets[$key]->runs->value ?? 0) + $target->runs->value;
            $targets[$key] = new PlanTarget($target->productTypeId, new Runs($runs), $owned->efficiency);
        }

        return array_values($targets);
    }
}
