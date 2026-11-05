<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\PlanRequest;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\ProductionPlanner;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\RecipeMemory;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Domain\Double\ActivityMaterialModifiers;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionPlanner::class)]
final class ProductionPlannerSuppliedRecipeMemoryTest extends TestCase
{
    private const int SHIP = 1001;
    private const int MINERAL_A = 3001;
    private const int MINERAL_QUANTITY_IN_PLANNER_CATALOG = 100;
    private const int MINERAL_QUANTITY_IN_SUPPLIED_MEMORY = 250;

    public function testPlanReadsTheRecipesOfTheSuppliedMemory(): void
    {
        $plan = self::plannerOnItsOwnCatalog()->plan(self::request(), self::suppliedMemory());

        $this->assertSame(self::MINERAL_QUANTITY_IN_SUPPLIED_MEMORY, $plan->leaves[self::MINERAL_A]->value);
    }

    public function testMarginalCostReadsTheRecipesOfTheSuppliedMemory(): void
    {
        $marginalCostPlan = self::plannerOnItsOwnCatalog()->marginalCost(self::request(), self::suppliedMemory());

        $this->assertSame([self::MINERAL_A => (float) self::MINERAL_QUANTITY_IN_SUPPLIED_MEMORY], $marginalCostPlan->leaves);
    }

    private static function plannerOnItsOwnCatalog(): ProductionPlanner
    {
        return new ProductionPlanner(new InMemoryBlueprintCatalog(self::shipRecipe(self::MINERAL_QUANTITY_IN_PLANNER_CATALOG)));
    }

    private static function suppliedMemory(): RecipeMemory
    {
        return new RecipeMemory(new InMemoryBlueprintCatalog(self::shipRecipe(self::MINERAL_QUANTITY_IN_SUPPLIED_MEMORY)));
    }

    private static function request(): PlanRequest
    {
        return new PlanRequest(
            targets: [new PlanTarget(self::SHIP, new Runs(1), new BlueprintEfficiency(new MaterialEfficiency(0), new TimeEfficiency(0)))],
            materialModifiers: ActivityMaterialModifiers::none(),
        );
    }

    private static function shipRecipe(int $mineralQuantityPerRun): Recipe
    {
        return new Recipe(
            productTypeId: self::SHIP,
            activity: ActivityKind::Manufacturing,
            outputPerRun: new OutputPerRun(1),
            materials: [new RecipeMaterial(self::MINERAL_A, new Quantity($mineralQuantityPerRun))],
            maxProductionLimit: new Runs(300),
            baseTimeSeconds: 600,
        );
    }
}
