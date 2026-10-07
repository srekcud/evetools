<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Intermediate;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Plan;
use App\Industry\Domain\PlanRequest;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\ProductionPlanner;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Domain\Double\ActivityMaterialModifiers;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Plan mode (R3, R13) against the reference plans of tests/Fixtures/Golden, recipes from sde-blueprint-recipes.json.
 *
 * - Nomad (Ravworks CBevjGf, fait foi): 63 leaves, 65 intermediates, demand aggregated over the whole plan (#69).
 * - Ravworks 2MbF29y (4 end products, nullsec): 43 leaves, 57 intermediates, 100 quantities that only match when
 *   materials are recomputed per split job (D11b, #73).
 * - Fernite Carbide and Hail L (§4.2, §4.3): confirmed by EVE Ref, still provisional (D12).
 *
 * Surpluses of the Ravworks plans are not asserted to the unit (Nitrogen Fuel Block of the Nomad: Ravworks 14, recomputed 15).
 */
#[CoversClass(ProductionPlanner::class)]
#[CoversClass(Plan::class)]
final class GoldenPlanTest extends TestCase
{
    private const string GOLDEN_DIRECTORY = __DIR__.'/../../../Fixtures/Golden';

    /** Engineering complex role bonus × T1 rig 2.0 % × nullsec 2.1 (configuration inferred, then checked on 100/100 quantities). */
    private const float RAVWORKS_MANUFACTURING_MODIFIER = 0.99 * 0.958;

    private const float RAVWORKS_REACTION_MODIFIER = 0.9736;

    public function testNomadPlanBuysExactlyTheLeavesOfTheReferencePlan(): void
    {
        $golden = self::loadGolden('nomad-1run-me10-no-structure.ravworks.json');

        $plan = self::nomadPlan();

        $this->assertSame(self::expectedLeaves($golden), self::leavesOf($plan));
    }

    public function testNomadPlanHasTheRunsAndDemandOfEveryReferenceIntermediate(): void
    {
        $golden = self::loadGolden('nomad-1run-me10-no-structure.ravworks.json');

        $plan = self::nomadPlan();

        $this->assertSame(self::expectedRunsAndDemand($golden), self::runsAndDemandOf($plan));
    }

    public function testNomadPlanSpotChecksFromTheSpec(): void
    {
        $plan = self::nomadPlan();

        $this->assertSame(120, self::intermediate($plan, 'Carbon Fiber')->totalRuns());
        $this->assertSame(120, self::intermediate($plan, 'Thermosetting Polymer')->totalRuns());
        $this->assertSame(27, self::intermediate($plan, 'Capital Jump Drive')->totalRuns());
        $this->assertSame(32, self::intermediate($plan, 'Capital Cargo Bay')->totalRuns());
        $oxygenFuelBlock = self::intermediate($plan, 'Oxygen Fuel Block');
        $this->assertSame([44, 1760, 1735], [$oxygenFuelBlock->totalRuns(), $oxygenFuelBlock->quantityProduced->value, $oxygenFuelBlock->demand->value]);
        $nitrogenFuelBlock = self::intermediate($plan, 'Nitrogen Fuel Block');
        $this->assertSame([106, 4240, 4225], [$nitrogenFuelBlock->totalRuns(), $nitrogenFuelBlock->quantityProduced->value, $nitrogenFuelBlock->demand->value]);
        $this->assertSame(17820, $plan->leaves[InMemoryBlueprintCatalog::typeIdOf('Oxygen Isotopes')]->value);
        $this->assertSame(75200, $plan->leaves[InMemoryBlueprintCatalog::typeIdOf('Hydrocarbons')]->value);
        $this->assertSame(60500, $plan->leaves[InMemoryBlueprintCatalog::typeIdOf('Atmospheric Gases')]->value);
        $this->assertSame(4692, $plan->leaves[InMemoryBlueprintCatalog::typeIdOf('Coolant')]->value);
    }

    public function testNomadPlanConsumesTheReferenceMaterialsInEveryJob(): void
    {
        $golden = self::loadGolden('nomad-1run-me10-no-structure.ravworks.json');

        $plan = self::nomadPlan();

        $expected = [$golden['expected']['root']['product'] => $golden['expected']['root']['inputs']];
        foreach ($golden['expected']['intermediates'] as $intermediate) {
            $expected[$intermediate['product']] = $intermediate['inputs'];
        }
        $this->assertEquals(self::sortedByKey($expected), self::materialsByProductOf($plan));
    }

    public function testNomadPlanRunsEachIntermediateInOneJobWithoutBlueprintCopyCap(): void
    {
        $plan = self::nomadPlan();

        foreach ($plan->intermediates as $productTypeId => $intermediate) {
            $this->assertCount(1, $intermediate->jobs, InMemoryBlueprintCatalog::nameOf($productTypeId));
        }
    }

    public function testRavworksSharedPlanBuysExactlyTheLeavesOfTheReferencePlan(): void
    {
        $golden = self::loadGolden('ravworks-2MbF29y-widow-marshal-nestor-machariel-nullsec.json');

        $plan = self::ravworksSharedPlan($golden);

        $this->assertSame(self::expectedLeaves($golden), self::leavesOf($plan));
    }

    public function testRavworksSharedPlanHasTheRunsAndDemandOfEveryReferenceIntermediate(): void
    {
        $golden = self::loadGolden('ravworks-2MbF29y-widow-marshal-nestor-machariel-nullsec.json');

        $plan = self::ravworksSharedPlan($golden);

        $this->assertSame(self::expectedRunsAndDemand($golden), self::runsAndDemandOf($plan));
    }

    public function testRavworksSharedPlanSplitsEveryJobLikeTheReferencePlan(): void
    {
        $golden = self::loadGolden('ravworks-2MbF29y-widow-marshal-nestor-machariel-nullsec.json');

        $plan = self::ravworksSharedPlan($golden);

        $expected = [];
        $actual = [];
        foreach ([...$golden['expected']['roots'], ...$golden['expected']['intermediates']] as $product) {
            $expected[$product['product']] = $product['jobs'];
            $actual[$product['product']] = array_map(
                static fn (Job $job): int => $job->runs->value,
                $plan->jobsFor($product['typeId']),
            );
        }
        $this->assertSame($expected, $actual);
    }

    public function testRavworksSharedPlanRecomputesTheMaterialsOfEverySplitJob(): void
    {
        $golden = self::loadGolden('ravworks-2MbF29y-widow-marshal-nestor-machariel-nullsec.json');

        $plan = self::ravworksSharedPlan($golden);

        $expected = [];
        foreach ([...$golden['expected']['roots'], ...$golden['expected']['intermediates']] as $product) {
            $expected[$product['product']] = $product['inputs'];
        }
        $this->assertEquals(self::sortedByKey($expected), self::materialsByProductOf($plan));
    }

    public function testFerniteCarbideAggregatesTheFuelBlockDemandOverTwoDepths(): void
    {
        $golden = self::loadGolden('fernite-carbide-10runs-tatara-nullsec.json');
        $planner = new ProductionPlanner(InMemoryBlueprintCatalog::fromSdeFixture());

        $plan = $planner->plan(new PlanRequest(
            targets: [new PlanTarget(16673, new Runs(10), self::efficiency(0, 0))],
            materialModifiers: new ActivityMaterialModifiers(
                manufacturing: new Multiplier(0.99),
                reaction: new Multiplier(0.9736),
            ),
        ));

        // 49 (root) + 25 (Ceramic Powder) + 25 (Fernite Alloy) = 99 → ceil(99 / 40) = 3 runs; per branch it would be 4.
        $hydrogenFuelBlock = self::intermediate($plan, 'Hydrogen Fuel Block');
        $this->assertSame([99, 3, 120, 21], [
            $hydrogenFuelBlock->demand->value,
            $hydrogenFuelBlock->totalRuns(),
            $hydrogenFuelBlock->quantityProduced->value,
            $hydrogenFuelBlock->surplus->value,
        ]);
        foreach (['Ceramic Powder', 'Fernite Alloy'] as $reaction) {
            $intermediate = self::intermediate($plan, $reaction);
            $this->assertSame([974, 5, 1000, 26], [
                $intermediate->demand->value,
                $intermediate->totalRuns(),
                $intermediate->quantityProduced->value,
                $intermediate->surplus->value,
            ], $reaction);
        }
        $this->assertSame(self::expectedLeaves($golden), self::leavesOf($plan));
    }

    public function testHailLBuildsOneRunOfRamWithItsSurplus(): void
    {
        $golden = self::loadGolden('hail-l-10runs-me2-raitaru-nullsec.json');
        $ramTypeId = InMemoryBlueprintCatalog::typeIdOf('R.A.M.- Ammunition Tech');
        $hailLTypeId = 12779;
        $planner = new ProductionPlanner(InMemoryBlueprintCatalog::fromSdeFixture());

        $plan = $planner->plan(new PlanRequest(
            targets: [new PlanTarget($hailLTypeId, new Runs(10), self::efficiency(2, 4))],
            materialModifiers: new ActivityMaterialModifiers(
                manufacturing: new Multiplier(0.99),
                reaction: Multiplier::one(),
                byProduct: [$hailLTypeId => new Multiplier(0.99 * 0.9496)],
            ),
            blacklist: [InMemoryBlueprintCatalog::typeIdOf('Fernite Carbide'), InMemoryBlueprintCatalog::typeIdOf('Fullerides')],
        ));

        [$hailLJob] = $plan->jobsFor($hailLTypeId);
        $this->assertSame(10, $hailLJob->runs->value);
        $ram = $plan->intermediates[$ramTypeId];
        $this->assertSame([10, 1, 100, 90], [$ram->demand->value, $ram->totalRuns(), $ram->quantityProduced->value, $ram->surplus->value]);
        $this->assertSame(self::expectedLeaves($golden), self::leavesOf($plan));
    }

    private static function nomadPlan(): Plan
    {
        $planner = new ProductionPlanner(InMemoryBlueprintCatalog::fromSdeFixture());

        // Ravworks "No Structure Bonus": no structure nor rig for any activity.
        return $planner->plan(new PlanRequest(
            targets: [new PlanTarget(28846, new Runs(1), self::efficiency(10, 20))],
            materialModifiers: ActivityMaterialModifiers::none(),
        ));
    }

    /**
     * End products are targets; Ravworks splits jobs by duration (2 days), an Application concern. The same jobs come
     * out of a BPC cap equal to the largest job of each product, runs spread evenly over ceil(runs / cap) jobs.
     *
     * @param array<string, mixed> $golden
     */
    private static function ravworksSharedPlan(array $golden): Plan
    {
        $targets = [];
        foreach ($golden['input']['endProductJobs'] as $endProduct) {
            $targets[] = new PlanTarget(
                $endProduct['typeId'],
                new Runs(array_sum($endProduct['jobs'])),
                self::efficiency($endProduct['me'], 0),
            );
        }
        $blueprintCopyMaxRuns = [];
        foreach ([...$golden['expected']['roots'], ...$golden['expected']['intermediates']] as $product) {
            $blueprintCopyMaxRuns[$product['typeId']] = new Runs(max($product['jobs']));
        }
        // Everything in the leaves is bought: fuel blocks and R.A.M. have a recipe but are not built in this plan.
        $blacklist = array_map(static fn (array $leaf): int => $leaf['typeId'], $golden['expected']['leaves']);

        $planner = new ProductionPlanner(InMemoryBlueprintCatalog::fromSdeFixture());

        return $planner->plan(new PlanRequest(
            targets: $targets,
            materialModifiers: new ActivityMaterialModifiers(
                manufacturing: new Multiplier(self::RAVWORKS_MANUFACTURING_MODIFIER),
                reaction: new Multiplier(self::RAVWORKS_REACTION_MODIFIER),
            ),
            blacklist: $blacklist,
            blueprintCopyMaxRuns: $blueprintCopyMaxRuns,
        ));
    }

    private static function intermediate(Plan $plan, string $productName): Intermediate
    {
        return $plan->intermediates[InMemoryBlueprintCatalog::typeIdOf($productName)];
    }

    /**
     * @param array<string, mixed> $golden
     *
     * @return array<int, int> quantity to buy by typeId
     */
    private static function expectedLeaves(array $golden): array
    {
        $leaves = [];
        foreach ($golden['expected']['leaves'] as $leaf) {
            $leaves[$leaf['typeId']] = $leaf['quantity'];
        }
        ksort($leaves);

        return $leaves;
    }

    /**
     * @return array<int, int>
     */
    private static function leavesOf(Plan $plan): array
    {
        $leaves = array_map(static fn (Quantity $quantity): int => $quantity->value, $plan->leaves);
        ksort($leaves);

        return $leaves;
    }

    /**
     * @param array<string, mixed> $golden
     *
     * @return array<string, array{runs: int, demand: int}>
     */
    private static function expectedRunsAndDemand(array $golden): array
    {
        $intermediates = [];
        foreach ($golden['expected']['intermediates'] as $intermediate) {
            $intermediates[$intermediate['product']] = ['runs' => $intermediate['runs'], 'demand' => $intermediate['quantityNeeded']];
        }
        ksort($intermediates);

        return $intermediates;
    }

    /**
     * @return array<string, array{runs: int, demand: int}>
     */
    private static function runsAndDemandOf(Plan $plan): array
    {
        $intermediates = [];
        foreach ($plan->intermediates as $productTypeId => $intermediate) {
            $intermediates[InMemoryBlueprintCatalog::nameOf($productTypeId)] = [
                'runs' => $intermediate->totalRuns(),
                'demand' => $intermediate->demand->value,
            ];
        }
        ksort($intermediates);

        return $intermediates;
    }

    /**
     * Materials summed over the jobs of each product, by material name.
     *
     * @return array<string, array<string, int>>
     */
    private static function materialsByProductOf(Plan $plan): array
    {
        $materialsByProduct = [];
        foreach ($plan->jobs as $job) {
            $productName = InMemoryBlueprintCatalog::nameOf($job->productTypeId);
            foreach ($job->materials as $materialTypeId => $quantity) {
                $materialName = InMemoryBlueprintCatalog::nameOf($materialTypeId);
                $materialsByProduct[$productName][$materialName] = ($materialsByProduct[$productName][$materialName] ?? 0) + $quantity->value;
            }
        }

        return self::sortedByKey($materialsByProduct);
    }

    /**
     * @param array<string, array<string, int>> $materialsByProduct
     *
     * @return array<string, array<string, int>>
     */
    private static function sortedByKey(array $materialsByProduct): array
    {
        ksort($materialsByProduct);

        return array_map(static function (array $materials): array {
            ksort($materials);

            return $materials;
        }, $materialsByProduct);
    }

    private static function efficiency(int $materialEfficiency, int $timeEfficiency): BlueprintEfficiency
    {
        return new BlueprintEfficiency(new MaterialEfficiency($materialEfficiency), new TimeEfficiency($timeEfficiency));
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadGolden(string $fileName): array
    {
        $json = file_get_contents(self::GOLDEN_DIRECTORY.'/'.$fileName);
        self::assertIsString($json, "Golden fixture {$fileName} is missing");

        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }
}
