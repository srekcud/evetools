<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\CourierHub;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\PlannedProduction;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureTotalCostComparator;
use App\Industry\Application\WorkStationAdvisor;
use App\Industry\Application\WorkStationRow;
use App\Industry\Application\WorkTransportKind;
use App\Industry\Application\WorkTransportLeg;
use App\Industry\Application\WorkType;
use App\Industry\Application\WorkTypeAdvice;
use App\Industry\Application\WorkTypeClassifier;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemoryShipProducts;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkStationAdvisor::class)]
#[Group('issue-1285')]
final class WorkStationAdvisorTest extends TestCase
{
    private const float ISK_TOLERANCE = 0.01;

    private const int TRITANIUM = 34;
    private const int MORPHITE = Example::MATERIAL_TYPE_ID;
    private const int FERNITE_COMPOSITE = 16670;
    private const int RAM_STARSHIP_TECH = 11478;
    private const int ISHTAR = Example::PRODUCT_TYPE_ID;
    private const int HECATE = 35683;
    private const int ARBITRATOR = 12006;

    private const float COURIER_ISK_PER_CUBIC_METER = 800.0;
    private const float JUMP_BRIDGE_ISK_PER_CUBIC_METER = 50.0;
    private const int HUB_SOLAR_SYSTEM_ID = Example::THREE_JUMPS_SYSTEM_ID;

    public function testOneEntryPerTypeOfWorkOfThePlanInTheOrderOfTheTypesWithTheirJobsAndRuns(): void
    {
        $advices = $this->adviseChain();

        $this->assertSame(
            [
                [WorkType::Reaction, ActivityKind::Reaction, 1, 5],
                [WorkType::Components, ActivityKind::Manufacturing, 2, 4],
                [WorkType::FinalProducts, ActivityKind::Manufacturing, 1, 2],
            ],
            array_map(static fn (WorkTypeAdvice $advice): array => [$advice->workType, $advice->activity, $advice->jobs, $advice->runs], $advices),
        );
    }

    public function testTypeOfWorkWithoutJobAndBlueprintsWithoutDeclaredLinesAreAbsent(): void
    {
        $advices = $this->adviseIshtar([Example::workshopStructureWithoutRig()]);

        $this->assertSame([WorkType::FinalProducts], array_map(static fn (WorkTypeAdvice $advice): WorkType => $advice->workType, $advices));
    }

    public function testHullsAreTheShipsThatAreNotTargetsOfTheProject(): void
    {
        $plan = new Plan(
            [
                $this->jobOf(self::ISHTAR, ActivityKind::Manufacturing, 10, [self::MORPHITE => 100_000]),
                $this->jobOf(self::HECATE, ActivityKind::Manufacturing, 3, [self::TRITANIUM => 12_000]),
            ],
            [self::MORPHITE => new Quantity(100_000), self::TRITANIUM => new Quantity(12_000)],
            [],
        );

        $advices = $this->advisor()->advise(new PlannedProduction($plan, []), [Example::workshopStructureWithoutRig()], $this->favoriteSystems(), [self::ISHTAR]);

        $this->assertSame([WorkType::Hulls, WorkType::FinalProducts], array_map(static fn (WorkTypeAdvice $advice): WorkType => $advice->workType, $advices));
        $hull = $this->rowOf($this->adviceFor(WorkType::Hulls, $advices), 'A');
        $this->assertEqualsWithDelta(60_000.0, $hull->materialsCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(4_320.0, $hull->installationCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(102_000.0, $hull->transportCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(166_320.0, $hull->totalCost, self::ISK_TOLERANCE);
    }

    public function testLowestTotalIsRecommendedAndEveryRowCarriesItsCostsTransportAndGapToTheRecommendedOne(): void
    {
        $advice = $this->adviceFor(WorkType::FinalProducts, $this->adviseIshtar([Example::workshopStructureWithoutRig(), Example::structureC()]));

        $this->assertSame('C', $advice->recommendedStructureId);
        $this->assertTrue($advice->costsCompared);
        $this->assertSame(['C', 'A'], $this->structureIdsOf($advice));
        $recommended = $this->rowOf($advice, 'C');
        $this->assertEqualsWithDelta(9_000_000.0, $recommended->materialsCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(850_000.0, $recommended->installationCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(800_000.0, $recommended->transportCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(10_650_000.0, $recommended->totalCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(0.0, $recommended->delta, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(0.9, $recommended->materialMultiplier->value, 1e-9);
        $this->assertFalse($recommended->inFavoriteSystem);
        $other = $this->rowOf($advice, 'A');
        $this->assertEqualsWithDelta(10_000_000.0, $other->materialsCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(900_000.0, $other->installationCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(900_000.0, $other->transportCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(11_800_000.0, $other->totalCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(1_150_000.0, $other->delta, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(1.0, $other->materialMultiplier->value, 1e-9);
        $this->assertTrue($other->inFavoriteSystem);
    }

    public function testTransportOfTheComparatorPerJumpIsNotCountedAndAMissingJumpRouteDoesNotMakeARowUnknown(): void
    {
        $advice = $this->adviceFor(WorkType::FinalProducts, $this->adviseIshtar([Example::workshopStructureWithoutRig()], $this->favoriteSystemsWithoutFavorite()));

        $row = $this->rowOf($advice, 'A');
        $this->assertEqualsWithDelta(11_800_000.0, $row->totalCost, self::ISK_TOLERANCE);
        $this->assertSame([], $row->missingData);
    }

    public function testEqualTotalsAreBrokenByTheFavoriteSystemFirst(): void
    {
        $structures = [Example::riggedStructure('X', Example::WORKSHOP_SYSTEM_ID), Example::riggedStructure('Y', Example::SECOND_THREE_JUMPS_SYSTEM_ID, '2026-03-01 00:00:00')];

        $advice = $this->adviceFor(WorkType::FinalProducts, $this->adviseIshtar($structures, new FavoriteSystems(Example::WORKSHOP_SYSTEM_ID, null, $this->courierHub())));

        $this->assertSame('X', $advice->recommendedStructureId);
        $this->assertEqualsWithDelta(10_750_000.0, $this->rowOf($advice, 'X')->totalCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(10_750_000.0, $this->rowOf($advice, 'Y')->totalCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(0.0, $this->rowOf($advice, 'Y')->delta, self::ISK_TOLERANCE);
    }

    public function testEqualTotalsWithoutFavoriteSystemAreBrokenByTheMostRecentlyConfiguredStructure(): void
    {
        $structures = [Example::riggedStructure('X', Example::WORKSHOP_SYSTEM_ID), Example::riggedStructure('Y', Example::SECOND_THREE_JUMPS_SYSTEM_ID, '2026-03-01 00:00:00')];

        $advice = $this->adviceFor(WorkType::FinalProducts, $this->adviseIshtar($structures, $this->favoriteSystemsWithoutFavorite()));

        $this->assertSame('Y', $advice->recommendedStructureId);
    }

    public function testEqualTotalsConfiguredAtTheSameTimeWithoutFavoriteSystemAreBrokenById(): void
    {
        $structures = [Example::riggedStructure('Y', Example::SECOND_THREE_JUMPS_SYSTEM_ID), Example::riggedStructure('X', Example::WORKSHOP_SYSTEM_ID)];

        $advice = $this->adviceFor(WorkType::FinalProducts, $this->adviseIshtar($structures, $this->favoriteSystemsWithoutFavorite()));

        $this->assertSame('X', $advice->recommendedStructureId);
    }

    public function testRowWhoseSolarSystemIsUnknownIsRankedAfterTheKnownOnesNeverRecommendedAndHasNoAmountsOrGap(): void
    {
        $advice = $this->adviceFor(WorkType::FinalProducts, $this->adviseIshtar([Example::riggedStructure('Z', null), Example::workshopStructureWithoutRig()]));

        $this->assertSame('A', $advice->recommendedStructureId);
        $this->assertSame(['A', 'Z'], $this->structureIdsOf($advice));
        $unknown = $this->rowOf($advice, 'Z');
        $this->assertEqualsWithDelta(9_000_000.0, $unknown->materialsCost, self::ISK_TOLERANCE);
        $this->assertNull($unknown->installationCost);
        $this->assertNull($unknown->transportCost);
        $this->assertNull($unknown->totalCost);
        $this->assertNull($unknown->delta);
        $this->assertContainsEquals(MissingData::costIndex(), $unknown->missingData);
        $this->assertContainsEquals(MissingData::jumpRoute(), $unknown->missingData);
        $courierLeg = $this->legOfKind($unknown, WorkTransportKind::Courier);
        $this->assertLeg($courierLeg, WorkTransportKind::Courier, null, 'Z', 1_000.0, null);
    }

    public function testOnlyUnknownCandidatesLeaveNothingRecommended(): void
    {
        $advice = $this->adviceFor(WorkType::FinalProducts, $this->adviseIshtar([Example::riggedStructure('Z', null)]));

        $this->assertNull($advice->recommendedStructureId);
        $this->assertNull($this->rowOf($advice, 'Z')->totalCost);
    }

    public function testWithoutCourierHubEveryTravelIsUnknownAndTheCourierHubIsMissingData(): void
    {
        $advice = $this->adviceFor(WorkType::FinalProducts, $this->adviseIshtar([Example::workshopStructureWithoutRig()], new FavoriteSystems(Example::WORKSHOP_SYSTEM_ID, null)));

        $row = $this->rowOf($advice, 'A');
        $this->assertNull($this->legOfKind($row, WorkTransportKind::Courier)->cost);
        $this->assertNull($this->legOfKind($row, WorkTransportKind::ToHub)->cost);
        $this->assertNull($row->totalCost);
        $this->assertContainsEquals(MissingData::courierHub(), $row->missingData);
        $this->assertNull($advice->recommendedStructureId);
    }

    public function testSingleCandidateIsRecommendedWithAGapOfZero(): void
    {
        $advice = $this->adviceFor(WorkType::FinalProducts, $this->adviseIshtar([Example::structureC()]));

        $this->assertSame('C', $advice->recommendedStructureId);
        $this->assertEqualsWithDelta(0.0, $this->rowOf($advice, 'C')->delta, self::ISK_TOLERANCE);
    }

    public function testTypeOfWorkWithoutCandidateStructureHasNoRowAndNoRecommendation(): void
    {
        $advice = $this->adviceFor(WorkType::FinalProducts, $this->adviseIshtar([Example::workshopRefinery()]));

        $this->assertSame([], $advice->rows);
        $this->assertNull($advice->recommendedStructureId);
        $this->assertNull($advice->chosenStructureId);
    }

    public function testPurchasedMaterialsToAStationOutsideTheHubSystemCostTheJitaToHubAndHubToStationRates(): void
    {
        $components = $this->adviceFor(WorkType::Components, $this->adviseChain());

        $this->assertLeg($this->legOfKind($this->rowOf($components, 'A'), WorkTransportKind::Courier), WorkTransportKind::Courier, null, 'A', 100.0, 85_000.0);
    }

    public function testPurchasedMaterialsToAStationOfTheHubSystemCostTheJitaToHubRateOnly(): void
    {
        $components = $this->adviceFor(WorkType::Components, $this->adviseChain());

        $this->assertLeg($this->legOfKind($this->rowOf($components, 'C'), WorkTransportKind::Courier), WorkTransportKind::Courier, null, 'C', 100.0, 80_000.0);
    }

    public function testIntermediatesFromAnotherTypeTravelFromItsStationOverTheJumpBridgeWhenTheSystemsDiffer(): void
    {
        $components = $this->adviceFor(WorkType::Components, $this->adviseChain());

        $this->assertLeg($this->legOfKind($this->rowOf($components, 'C'), WorkTransportKind::JumpBridge), WorkTransportKind::JumpBridge, 'A-refinery', 'C', 800.0, 40_000.0);
    }

    public function testIntermediatesFromAStructureOfTheSameSystemTravelForFree(): void
    {
        $components = $this->adviceFor(WorkType::Components, $this->adviseChain());

        $this->assertLeg($this->legOfKind($this->rowOf($components, 'A'), WorkTransportKind::JumpBridge), WorkTransportKind::JumpBridge, 'A-refinery', 'A', 800.0, 0.0);
    }

    public function testComponentsOfTheRecommendedStationTravelToTheFinalProductsStationOverTheJumpBridge(): void
    {
        $final = $this->adviceFor(WorkType::FinalProducts, $this->adviseChain());

        $this->assertLeg($this->legOfKind($this->rowOf($final, 'C'), WorkTransportKind::JumpBridge), WorkTransportKind::JumpBridge, 'A', 'C', 200.0, 10_000.0);
        $this->assertLeg($this->legOfKind($this->rowOf($final, 'A'), WorkTransportKind::JumpBridge), WorkTransportKind::JumpBridge, 'A', 'A', 200.0, 0.0);
    }

    public function testFinalProductsLeaveForTheHubForFreeFromTheHubSystemAndOverTheJumpBridgeFromElsewhere(): void
    {
        $final = $this->adviceFor(WorkType::FinalProducts, $this->adviseChain());

        $this->assertLeg($this->legOfKind($this->rowOf($final, 'C'), WorkTransportKind::ToHub), WorkTransportKind::ToHub, 'C', null, 1_000.0, 0.0);
        $this->assertLeg($this->legOfKind($this->rowOf($final, 'A'), WorkTransportKind::ToHub), WorkTransportKind::ToHub, 'A', null, 1_000.0, 50_000.0);
    }

    public function testRowTotalIsMaterialsPlusInstallationPlusTheSumOfItsTravels(): void
    {
        $advices = $this->adviseChain();

        $reaction = $this->rowOf($this->adviceFor(WorkType::Reaction, $advices), 'A-refinery');
        $this->assertEqualsWithDelta(175_000.0, $reaction->materialsCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(12_600.0, $reaction->installationCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(297_500.0, $reaction->transportCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(485_100.0, $reaction->totalCost, self::ISK_TOLERANCE);
        $components = $this->adviceFor(WorkType::Components, $advices);
        $this->assertEqualsWithDelta(480_200.0, $this->rowOf($components, 'A')->totalCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(513_800.0, $this->rowOf($components, 'C')->totalCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(33_600.0, $this->rowOf($components, 'C')->delta, self::ISK_TOLERANCE);
        $this->assertSame('A', $components->recommendedStructureId);
        $final = $this->adviceFor(WorkType::FinalProducts, $advices);
        $this->assertEqualsWithDelta(438_500.0, $this->rowOf($final, 'A')->totalCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(396_900.0, $this->rowOf($final, 'C')->totalCost, self::ISK_TOLERANCE);
        $this->assertSame('C', $final->recommendedStructureId);
    }

    public function testStoredChoiceOfAnotherTypeReplacesItsRecommendedStationInTheTravels(): void
    {
        $advices = $this->adviseChain(['components' => 'C']);

        $final = $this->adviceFor(WorkType::FinalProducts, $advices);
        $this->assertLeg($this->legOfKind($this->rowOf($final, 'A'), WorkTransportKind::JumpBridge), WorkTransportKind::JumpBridge, 'C', 'A', 200.0, 10_000.0);
        $this->assertLeg($this->legOfKind($this->rowOf($final, 'C'), WorkTransportKind::JumpBridge), WorkTransportKind::JumpBridge, 'C', 'C', 200.0, 0.0);
        $this->assertEqualsWithDelta(448_500.0, $this->rowOf($final, 'A')->totalCost, self::ISK_TOLERANCE);
        $this->assertEqualsWithDelta(386_900.0, $this->rowOf($final, 'C')->totalCost, self::ISK_TOLERANCE);
    }

    public function testStoredChoiceDifferentFromTheRecommendedIsKeptWithTheSavingIfTheRecommendedIsUsed(): void
    {
        $components = $this->adviceFor(WorkType::Components, $this->adviseChain(['components' => 'C']));

        $this->assertSame(['A', 'C', false], [$components->recommendedStructureId, $components->chosenStructureId, $components->choiceStale]);
        $this->assertEqualsWithDelta(33_600.0, $components->savingIfRecommended, self::ISK_TOLERANCE);
    }

    public function testStoredChoiceEqualToTheRecommendedHasNoSaving(): void
    {
        $components = $this->adviceFor(WorkType::Components, $this->adviseChain(['components' => 'A']));

        $this->assertSame(['A', 'A', false, null], [$components->recommendedStructureId, $components->chosenStructureId, $components->choiceStale, $components->savingIfRecommended]);
    }

    public function testWithoutStoredChoiceNothingIsChosenAndNothingIsStale(): void
    {
        $components = $this->adviceFor(WorkType::Components, $this->adviseChain());

        $this->assertSame([null, false, null], [$components->chosenStructureId, $components->choiceStale, $components->savingIfRecommended]);
    }

    public function testStoredStructureThatIsNoLongerAmongTheStructuresOfTheUserIsStaleAndNotChosen(): void
    {
        $advice = $this->adviceFor(WorkType::FinalProducts, $this->adviseIshtar([Example::workshopStructureWithoutRig(), Example::structureC()], null, ['final_products' => 'deleted']));

        $this->assertSame([null, true, null, 'C'], [$advice->chosenStructureId, $advice->choiceStale, $advice->savingIfRecommended, $advice->recommendedStructureId]);
    }

    public function testStoredStructureThatCannotRunTheActivityAnymoreIsStaleAndNotChosen(): void
    {
        $advice = $this->adviceFor(WorkType::FinalProducts, $this->adviseIshtar([Example::workshopStructureWithoutRig(), Example::workshopRefinery()], null, ['final_products' => 'A-refinery']));

        $this->assertSame([null, true, ['A']], [$advice->chosenStructureId, $advice->choiceStale, $this->structureIdsOf($advice)]);
    }

    public function testBlueprintsAreListedOnlyWhenTheBomHasBlueprintLinesWithTheStructuresAbleToCopyAndNoAmounts(): void
    {
        $advices = $this->adviseIshtar([Example::workshopStructureWithoutRig(), Example::structureC(), Example::workshopRefinery()], null, [], true);

        $this->assertSame([WorkType::FinalProducts, WorkType::Blueprints], array_map(static fn (WorkTypeAdvice $advice): WorkType => $advice->workType, $advices));
        $blueprints = $this->adviceFor(WorkType::Blueprints, $advices);
        $this->assertSame([ActivityKind::Copying, 'C', false], [$blueprints->activity, $blueprints->recommendedStructureId, $blueprints->costsCompared]);
        $ids = $this->structureIdsOf($blueprints);
        sort($ids);
        $this->assertSame(['A', 'C'], $ids);
        foreach ($blueprints->rows as $row) {
            $this->assertSame([null, null, null, null, null, []], [$row->materialsCost, $row->installationCost, $row->transportCost, $row->totalCost, $row->delta, $row->transportLegs]);
        }
        $this->assertTrue($this->adviceFor(WorkType::FinalProducts, $advices)->costsCompared);
    }

    public function testStoredBlueprintStructureIsChosenWithoutSaving(): void
    {
        $blueprints = $this->adviceFor(WorkType::Blueprints, $this->adviseIshtar([Example::workshopStructureWithoutRig(), Example::structureC()], null, ['blueprints' => 'A'], true));

        $this->assertSame(['C', 'A', false, null], [$blueprints->recommendedStructureId, $blueprints->chosenStructureId, $blueprints->choiceStale, $blueprints->savingIfRecommended]);
    }

    /**
     * @param array<string, string> $storedStructureIds
     *
     * @return list<WorkTypeAdvice>
     */
    private function adviseChain(array $storedStructureIds = []): array
    {
        $plan = new Plan(
            [
                $this->jobOf(self::ARBITRATOR, ActivityKind::Manufacturing, 2, [self::RAM_STARSHIP_TECH => 10, self::MORPHITE => 600]),
                $this->jobOf(self::RAM_STARSHIP_TECH, ActivityKind::Manufacturing, 3, [self::FERNITE_COMPOSITE => 120, self::TRITANIUM => 7_500]),
                $this->jobOf(self::RAM_STARSHIP_TECH, ActivityKind::Manufacturing, 1, [self::FERNITE_COMPOSITE => 40, self::TRITANIUM => 2_500]),
                $this->jobOf(self::FERNITE_COMPOSITE, ActivityKind::Reaction, 5, [self::TRITANIUM => 35_000]),
            ],
            [self::TRITANIUM => new Quantity(45_000), self::MORPHITE => new Quantity(600)],
            [],
        );

        return $this->advisor()->advise(
            new PlannedProduction($plan, []),
            [Example::workshopRefinery(), Example::workshopStructureWithoutRig(), Example::structureC()],
            $this->favoriteSystems(),
            [self::ARBITRATOR],
            $storedStructureIds,
        );
    }

    /**
     * @param list<ProductionStructure> $structures
     * @param array<string, string>     $storedStructureIds
     *
     * @return list<WorkTypeAdvice>
     */
    private function adviseIshtar(array $structures, ?FavoriteSystems $favoriteSystems = null, array $storedStructureIds = [], bool $includesBlueprints = false): array
    {
        $plan = new Plan(
            [$this->jobOf(self::ISHTAR, ActivityKind::Manufacturing, 10, [self::MORPHITE => 100_000])],
            [self::MORPHITE => new Quantity(100_000)],
            [],
        );

        return $this->advisor()->advise(new PlannedProduction($plan, []), $structures, $favoriteSystems ?? $this->favoriteSystems(), [self::ISHTAR], $storedStructureIds, $includesBlueprints);
    }

    private function advisor(): WorkStationAdvisor
    {
        $volumes = new InMemoryItemVolumes([
            self::TRITANIUM => 0.01,
            self::MORPHITE => 0.01,
            self::FERNITE_COMPOSITE => 5.0,
            self::RAM_STARSHIP_TECH => 20.0,
            self::ISHTAR => 100.0,
            self::ARBITRATOR => 500.0,
            self::HECATE => 80.0,
        ]);

        return new WorkStationAdvisor(
            new InMemoryBlueprintCatalog(
                Example::recipe(),
                $this->recipeOf(self::FERNITE_COMPOSITE, ActivityKind::Reaction, [self::TRITANIUM => 7_000]),
                $this->recipeOf(self::RAM_STARSHIP_TECH, ActivityKind::Manufacturing, [self::FERNITE_COMPOSITE => 40, self::TRITANIUM => 2_500]),
                $this->recipeOf(self::ARBITRATOR, ActivityKind::Manufacturing, [self::RAM_STARSHIP_TECH => 5, self::MORPHITE => 300]),
                $this->recipeOf(self::HECATE, ActivityKind::Manufacturing, [self::TRITANIUM => 4_000]),
            ),
            new InMemoryRigCategories([self::ISHTAR => Example::PRODUCT_CATEGORY]),
            new StructureTotalCostComparator(
                new InMemoryMarketPrices([self::TRITANIUM => 5.0, self::MORPHITE => 100.0, self::FERNITE_COMPOSITE => 2_000.0, self::RAM_STARSHIP_TECH => 30_000.0]),
                new InMemoryAdjustedPrices([self::TRITANIUM => 4.0, self::MORPHITE => 100.0, self::FERNITE_COMPOSITE => 1_500.0, self::RAM_STARSHIP_TECH => 20_000.0]),
                Example::costIndices(),
                Example::jumpDistances(),
                $volumes,
            ),
            Example::costIndices(),
            $volumes,
            new WorkTypeClassifier(new InMemoryShipProducts([self::HECATE, self::ISHTAR, self::ARBITRATOR])),
        );
    }

    private function favoriteSystems(): FavoriteSystems
    {
        return new FavoriteSystems(Example::WORKSHOP_SYSTEM_ID, null, $this->courierHub());
    }

    private function favoriteSystemsWithoutFavorite(): FavoriteSystems
    {
        return new FavoriteSystems(null, null, $this->courierHub());
    }

    private function courierHub(): CourierHub
    {
        return new CourierHub(self::HUB_SOLAR_SYSTEM_ID, self::COURIER_ISK_PER_CUBIC_METER, self::JUMP_BRIDGE_ISK_PER_CUBIC_METER);
    }

    /**
     * @param array<int, int> $quantitiesByMaterial
     */
    private function jobOf(int $productTypeId, ActivityKind $activity, int $runs, array $quantitiesByMaterial): Job
    {
        return new Job(
            $productTypeId,
            $activity,
            new Runs($runs),
            new MaterialEfficiency(0),
            new TimeEfficiency(0),
            array_map(static fn (int $quantity): Quantity => new Quantity($quantity), $quantitiesByMaterial),
        );
    }

    /**
     * @param array<int, int> $baseQuantitiesPerRun
     */
    private function recipeOf(int $productTypeId, ActivityKind $activity, array $baseQuantitiesPerRun): Recipe
    {
        $materials = [];
        foreach ($baseQuantitiesPerRun as $materialTypeId => $baseQuantityPerRun) {
            $materials[] = new RecipeMaterial($materialTypeId, new Quantity($baseQuantityPerRun));
        }

        return new Recipe($productTypeId, $activity, new OutputPerRun(1), $materials, new Runs(300), 3600);
    }

    /**
     * @param list<WorkTypeAdvice> $advices
     */
    private function adviceFor(WorkType $workType, array $advices): WorkTypeAdvice
    {
        $found = array_values(array_filter($advices, static fn (WorkTypeAdvice $advice): bool => $advice->workType === $workType));
        $this->assertCount(1, $found, \sprintf('Expected one advice for the type of work %s.', $workType->value));

        return $found[0];
    }

    private function rowOf(WorkTypeAdvice $advice, string $structureId): WorkStationRow
    {
        $found = array_values(array_filter($advice->rows, static fn (WorkStationRow $row): bool => $row->structure->id === $structureId));
        $this->assertCount(1, $found, \sprintf('Expected one row for the structure %s.', $structureId));

        return $found[0];
    }

    private function legOfKind(WorkStationRow $row, WorkTransportKind $kind): WorkTransportLeg
    {
        $found = array_values(array_filter($row->transportLegs, static fn (WorkTransportLeg $leg): bool => $leg->kind === $kind));
        $this->assertCount(1, $found, \sprintf('Expected one %s leg.', $kind->value));

        return $found[0];
    }

    private function assertLeg(WorkTransportLeg $leg, WorkTransportKind $kind, ?string $fromStructureId, ?string $toStructureId, float $cubicMeters, ?float $cost): void
    {
        $this->assertSame([$kind, $fromStructureId, $toStructureId], [$leg->kind, $leg->fromStructureId, $leg->toStructureId]);
        $this->assertEqualsWithDelta($cubicMeters, $leg->cubicMeters, self::ISK_TOLERANCE);
        if (null === $cost) {
            $this->assertNull($leg->cost);

            return;
        }
        $this->assertNotNull($leg->cost);
        $this->assertEqualsWithDelta($cost, $leg->cost, self::ISK_TOLERANCE);
    }

    /**
     * @return list<string>
     */
    private function structureIdsOf(WorkTypeAdvice $advice): array
    {
        return array_map(static fn (WorkStationRow $row): string => $row->structure->id, $advice->rows);
    }
}
