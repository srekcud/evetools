<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Plan;
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
 * Plan mode on hand-computed recipes: BPC run cap (R3, D2), materials per split job (R13, D11b),
 * starting stock (R9, D8), default ME/TE of the intermediates (R3). No structure bonus: every modifier is 1.
 *
 * Synthetic catalog:
 *   ship      (manufacturing, 1 / run)   : 10 component + 50 polymer + 100 mineral A
 *   component (manufacturing, 2 / run)   : 5 mineral B
 *   polymer   (reaction, 200 / run)      : 100 gas
 *   part      (manufacturing, 1 / run)   : 7 mineral C
 */
#[CoversClass(ProductionPlanner::class)]
#[CoversClass(Plan::class)]
final class ProductionPlannerTest extends TestCase
{
    private const int SHIP = 1001;
    private const int PART = 1002;
    private const int COMPONENT = 2001;
    private const int POLYMER = 2002;
    private const int MINERAL_A = 3001;
    private const int MINERAL_B = 3002;
    private const int MINERAL_C = 3003;
    private const int GAS = 3004;

    private const int BPO_MAX_PRODUCTION_LIMIT = 300;

    public function testBlueprintCopyRunCapSplitsTheTargetIntoEvenJobs(): void
    {
        $plan = $this->plan(
            [new PlanTarget(self::PART, new Runs(32), self::efficiency(10, 20))],
            blueprintCopyMaxRuns: [self::PART => new Runs(11)],
        );

        // ceil(32 / 11) = 3 jobs, never more than 11 runs each.
        $this->assertSame([11, 11, 10], self::runsOfJobs($plan->jobsFor(self::PART)));
    }

    public function testMaterialsAreRecomputedForEachSplitJob(): void
    {
        $plan = $this->plan(
            [new PlanTarget(self::PART, new Runs(32), self::efficiency(10, 20))],
            blueprintCopyMaxRuns: [self::PART => new Runs(11)],
        );

        // 11 × 7 × 0.9 = 69.3 → 70, twice ; 10 × 7 × 0.9 = 63.
        $this->assertSame(
            [70, 70, 63],
            array_map(static fn (Job $job): int => $job->materials[self::MINERAL_C]->value, $plan->jobsFor(self::PART)),
        );
        $this->assertSame(203, $plan->leaves[self::MINERAL_C]->value);
    }

    public function testWithoutBlueprintCopyCapTheTargetIsOneJob(): void
    {
        $plan = $this->plan([new PlanTarget(self::PART, new Runs(32), self::efficiency(10, 20))]);

        // BPO: no split; 32 × 7 × 0.9 = 201.6 → 202 in a single job.
        $this->assertSame([32], self::runsOfJobs($plan->jobsFor(self::PART)));
        $this->assertSame(202, $plan->leaves[self::MINERAL_C]->value);
    }

    public function testBlueprintCopyRunCapSplitsAnIntermediateAndItsMaterialsPerJob(): void
    {
        $plan = $this->plan(
            [new PlanTarget(self::SHIP, new Runs(2), self::efficiency(0, 0))],
            blueprintCopyMaxRuns: [self::COMPONENT => new Runs(4)],
        );

        // Component demand 20, 2 per run → 10 runs, capped at 4: 4 + 3 + 3.
        $this->assertSame([4, 3, 3], self::runsOfJobs($plan->jobsFor(self::COMPONENT)));
        // 4 × 5 × 0.9 = 18 ; 3 × 5 × 0.9 = 13.5 → 14, twice. One single job of 10 runs would need 45.
        $this->assertSame(46, $plan->leaves[self::MINERAL_B]->value);
    }

    public function testRunsOfAnIntermediateComeFromItsDemandAndOutputPerRun(): void
    {
        $plan = $this->plan([new PlanTarget(self::SHIP, new Runs(2), self::efficiency(0, 0))]);

        $component = $plan->intermediates[self::COMPONENT];
        $this->assertSame(20, $component->demand->value);
        $this->assertSame(10, $component->totalRuns());
        $this->assertSame(20, $component->quantityProduced->value);
        $this->assertSame(0, $component->surplus->value);

        $polymer = $plan->intermediates[self::POLYMER];
        $this->assertSame(100, $polymer->demand->value);
        $this->assertSame(1, $polymer->totalRuns());
        $this->assertSame(200, $polymer->quantityProduced->value);
        $this->assertSame(100, $polymer->surplus->value);
    }

    public function testTargetJobKeepsTheRunsAndEfficiencyOfTheTarget(): void
    {
        $plan = $this->plan([new PlanTarget(self::SHIP, new Runs(2), self::efficiency(4, 8))]);

        [$shipJob] = $plan->jobsFor(self::SHIP);
        $this->assertSame(2, $shipJob->runs->value);
        $this->assertSame(4, $shipJob->materialEfficiency->value);
        $this->assertSame(8, $shipJob->timeEfficiency->value);
        $this->assertSame(ActivityKind::Manufacturing, $shipJob->activity);
        // 2 × 100 × 0.96 = 192.
        $this->assertSame(192, $shipJob->materials[self::MINERAL_A]->value);
    }

    public function testManufacturedIntermediatesDefaultToMe10Te20(): void
    {
        $plan = $this->plan([new PlanTarget(self::SHIP, new Runs(2), self::efficiency(0, 0))]);

        [$componentJob] = $plan->jobsFor(self::COMPONENT);
        $this->assertSame(10, $componentJob->materialEfficiency->value);
        $this->assertSame(20, $componentJob->timeEfficiency->value);
        // 10 × 5 × 0.9 = 45.
        $this->assertSame(45, $plan->leaves[self::MINERAL_B]->value);
    }

    public function testReactionIntermediatesHaveNoMeNorTe(): void
    {
        $plan = $this->plan([new PlanTarget(self::SHIP, new Runs(2), self::efficiency(0, 0))]);

        [$polymerJob] = $plan->jobsFor(self::POLYMER);
        $this->assertSame(ActivityKind::Reaction, $polymerJob->activity);
        $this->assertSame(0, $polymerJob->materialEfficiency->value);
        $this->assertSame(0, $polymerJob->timeEfficiency->value);
        $this->assertSame(100, $plan->leaves[self::GAS]->value);
    }

    public function testDefaultEfficiencyOfAnIntermediateCanBeOverridden(): void
    {
        $plan = $this->plan(
            [new PlanTarget(self::SHIP, new Runs(2), self::efficiency(0, 0))],
            intermediateEfficiencies: [self::COMPONENT => self::efficiency(5, 10)],
        );

        [$componentJob] = $plan->jobsFor(self::COMPONENT);
        $this->assertSame(5, $componentJob->materialEfficiency->value);
        $this->assertSame(10, $componentJob->timeEfficiency->value);
        // 10 × 5 × 0.95 = 47.5 → 48.
        $this->assertSame(48, $plan->leaves[self::MINERAL_B]->value);
    }

    public function testStockOfAnIntermediateIsConsumedBeforeItsRunsAndReducesItsSubtree(): void
    {
        $plan = $this->plan(
            [new PlanTarget(self::SHIP, new Runs(2), self::efficiency(0, 0))],
            startingStock: [self::COMPONENT => new Quantity(7)],
        );

        $component = $plan->intermediates[self::COMPONENT];
        // Demand 20 before stock; 13 left to build, 2 per run → 7 runs, 14 produced, 1 in surplus.
        $this->assertSame(20, $component->demand->value);
        $this->assertSame(7, $component->consumedStock->value);
        $this->assertSame(7, $component->totalRuns());
        $this->assertSame(14, $component->quantityProduced->value);
        $this->assertSame(1, $component->surplus->value);
        // Subtree of 7 runs instead of 10: 7 × 5 × 0.9 = 31.5 → 32.
        $this->assertSame(32, $plan->leaves[self::MINERAL_B]->value);
    }

    public function testIntermediateFullyCoveredByStockHasNoJobAndNoSubtree(): void
    {
        $plan = $this->plan(
            [new PlanTarget(self::SHIP, new Runs(2), self::efficiency(0, 0))],
            startingStock: [self::COMPONENT => new Quantity(25)],
        );

        $component = $plan->intermediates[self::COMPONENT];
        $this->assertSame(20, $component->demand->value);
        $this->assertSame(20, $component->consumedStock->value);
        $this->assertSame(0, $component->totalRuns());
        $this->assertSame(0, $component->surplus->value);
        $this->assertSame([], $plan->jobsFor(self::COMPONENT));
        $this->assertArrayNotHasKey(self::MINERAL_B, $plan->leaves);
    }

    public function testStockOfALeafReducesTheQuantityToBuy(): void
    {
        $plan = $this->plan(
            [new PlanTarget(self::SHIP, new Runs(2), self::efficiency(0, 0))],
            startingStock: [self::MINERAL_A => new Quantity(50), self::GAS => new Quantity(100)],
        );

        $this->assertSame(150, $plan->leaves[self::MINERAL_A]->value);
        // Fully covered: nothing left to buy.
        $this->assertArrayNotHasKey(self::GAS, $plan->leaves);
    }

    public function testBlacklistedIntermediateIsBoughtAsALeafWithoutSubtree(): void
    {
        $plan = $this->plan(
            [new PlanTarget(self::SHIP, new Runs(2), self::efficiency(0, 0))],
            blacklist: [self::COMPONENT],
        );

        $this->assertSame(20, $plan->leaves[self::COMPONENT]->value);
        $this->assertSame([], $plan->jobsFor(self::COMPONENT));
        $this->assertArrayNotHasKey(self::COMPONENT, $plan->intermediates);
        $this->assertArrayNotHasKey(self::MINERAL_B, $plan->leaves);
    }

    /**
     * @param list<PlanTarget>                $targets
     * @param array<int, Quantity>            $startingStock
     * @param list<int>                       $blacklist
     * @param array<int, Runs>                $blueprintCopyMaxRuns
     * @param array<int, BlueprintEfficiency> $intermediateEfficiencies
     */
    private function plan(
        array $targets,
        array $startingStock = [],
        array $blacklist = [],
        array $blueprintCopyMaxRuns = [],
        array $intermediateEfficiencies = [],
    ): Plan {
        $planner = new ProductionPlanner(self::syntheticCatalog());

        return $planner->plan(new PlanRequest(
            targets: $targets,
            materialModifiers: ActivityMaterialModifiers::none(),
            startingStock: $startingStock,
            blacklist: $blacklist,
            blueprintCopyMaxRuns: $blueprintCopyMaxRuns,
            intermediateEfficiencies: $intermediateEfficiencies,
        ));
    }

    private static function syntheticCatalog(): InMemoryBlueprintCatalog
    {
        return new InMemoryBlueprintCatalog(
            self::recipe(self::SHIP, ActivityKind::Manufacturing, 1, [self::COMPONENT => 10, self::POLYMER => 50, self::MINERAL_A => 100]),
            self::recipe(self::COMPONENT, ActivityKind::Manufacturing, 2, [self::MINERAL_B => 5]),
            self::recipe(self::POLYMER, ActivityKind::Reaction, 200, [self::GAS => 100]),
            self::recipe(self::PART, ActivityKind::Manufacturing, 1, [self::MINERAL_C => 7]),
        );
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
            outputPerRun: new Quantity($outputPerRun),
            materials: $materials,
            maxProductionLimit: new Runs(self::BPO_MAX_PRODUCTION_LIMIT),
            baseTimeSeconds: 600,
        );
    }

    private static function efficiency(int $materialEfficiency, int $timeEfficiency): BlueprintEfficiency
    {
        return new BlueprintEfficiency(new MaterialEfficiency($materialEfficiency), new TimeEfficiency($timeEfficiency));
    }

    /**
     * @param list<Job> $jobs
     *
     * @return list<int>
     */
    private static function runsOfJobs(array $jobs): array
    {
        return array_map(static fn (Job $job): int => $job->runs->value, $jobs);
    }
}
