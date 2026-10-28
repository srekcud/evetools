<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
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

#[CoversClass(ProductionPlanner::class)]
final class ProductionPlannerSeveralUnitsPerRunTest extends TestCase
{
    private const int AMMUNITION = 5001;
    private const int COMPOUND = 5002;
    private const int MINERAL = 5101;
    private const int GAS = 5102;

    private const int AMMUNITION_OUTPUT_PER_RUN = 100;
    private const int COMPOUND_OUTPUT_PER_RUN = 40;
    private const int BPO_MAX_PRODUCTION_LIMIT = 300;

    public function testPlanOfATargetYieldingAHundredUnitsPerRunRoundsItsIntermediateUpToWholeRuns(): void
    {
        $plan = self::planner()->plan(self::request());

        $this->assertSame([3], array_map(static fn (Job $job): int => $job->runs->value, $plan->jobsFor(self::AMMUNITION)));
        $compound = $plan->intermediates[self::COMPOUND];
        $this->assertSame(90, $compound->demand->value);
        $this->assertSame(3, $compound->totalRuns());
        $this->assertSame(120, $compound->quantityProduced->value);
        $this->assertSame(30, $compound->surplus->value);
        $this->assertSame(21, $plan->leaves[self::MINERAL]->value);
        $this->assertSame(15, $plan->leaves[self::GAS]->value);
    }

    public function testMarginalCostOfATargetYieldingAHundredUnitsPerRunKeepsFractionalRunsOfItsIntermediate(): void
    {
        $marginalCostPlan = self::planner()->marginalCost(self::request());

        $this->assertSame([self::COMPOUND => 2.25], $marginalCostPlan->fractionalRuns);
        $this->assertSame(21.0, $marginalCostPlan->leaves[self::MINERAL]);
        $this->assertSame(11.25, $marginalCostPlan->leaves[self::GAS]);
    }

    private static function planner(): ProductionPlanner
    {
        return new ProductionPlanner(new InMemoryBlueprintCatalog(
            self::recipe(self::AMMUNITION, ActivityKind::Manufacturing, self::AMMUNITION_OUTPUT_PER_RUN, [self::COMPOUND => 30, self::MINERAL => 7]),
            self::recipe(self::COMPOUND, ActivityKind::Reaction, self::COMPOUND_OUTPUT_PER_RUN, [self::GAS => 5]),
        ));
    }

    private static function request(): PlanRequest
    {
        return new PlanRequest(
            targets: [new PlanTarget(self::AMMUNITION, new Runs(3), new BlueprintEfficiency(new MaterialEfficiency(0), new TimeEfficiency(0)))],
            materialModifiers: ActivityMaterialModifiers::none(),
        );
    }

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
}
