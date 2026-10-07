<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\MarginalCostPlan;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\PlanRequest;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\ProductionPlanner;
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
