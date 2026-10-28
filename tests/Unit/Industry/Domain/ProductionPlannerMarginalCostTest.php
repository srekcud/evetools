<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\MarginalCostPlan;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\PlanRequest;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\ProductionPlanner;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Domain\Double\ActivityMaterialModifiers;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Marginal cost mode (R12, D4b): fractional runs = demand / output per run, quantity = runs × base × (1 − ME/100) × modifier,
 * without rounding, without the one-unit-per-run minimum, without surplus. Consumers: Profit Margins, scanner, pivot.
 *
 * Hail L (§4.2), 10 runs ME 2, root modifier 0.99 × 0.9496, R.A.M. ME 10 in a Raitaru without rig (0.99),
 * Fernite Carbide and Fullerides bought.
 */
#[CoversClass(ProductionPlanner::class)]
#[CoversClass(MarginalCostPlan::class)]
final class ProductionPlannerMarginalCostTest extends TestCase
{
    private const int HAIL_L = 12779;
    private const int RAM_AMMUNITION_TECH = 11476;

    /*
     * Synthetic catalog, no structure bonus (every modifier is 1); mineral A is consumed by two recipes:
     *   ship      (manufacturing, 1 / run)   : 10 component + 50 polymer + 100 mineral A
     *   component (manufacturing, 2 / run)   : 5 mineral A
     *   polymer   (reaction, 200 / run)      : 100 gas
     * Target: 2 ship runs at ME 0 → demand 20 component, 100 polymer, 200 mineral A.
     */
    private const int SHIP = 1001;
    private const int MODULE = 1003;
    private const int COMPONENT = 2001;
    private const int POLYMER = 2002;
    private const int ASSEMBLY = 2003;
    private const int MINERAL_A = 3001;
    private const int GAS = 3004;
    private const int BPO_MAX_PRODUCTION_LIMIT = 300;

    private const float FLOAT_TOLERANCE = 1e-6;

    public function testIntermediateIsPaidProRataOfItsDemand(): void
    {
        $marginalCostPlan = self::hailLMarginalCostPlan();

        // D4b: the root demand of R.A.M. is not rounded either: 10 × 1 × 0.98 × 0.99 × 0.9496 = 9.2130192 units,
        // 100 per run → 0.092130192 run, no surplus of 90.
        $this->assertEqualsWithDelta(0.092130192, $marginalCostPlan->fractionalRuns[self::RAM_AMMUNITION_TECH], self::FLOAT_TOLERANCE);
    }

    public function testLeavesAreFractionalQuantitiesWithoutRounding(): void
    {
        $marginalCostPlan = self::hailLMarginalCostPlan();

        $expectedLeaves = [
            'Fernite Carbide' => 27639.0576,   // 10 × 3000 × 0.98 × 0.99 × 0.9496 (plan mode: 27 640)
            'Fullerides' => 11055.62304,       // 10 × 1200 × 0.98 × 0.99 × 0.9496 (plan mode: 11 056)
            'Morphite' => 138.195288,          // 10 × 15 × 0.98 × 0.99 × 0.9496 (plan mode: 139)
            'Tritanium' => 45.640928596032,    // 0.092130192 × 556 × 0.9 × 0.99 (plan mode: 496)
            'Pyerite' => 36.447072475968,      // × 444
            'Mexallon' => 18.223536237984,     // × 222
            'Isogen' => 6.731216087904,        // × 82
            'Nocxium' => 2.955168038592,       // × 36
        ];
        $this->assertCount(\count($expectedLeaves), $marginalCostPlan->leaves);
        foreach ($expectedLeaves as $leafName => $expectedQuantity) {
            $this->assertEqualsWithDelta(
                $expectedQuantity,
                $marginalCostPlan->leaves[InMemoryBlueprintCatalog::typeIdOf($leafName)],
                self::FLOAT_TOLERANCE,
                $leafName,
            );
        }
    }

    public function testStockOfAnIntermediateReducesItsFractionalRunsAndItsSubtree(): void
    {
        $marginalCostPlan = self::syntheticMarginalCostPlan(startingStock: [self::COMPONENT => new Quantity(7)]);

        // Component: demand 20 − 7 in stock = 13, 2 per run → 6.5 runs (no ceil);
        // mineral A: 200 + 6.5 × 5 × 0.9 (ME 10 by default) = 229.25.
        $this->assertEqualsWithDelta(6.5, $marginalCostPlan->fractionalRuns[self::COMPONENT], self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(229.25, $marginalCostPlan->leaves[self::MINERAL_A], self::FLOAT_TOLERANCE);
    }

    public function testMaterialSharedByTwoRecipesAddsUpBothDemands(): void
    {
        $marginalCostPlan = self::syntheticMarginalCostPlan();

        // Mineral A: 2 × 100 for the ship + 10 component runs × 5 × 0.9 = 245.
        $this->assertEqualsWithDelta(10.0, $marginalCostPlan->fractionalRuns[self::COMPONENT], self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(245.0, $marginalCostPlan->leaves[self::MINERAL_A], self::FLOAT_TOLERANCE);
    }

    public function testStockOfALeafReducesTheFractionalQuantityToBuy(): void
    {
        $marginalCostPlan = self::syntheticMarginalCostPlan(startingStock: [self::MINERAL_A => new Quantity(50)]);

        // 245 − 50 = 195.
        $this->assertEqualsWithDelta(195.0, $marginalCostPlan->leaves[self::MINERAL_A], self::FLOAT_TOLERANCE);
    }

    public function testLeafFullyCoveredByStockIsNotBought(): void
    {
        $marginalCostPlan = self::syntheticMarginalCostPlan(startingStock: [self::GAS => new Quantity(50)]);

        // Polymer: 100 / 200 = 0.5 run → 50 gas, all in stock: nothing to buy, not even a zero line.
        $this->assertEqualsWithDelta(0.5, $marginalCostPlan->fractionalRuns[self::POLYMER], self::FLOAT_TOLERANCE);
        $this->assertSame([self::MINERAL_A], array_keys($marginalCostPlan->leaves));
    }

    public function testIntermediateAfterOneFullyCoveredByStockStillHasFractionalRuns(): void
    {
        $planner = new ProductionPlanner(new InMemoryBlueprintCatalog(
            self::recipe(self::MODULE, ActivityKind::Manufacturing, 1, [self::POLYMER => 30, self::ASSEMBLY => 4]),
            self::recipe(self::ASSEMBLY, ActivityKind::Manufacturing, 1, [self::COMPONENT => 3]),
            self::recipe(self::COMPONENT, ActivityKind::Manufacturing, 2, [self::MINERAL_A => 5]),
            self::recipe(self::POLYMER, ActivityKind::Reaction, 200, [self::GAS => 100]),
        ));

        $marginalCostPlan = $planner->marginalCost(new PlanRequest(
            targets: [new PlanTarget(self::MODULE, new Runs(3), new BlueprintEfficiency(new MaterialEfficiency(0), new TimeEfficiency(0)))],
            materialModifiers: ActivityMaterialModifiers::none(),
            startingStock: [self::ASSEMBLY => new Quantity(15)],
        ));

        $this->assertEqualsWithDelta(
            [self::ASSEMBLY => 0.0, self::COMPONENT => 0.0, self::POLYMER => 0.45],
            $marginalCostPlan->fractionalRuns,
            self::FLOAT_TOLERANCE,
        );
        $this->assertEqualsWithDelta([self::GAS => 45.0], $marginalCostPlan->leaves, self::FLOAT_TOLERANCE);
    }

    /**
     * @param array<int, Quantity> $startingStock
     */
    private static function syntheticMarginalCostPlan(array $startingStock = []): MarginalCostPlan
    {
        $planner = new ProductionPlanner(new InMemoryBlueprintCatalog(
            self::recipe(self::SHIP, ActivityKind::Manufacturing, 1, [self::COMPONENT => 10, self::POLYMER => 50, self::MINERAL_A => 100]),
            self::recipe(self::COMPONENT, ActivityKind::Manufacturing, 2, [self::MINERAL_A => 5]),
            self::recipe(self::POLYMER, ActivityKind::Reaction, 200, [self::GAS => 100]),
        ));

        return $planner->marginalCost(new PlanRequest(
            targets: [new PlanTarget(self::SHIP, new Runs(2), new BlueprintEfficiency(new MaterialEfficiency(0), new TimeEfficiency(0)))],
            materialModifiers: ActivityMaterialModifiers::none(),
            startingStock: $startingStock,
        ));
    }

    /**
     * @param array<int, int> $baseQuantitiesPerRun by material typeId
     */
    private static function recipe(int $productTypeId, ActivityKind $activity, int $outputPerRun, array $baseQuantitiesPerRun): Recipe
    {
        $materials = [];
        foreach ($baseQuantitiesPerRun as $materialTypeId => $baseQuantityPerRun) {
            $materials[] = new RecipeMaterial($materialTypeId, new Quantity($baseQuantityPerRun));
        }

        return new Recipe(
            productTypeId: $productTypeId,
            activity: $activity,
            outputPerRun: new OutputPerRun($outputPerRun),
            materials: $materials,
            maxProductionLimit: new Runs(self::BPO_MAX_PRODUCTION_LIMIT),
            baseTimeSeconds: 600,
        );
    }

    private static function hailLMarginalCostPlan(): MarginalCostPlan
    {
        $planner = new ProductionPlanner(InMemoryBlueprintCatalog::fromSdeFixture());

        return $planner->marginalCost(new PlanRequest(
            targets: [new PlanTarget(
                self::HAIL_L,
                new Runs(10),
                new BlueprintEfficiency(new MaterialEfficiency(2), new TimeEfficiency(4)),
            )],
            materialModifiers: new ActivityMaterialModifiers(
                manufacturing: new Multiplier(0.99),
                reaction: Multiplier::one(),
                byProduct: [self::HAIL_L => new Multiplier(0.99 * 0.9496)],
            ),
            blacklist: [InMemoryBlueprintCatalog::typeIdOf('Fernite Carbide'), InMemoryBlueprintCatalog::typeIdOf('Fullerides')],
        ));
    }
}
