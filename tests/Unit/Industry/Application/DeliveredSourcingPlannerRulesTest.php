<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\CourierHub;
use App\Industry\Application\DeliveredSourcing;
use App\Industry\Application\DeliveredSourcingLine;
use App\Industry\Application\DeliveredSourcingPlanner;
use App\Industry\Application\DeliveredSourcingTotals;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\MarketPrices;
use App\Industry\Application\PlannedProduction;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\SourcingChoice;
use App\Industry\Application\SourcingUniverse;
use App\Industry\Application\SourcingValuation;
use App\Industry\Application\StructureChoice;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\WorkTypeClassifier;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Intermediate;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\FixedJobInstallationCosts;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryShipProducts;
use App\Tests\Unit\Industry\Application\Double\InMemoryStructureMarketPrices;
use App\Tests\Unit\Industry\Application\Double\PlannedProductionsByPurchases;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use App\Tests\Unit\Industry\Application\Double\RecordingMarketPrices;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeliveredSourcingPlanner::class)]
#[CoversClass(SourcingValuation::class)]
#[CoversClass(SourcingUniverse::class)]
#[CoversClass(DeliveredSourcingTotals::class)]
final class DeliveredSourcingPlannerRulesTest extends TestCase
{
    private const int TARGET_PRODUCT = 12005;
    private const int SECOND_TARGET_PRODUCT = 12015;
    private const int FERNITE_COMPOSITE = 16670;
    private const int CAPACITOR_BATTERY = 11538;
    private const int ARMOR_PLATE = 11540;
    private const int LEAF_ALPHA = 16633;
    private const int LEAF_BETA = 16634;
    private const int LEAF_GAMMA = 16635;

    private const int HUB_SYSTEM_ID = 30_000_772;
    private const int OTHER_SYSTEM_ID = 30_002_187;
    private const int REACTION_SYSTEM_ID = 30_002_659;
    private const int MARKET_SYSTEM_ID = 30_001_000;
    private const int MARKET_STRUCTURE_ID = 1_040_000_000_001;

    public function testTheBoughtTypeIdsAreSortedWhateverTheOrderOfTheBuiltItems(): void
    {
        $jobs = $this->batteryAndPlateJobs();
        $universe = $this->plannedProduction(
            [$jobs['target'], $jobs['battery'], $jobs['plate']],
            [self::LEAF_ALPHA => 100, self::LEAF_BETA => 30],
            [
                self::ARMOR_PLATE => $this->intermediate(6, 10, [$jobs['plate']]),
                self::CAPACITOR_BATTERY => $this->intermediate(10, 10, [$jobs['battery']]),
            ],
            [self::TARGET_PRODUCT => $this->firstStation(), self::CAPACITOR_BATTERY => $this->firstStation(), self::ARMOR_PLATE => $this->firstStation()],
        );
        $replans = new PlannedProductionsByPurchases([
            '' => $universe,
            self::CAPACITOR_BATTERY.','.self::ARMOR_PLATE => $this->plannedProduction(
                [$jobs['target']],
                [self::CAPACITOR_BATTERY => 10, self::ARMOR_PLATE => 6],
                [],
                [self::TARGET_PRODUCT => $this->firstStation()],
            ),
        ]);

        $result = $this->plan(
            $universe,
            $replans,
            jitaUnitPrices: [self::LEAF_ALPHA => 100.0, self::LEAF_BETA => 250.0, self::CAPACITOR_BATTERY => 2_000.0, self::ARMOR_PLATE => 5_000.0],
            installationCosts: [self::TARGET_PRODUCT => 1_000.0, self::CAPACITOR_BATTERY => 500.0, self::ARMOR_PLATE => 400.0],
            buys: [self::CAPACITOR_BATTERY => true, self::ARMOR_PLATE => true],
        );

        self::assertSame([self::CAPACITOR_BATTERY, self::ARMOR_PLATE], $result->boughtTypeIds);
        self::assertSame([[self::CAPACITOR_BATTERY, self::ARMOR_PLATE]], $replans->calls);
        self::assertSame(
            [[0, 0], [0, 0]],
            [
                [$this->lineOf($result, self::CAPACITOR_BATTERY)->runs, $this->lineOf($result, self::CAPACITOR_BATTERY)->surplus->value],
                [$this->lineOf($result, self::ARMOR_PLATE)->runs, $this->lineOf($result, self::ARMOR_PLATE)->surplus->value],
            ],
        );
    }

    public function testAnItemOfTheLeavesNobodyConsumesHasNoLine(): void
    {
        $jobOfTheTarget = $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::LEAF_ALPHA => 100]);
        $universe = $this->plannedProduction(
            [$jobOfTheTarget],
            [self::LEAF_ALPHA => 100, self::LEAF_GAMMA => 77],
            [],
            [self::TARGET_PRODUCT => $this->firstStation()],
        );

        $result = $this->plan($universe, new PlannedProductionsByPurchases(['' => $universe]), jitaUnitPrices: [self::LEAF_ALPHA => 100.0, self::LEAF_GAMMA => 30.0]);

        self::assertSame([self::LEAF_ALPHA], array_map(static fn (DeliveredSourcingLine $line): int => $line->typeId, $result->lines));
    }

    public function testTheConsumerStructuresOfALineLeaveOutTheUnknownStationAndStayAList(): void
    {
        $jobWithoutStructure = $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::LEAF_ALPHA => 100]);
        $jobAtTheFirstStation = $this->job(self::SECOND_TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::LEAF_ALPHA => 50]);
        $universe = $this->plannedProduction(
            [$jobWithoutStructure, $jobAtTheFirstStation],
            [self::LEAF_ALPHA => 150],
            [],
            [self::SECOND_TARGET_PRODUCT => $this->firstStation()],
        );

        $result = $this->plan($universe, new PlannedProductionsByPurchases(['' => $universe]), jitaUnitPrices: [self::LEAF_ALPHA => 100.0]);

        self::assertSame(['S1'], $this->lineOf($result, self::LEAF_ALPHA)->consumerStructureIds);
    }

    public function testABoughtLineCarriesNoRunsAndNoSurplusWhateverTheRecalculatedPlanListsForIt(): void
    {
        $batteryJob = $this->job(self::CAPACITOR_BATTERY, ActivityKind::Manufacturing, 3, [self::LEAF_ALPHA => 100]);
        $jobOfTheTarget = $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::CAPACITOR_BATTERY => 10]);
        $universe = $this->plannedProduction(
            [$jobOfTheTarget, $batteryJob],
            [self::LEAF_ALPHA => 100],
            [self::CAPACITOR_BATTERY => $this->intermediate(10, 10, [$batteryJob])],
            [self::TARGET_PRODUCT => $this->firstStation(), self::CAPACITOR_BATTERY => $this->firstStation()],
        );
        $replans = new PlannedProductionsByPurchases([
            '' => $universe,
            (string) self::CAPACITOR_BATTERY => $this->plannedProduction(
                [$jobOfTheTarget],
                [self::CAPACITOR_BATTERY => 10],
                [self::CAPACITOR_BATTERY => $this->intermediate(10, 30, [$batteryJob])],
                [self::TARGET_PRODUCT => $this->firstStation()],
            ),
        ]);

        $result = $this->plan(
            $universe,
            $replans,
            jitaUnitPrices: [self::LEAF_ALPHA => 100.0, self::CAPACITOR_BATTERY => 2_000.0],
            installationCosts: [self::TARGET_PRODUCT => 1_000.0, self::CAPACITOR_BATTERY => 500.0],
            buys: [self::CAPACITOR_BATTERY => true],
        );

        $line = $this->lineOf($result, self::CAPACITOR_BATTERY);
        self::assertTrue($line->bought);
        self::assertSame([10, 0, 0], [$line->quantity->value, $line->runs, $line->surplus->value]);
    }

    public function testTheTransportTotalScalesTheBuildTransportOfABuiltLineByItsShareOfTheUniverseDemand(): void
    {
        $reactionJob = $this->job(self::FERNITE_COMPOSITE, ActivityKind::Reaction, 1, [self::LEAF_BETA => 50]);
        $batteryJob = $this->job(self::CAPACITOR_BATTERY, ActivityKind::Manufacturing, 1, [self::FERNITE_COMPOSITE => 5, self::LEAF_ALPHA => 20]);
        $jobOfTheTarget = $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::FERNITE_COMPOSITE => 10, self::CAPACITOR_BATTERY => 4]);
        $universe = $this->plannedProduction(
            [$jobOfTheTarget, $batteryJob, $reactionJob],
            [self::LEAF_ALPHA => 20, self::LEAF_BETA => 50],
            [
                self::FERNITE_COMPOSITE => $this->intermediate(15, 15, [$reactionJob]),
                self::CAPACITOR_BATTERY => $this->intermediate(4, 4, [$batteryJob]),
            ],
            [self::TARGET_PRODUCT => $this->firstStation(), self::CAPACITOR_BATTERY => $this->secondStation(), self::FERNITE_COMPOSITE => $this->reactionStation()],
        );
        $replans = new PlannedProductionsByPurchases([
            '' => $universe,
            (string) self::CAPACITOR_BATTERY => $this->plannedProduction(
                [$jobOfTheTarget, $reactionJob],
                [self::CAPACITOR_BATTERY => 4, self::LEAF_BETA => 50],
                [self::FERNITE_COMPOSITE => $this->intermediate(10, 10, [$reactionJob])],
                [self::TARGET_PRODUCT => $this->firstStation(), self::FERNITE_COMPOSITE => $this->reactionStation()],
            ),
        ]);

        $result = $this->plan(
            $universe,
            $replans,
            jitaUnitPrices: [self::LEAF_ALPHA => 100.0, self::LEAF_BETA => 20.0, self::FERNITE_COMPOSITE => 3_000.0, self::CAPACITOR_BATTERY => 2_000.0],
            cubicMetersByTypeId: [self::LEAF_ALPHA => 0.5, self::LEAF_BETA => 1.0, self::FERNITE_COMPOSITE => 2.0, self::CAPACITOR_BATTERY => 1.0],
            installationCosts: [self::TARGET_PRODUCT => 1_000.0, self::CAPACITOR_BATTERY => 300.0, self::FERNITE_COMPOSITE => 500.0],
            buys: [self::CAPACITOR_BATTERY => true],
            bridgeIskPerCubicMeter: 10.0,
        );

        $line = $this->lineOf($result, self::FERNITE_COMPOSITE);
        self::assertSame([10, 15, false], [$line->quantity->value, $line->universeDemand->value, $line->bought]);
        self::assertSame(300.0, round($line->option(SourcingChoice::Build)->transport->amount()->amount, 2));
        self::assertSame(200.0, round($result->totals->transport->amount()->amount, 2));
    }

    public function testAJobOfTheRecalculatedPlanWithoutRecipeFailsExplicitly(): void
    {
        $jobOfTheTarget = $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::LEAF_ALPHA => 100]);
        $universe = $this->plannedProduction([$jobOfTheTarget], [self::LEAF_ALPHA => 100], [], [self::TARGET_PRODUCT => $this->firstStation()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Job of product 12005 has no recipe.');

        $this->plan(
            $universe,
            new PlannedProductionsByPurchases(['' => $universe]),
            jitaUnitPrices: [self::LEAF_ALPHA => 100.0],
            recipedProductTypeIds: [],
        );
    }

    public function testACapitalMarketWithoutPriceStaysNotEligibleOnTheLineWhateverItsStock(): void
    {
        $jobOfTheTarget = $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::LEAF_ALPHA => 1_000]);
        $universe = $this->plannedProduction([$jobOfTheTarget], [self::LEAF_ALPHA => 1_000], [], [self::TARGET_PRODUCT => $this->firstStation()]);

        $result = $this->plan(
            $universe,
            new PlannedProductionsByPurchases(['' => $universe]),
            jitaUnitPrices: [self::LEAF_ALPHA => 100.0],
            structureUnitPrices: [self::LEAF_BETA => 1.0],
            structureSellVolumes: [self::LEAF_ALPHA => 5_000],
        );

        $structure = $this->lineOf($result, self::LEAF_ALPHA)->option(SourcingChoice::Structure);
        self::assertSame(5_000, $structure->availableQuantity);
        self::assertFalse($structure->eligible);
    }

    public function testTheJitaOrderBookIsAskedForTheUniverseDemandOfEveryConsumedItemAsFloats(): void
    {
        $jobOfTheTarget = $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::LEAF_ALPHA => 100, self::LEAF_BETA => 50]);
        $universe = $this->plannedProduction([$jobOfTheTarget], [self::LEAF_ALPHA => 100, self::LEAF_BETA => 50], [], [self::TARGET_PRODUCT => $this->firstStation()]);
        $marketPrices = new RecordingMarketPrices(new InMemoryMarketPrices([self::LEAF_ALPHA => 100.0, self::LEAF_BETA => 250.0]));

        $this->plan($universe, new PlannedProductionsByPurchases(['' => $universe]), jitaUnitPrices: [], marketPrices: $marketPrices);

        self::assertSame([[self::LEAF_ALPHA => 100.0, self::LEAF_BETA => 50.0]], $marketPrices->requests);
    }

    private function batteryAndPlateJobs(): array
    {
        return [
            'target' => $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::CAPACITOR_BATTERY => 10, self::ARMOR_PLATE => 6]),
            'battery' => $this->job(self::CAPACITOR_BATTERY, ActivityKind::Manufacturing, 1, [self::LEAF_ALPHA => 100]),
            'plate' => $this->job(self::ARMOR_PLATE, ActivityKind::Manufacturing, 2, [self::LEAF_BETA => 30]),
        ];
    }

    /**
     * @param array<int, float>  $jitaUnitPrices
     * @param array<int, float>  $cubicMetersByTypeId
     * @param array<int, float>  $installationCosts
     * @param array<int, float>  $structureUnitPrices
     * @param array<int, int>    $structureSellVolumes
     * @param list<int>|null     $recipedProductTypeIds
     */
    private function plan(
        PlannedProduction $universe,
        PlannedProductionsByPurchases $replans,
        array $jitaUnitPrices = [],
        array $cubicMetersByTypeId = [],
        array $installationCosts = [],
        array $buys = [],
        float $bridgeIskPerCubicMeter = 0.0,
        ?array $structureUnitPrices = null,
        array $structureSellVolumes = [],
        ?array $recipedProductTypeIds = null,
        ?MarketPrices $marketPrices = null,
    ): DeliveredSourcing {
        $recipes = [];
        foreach ($recipedProductTypeIds ?? array_map(static fn (Job $job): int => $job->productTypeId, $universe->plan->jobs) as $productTypeId) {
            $recipes[] = new Recipe($productTypeId, ActivityKind::Manufacturing, new OutputPerRun(1), [], new Runs(300), 3600);
        }
        $planner = new DeliveredSourcingPlanner(
            new InMemoryBlueprintCatalog(...$recipes),
            new FixedJobInstallationCosts(array_map(static fn (float $cost): Cost => Cost::known(new Isk($cost)), $installationCosts)),
            $marketPrices ?? new InMemoryMarketPrices($jitaUnitPrices),
            new InMemoryStructureMarketPrices(
                null === $structureUnitPrices ? [] : [self::MARKET_STRUCTURE_ID => $structureUnitPrices],
                null,
                [self::MARKET_STRUCTURE_ID => $structureSellVolumes],
            ),
            new InMemoryItemVolumes($cubicMetersByTypeId),
            new WorkTypeClassifier(new InMemoryShipProducts()),
        );

        return $planner->plan(
            $universe,
            $replans->closure(),
            new FavoriteSystems(self::OTHER_SYSTEM_ID, self::REACTION_SYSTEM_ID, new CourierHub(self::HUB_SYSTEM_ID, 800.0, $bridgeIskPerCubicMeter)),
            null === $structureUnitPrices ? null : self::MARKET_STRUCTURE_ID,
            null === $structureUnitPrices ? null : self::MARKET_SYSTEM_ID,
            [self::TARGET_PRODUCT],
            $buys,
            [],
        );
    }

    private function firstStation(): ProductionStructure
    {
        return ProductionStructures::raitaru('S1', solarSystemId: self::HUB_SYSTEM_ID);
    }

    private function secondStation(): ProductionStructure
    {
        return ProductionStructures::raitaru('S2', solarSystemId: self::OTHER_SYSTEM_ID);
    }

    private function reactionStation(): ProductionStructure
    {
        return ProductionStructures::tatara('R1', solarSystemId: self::REACTION_SYSTEM_ID);
    }

    private function job(int $productTypeId, ActivityKind $activity, int $runs, array $materialQuantities): Job
    {
        return new Job(
            $productTypeId,
            $activity,
            new Runs($runs),
            new MaterialEfficiency(0),
            new TimeEfficiency(0),
            array_map(static fn (int $quantity): Quantity => new Quantity($quantity), $materialQuantities),
        );
    }

    private function intermediate(int $demand, int $produced, array $jobs): Intermediate
    {
        return new Intermediate(new Quantity($demand), new Quantity(0), new Quantity($produced), new Quantity($produced - $demand), $jobs);
    }

    private function plannedProduction(array $jobs, array $leafQuantities, array $intermediates, array $stationsByProductTypeId): PlannedProduction
    {
        $choices = [];
        foreach ($jobs as $job) {
            $station = $stationsByProductTypeId[$job->productTypeId] ?? null;
            if (null !== $station) {
                $choices[PlannedProduction::choiceKey($job->productTypeId, $job->activity)] = new StructureChoice($station, StructureChoiceStatus::Assigned, Multiplier::one());
            }
        }

        return new PlannedProduction(
            new Plan($jobs, array_map(static fn (int $quantity): Quantity => new Quantity($quantity), $leafQuantities), $intermediates),
            $choices,
        );
    }

    private function lineOf(DeliveredSourcing $result, int $typeId): DeliveredSourcingLine
    {
        foreach ($result->lines as $line) {
            if ($typeId === $line->typeId) {
                return $line;
            }
        }

        self::fail(\sprintf('No line for the type %d.', $typeId));
    }
}
