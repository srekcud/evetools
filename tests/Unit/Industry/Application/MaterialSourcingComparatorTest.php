<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Constant\EveConstants;
use App\Industry\Application\CourierHub;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\JitaPurchase;
use App\Industry\Application\MaterialSourcing;
use App\Industry\Application\MaterialSourcingComparator;
use App\Industry\Application\MaterialToSource;
use App\Industry\Application\SourcingSide;
use App\Industry\Application\StructureStock;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Quantity;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemoryJumpDistances;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryStructureMarketPrices;
use App\Tests\Unit\Industry\Application\Double\RecordingMarketPrices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaterialSourcingComparator::class)]
#[CoversClass(MaterialSourcing::class)]
#[CoversClass(JitaPurchase::class)]
#[CoversClass(MissingData::class)]
#[Group('issue-466')]
final class MaterialSourcingComparatorTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;

    private const int STRUCTURE_ID = 1_035_466_543_993;
    private const int MANUFACTURING_SYSTEM_ID = 30_002_187;
    private const int REACTION_SYSTEM_ID = 30_002_659;
    private const int HUB_SYSTEM_ID = 30_000_772;
    private const int HUB_JUMPS = 4;
    private const int MANUFACTURING_JUMPS = 9;
    private const int REACTION_JUMPS = 2;
    private const float DELTA = 0.001;
    private const string PYERITE_LINE_ID = '0192f3a4-5b6c-7d8e-9f01-23456789abcd';
    private const string TRITANIUM_LINE_ID = '0192f3a4-5b6c-7d8e-9f01-23456789abce';

    public function testExampleAStructureCheaperThanJitaWithImportIsBoughtAtTheStructureAndSavesTheDifference(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::PYERITE => 20.0],
            volumes: [self::PYERITE => 0.01],
            sellVolumes: [self::PYERITE => 1_000],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, $this->favoriteSystems(), self::MANUFACTURING_SYSTEM_ID);

        self::assertCount(1, $sourcings);
        self::assertEqualsWithDelta(
            [self::PYERITE, SourcingSide::Structure, 10_000.0, 12_500.0, 22_500.0, 20_000.0, 2_500.0, false, self::MANUFACTURING_JUMPS, 0.01],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertEqualsWithDelta(20_000.0, $sourcings[0]->bestCost()->amount()->amount, self::DELTA);
    }

    public function testExampleBJitaWithImportCheaperThanTheStructureIsBoughtAtJita(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::TRITANIUM => 5.0],
            structurePrices: [self::TRITANIUM => 20.0],
            volumes: [self::TRITANIUM => 0.01],
            sellVolumes: [self::TRITANIUM => 10_000],
        )->compare([$this->material(self::TRITANIUM, 10_000)], self::STRUCTURE_ID, $this->favoriteSystems(), self::MANUFACTURING_SYSTEM_ID);

        self::assertEqualsWithDelta(
            [self::TRITANIUM, SourcingSide::Jita, 50_000.0, 125_000.0, 175_000.0, 200_000.0, 25_000.0, false, self::MANUFACTURING_JUMPS, 0.01],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertEqualsWithDelta(175_000.0, $sourcings[0]->bestCost()->amount()->amount, self::DELTA);
    }

    public function testExampleCTieBetweenJitaWithImportAndTheStructureGoesToJitaAndSavesNothing(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::MEXALLON => 100.0],
            structurePrices: [self::MEXALLON => 1_350.0],
            volumes: [self::MEXALLON => 1.0],
            jumps: [self::REACTION_SYSTEM_ID => self::REACTION_JUMPS],
            sellVolumes: [self::MEXALLON => 100],
        )->compare([new MaterialToSource(self::MEXALLON, new Quantity(100), ActivityKind::Reaction)], self::STRUCTURE_ID, $this->favoriteSystems(), self::REACTION_SYSTEM_ID);

        self::assertEqualsWithDelta(
            [self::MEXALLON, SourcingSide::Jita, 10_000.0, 125_000.0, 135_000.0, 135_000.0, 0.0, false, self::REACTION_JUMPS, 1.0],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
    }

    public function testExampleDMaterialWithoutStructurePriceGoesToJitaAndIsFlaggedAsFallback(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::TRITANIUM => 5.0],
            volumes: [self::PYERITE => 0.01],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, $this->favoriteSystems());

        self::assertEqualsWithDelta(
            [self::PYERITE, SourcingSide::Jita, 10_000.0, 12_500.0, 22_500.0, null, null, true, self::MANUFACTURING_JUMPS, 0.01],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertEquals([MissingData::structurePrice(self::PYERITE)], $sourcings[0]->structureCost->missingData);
    }

    public function testExampleEMaterialWithoutJitaPriceIsBoughtAtTheStructureAndKeepsTheMissingJitaPrice(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [],
            structurePrices: [self::PYERITE => 20.0],
            volumes: [self::PYERITE => 0.01],
            sellVolumes: [self::PYERITE => 1_000],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, $this->favoriteSystems(), self::MANUFACTURING_SYSTEM_ID);

        self::assertEqualsWithDelta(
            [self::PYERITE, SourcingSide::Structure, null, 12_500.0, null, 20_000.0, null, false, self::MANUFACTURING_JUMPS, 0.01],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertEquals([MissingData::marketPrice(self::PYERITE)], $sourcings[0]->jitaWithImportCost()->missingData);
    }

    public function testExampleFMaterialWithoutVolumeHasAnUnknownImportAndAnUnknownStructureTransportSoTheSideIsUnknown(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::PYERITE => 20.0],
            volumes: [],
            sellVolumes: [self::PYERITE => 1_000],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, $this->favoriteSystems(), self::HUB_SYSTEM_ID);

        self::assertEqualsWithDelta(
            [self::PYERITE, SourcingSide::Unknown, 10_000.0, null, null, 20_000.0, null, false, self::MANUFACTURING_JUMPS, null],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertEquals([MissingData::itemVolume(self::PYERITE)], $sourcings[0]->importCost->missingData);
        self::assertEquals([MissingData::itemVolume(self::PYERITE)], $sourcings[0]->structureBridgeCost->missingData);
        self::assertFalse($sourcings[0]->structureDeliveredCost()->isKnown());
    }

    public function testExampleGWorkshopSystemWithoutRouteFromJitaKeepsTheImportKnownBecauseItNoLongerDependsOnJumps(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::PYERITE => 20.0],
            volumes: [self::PYERITE => 0.01],
            jumps: [],
            sellVolumes: [self::PYERITE => 1_000],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, $this->favoriteSystems(), self::MANUFACTURING_SYSTEM_ID);

        self::assertEqualsWithDelta(
            [self::PYERITE, SourcingSide::Structure, 10_000.0, 12_500.0, 22_500.0, 20_000.0, 2_500.0, false, null, 0.01],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertSame([], $sourcings[0]->importCost->missingData);
    }

    public function testWorkshopInTheHubSystemPaysTheRateFromJitaWithoutTheBridge(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::PYERITE => 20.0],
            volumes: [self::PYERITE => 0.01],
            jumps: [self::HUB_SYSTEM_ID => self::HUB_JUMPS],
            sellVolumes: [self::PYERITE => 1_000],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, new FavoriteSystems(self::HUB_SYSTEM_ID, self::REACTION_SYSTEM_ID, $this->hub()), self::HUB_SYSTEM_ID);

        self::assertEqualsWithDelta(
            [self::PYERITE, SourcingSide::Structure, 10_000.0, 12_000.0, 22_000.0, 20_000.0, 2_000.0, false, self::HUB_JUMPS, 0.01],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
    }

    public function testWithoutCourierHubTheImportIsUnknownBecauseOfTheCourierHubAndTheMaterialIsBoughtAtTheStructure(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::PYERITE => 20.0],
            volumes: [self::PYERITE => 0.01],
            sellVolumes: [self::PYERITE => 1_000],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, new FavoriteSystems(self::MANUFACTURING_SYSTEM_ID, self::REACTION_SYSTEM_ID), self::MANUFACTURING_SYSTEM_ID);

        self::assertEqualsWithDelta(
            [self::PYERITE, SourcingSide::Structure, 10_000.0, null, null, 20_000.0, null, false, self::MANUFACTURING_JUMPS, 0.01],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertEquals([MissingData::courierHub()], $sourcings[0]->importCost->missingData);
    }

    public function testWithoutCourierHubNorVolumeTheImportListsBothCauses(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::PYERITE => 20.0],
            volumes: [],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, new FavoriteSystems(self::MANUFACTURING_SYSTEM_ID, self::REACTION_SYSTEM_ID));

        self::assertEqualsCanonicalizing(
            [MissingData::courierHub(), MissingData::itemVolume(self::PYERITE)],
            $sourcings[0]->importCost->missingData,
        );
    }

    public function testExampleHNeitherSidePricedLeavesTheSideUnknown(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [],
            structurePrices: [self::TRITANIUM => 5.0],
            volumes: [self::PYERITE => 0.01],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, $this->favoriteSystems());

        self::assertSame(SourcingSide::Unknown, $sourcings[0]->side);
        self::assertNull($sourcings[0]->saving);
        self::assertFalse($sourcings[0]->bestCost()->isKnown());
    }

    public function testExampleIWithoutPreferredStructureEveryMaterialGoesToJitaWithoutComparisonOrFallbackFlag(): void
    {
        $structurePrices = new InMemoryStructureMarketPrices([self::STRUCTURE_ID => [self::PYERITE => 1.0]]);

        $sourcings = $this->comparatorWith(
            new InMemoryMarketPrices([self::PYERITE => 10.0]),
            $structurePrices,
            [self::PYERITE => 0.01],
            [self::MANUFACTURING_SYSTEM_ID => self::MANUFACTURING_JUMPS],
        )->compare([$this->material(self::PYERITE, 1_000)], null, $this->favoriteSystems());

        self::assertEqualsWithDelta(
            [self::PYERITE, SourcingSide::Jita, 10_000.0, 12_500.0, 22_500.0, null, null, false, self::MANUFACTURING_JUMPS, 0.01],
            $this->rowOf($sourcings[0]),
            self::DELTA,
        );
        self::assertSame([], $structurePrices->requests);
        self::assertSame([], $structurePrices->volumeRequests);
        self::assertSame([null, StructureStock::Unknown], [$sourcings[0]->structureAvailableQuantity, $sourcings[0]->structureStock()]);
    }

    public function testJitaIsPricedOnTheRemainingQuantitiesAndTheStructureIsAskedOnceForAllTypes(): void
    {
        $marketPrices = new RecordingMarketPrices(new InMemoryMarketPrices([self::PYERITE => 10.0, self::TRITANIUM => 5.0]));
        $structurePrices = new InMemoryStructureMarketPrices([self::STRUCTURE_ID => []]);

        $this->comparatorWith($marketPrices, $structurePrices, [], [])->compare(
            [$this->material(self::PYERITE, 1_000), $this->material(self::TRITANIUM, 250)],
            self::STRUCTURE_ID,
            $this->favoriteSystems(),
        );

        self::assertSame([[self::PYERITE => 1_000.0, self::TRITANIUM => 250.0]], $marketPrices->requests);
        self::assertSame([[self::STRUCTURE_ID, [self::PYERITE, self::TRITANIUM]]], $structurePrices->requests);
        self::assertSame([[self::STRUCTURE_ID, [self::PYERITE, self::TRITANIUM]]], $structurePrices->volumeRequests);
    }

    public function testPartialJitaCoverageIsKeptOnTheJitaWithImportCost(): void
    {
        $sourcings = $this->comparatorWith(
            new InMemoryMarketPrices([self::PYERITE => 10.0], [self::PYERITE => 0.6]),
            new InMemoryStructureMarketPrices([self::STRUCTURE_ID => []]),
            [self::PYERITE => 0.01],
            [self::MANUFACTURING_SYSTEM_ID => self::MANUFACTURING_JUMPS],
        )->compare([$this->material(self::PYERITE, 1_000)], self::STRUCTURE_ID, $this->favoriteSystems());

        $partialPrices = $sourcings[0]->jitaWithImportCost()->partialPrices;
        self::assertCount(1, $partialPrices);
        self::assertSame(self::PYERITE, $partialPrices[0]->typeId);
    }

    public function testNoMaterialAsksForNothingAndReturnsNothing(): void
    {
        $marketPrices = new RecordingMarketPrices(new InMemoryMarketPrices([]));
        $structurePrices = new InMemoryStructureMarketPrices([self::STRUCTURE_ID => []]);

        $sourcings = $this->comparatorWith($marketPrices, $structurePrices, [], [])->compare([], self::STRUCTURE_ID, $this->favoriteSystems());

        self::assertSame([], $sourcings);
        self::assertSame([], $marketPrices->requests);
        self::assertSame([], $structurePrices->requests);
        self::assertSame([], $structurePrices->volumeRequests);
    }

    #[Group('issue-1287')]
    public function testExample1StructureAtTheHubCheaperWithEnoughStockIsRecommendedWithItsBridgeInTheDeliveredTotal(): void
    {
        $sourcing = $this->compareForMarketChoice(jitaPrices: [self::PYERITE => 10.0], structurePrices: [self::PYERITE => 12.0], sellVolumes: [self::PYERITE => 100], structureSystemId: self::HUB_SYSTEM_ID);

        self::assertSame(
            [1_850.0, 1_200.0, 50.0, 1_250.0, 100, StructureStock::Enough, SourcingSide::Structure, 600.0],
            $this->deliveredFieldsOf($sourcing),
        );
        self::assertSame(1_250.0, $sourcing->bestCost()->amount()->amount);
    }

    #[Group('issue-1287')]
    public function testExample2CheaperStructureWithOneUnitTooLittleStockLosesToJitaAndReportsInsufficientStock(): void
    {
        $sourcing = $this->compareForMarketChoice(jitaPrices: [self::PYERITE => 10.0], structurePrices: [self::PYERITE => 12.0], sellVolumes: [self::PYERITE => 99], structureSystemId: self::HUB_SYSTEM_ID);

        self::assertSame(
            [1_850.0, 1_200.0, 50.0, 1_250.0, 99, StructureStock::Insufficient, SourcingSide::Jita, 600.0],
            $this->deliveredFieldsOf($sourcing),
        );
        self::assertSame(1_850.0, $sourcing->bestCost()->amount()->amount);
    }

    #[Group('issue-1287')]
    public function testExample3StructureInTheWorkshopSystemPaysNoBridge(): void
    {
        $sourcing = $this->compareForMarketChoice(jitaPrices: [self::PYERITE => 10.0], structurePrices: [self::PYERITE => 12.0], sellVolumes: [self::PYERITE => 100], structureSystemId: self::MANUFACTURING_SYSTEM_ID);

        self::assertSame(
            [1_850.0, 1_200.0, 0.0, 1_200.0, 100, StructureStock::Enough, SourcingSide::Structure, 650.0],
            $this->deliveredFieldsOf($sourcing),
        );
    }

    #[Group('issue-1287')]
    public function testExample4StructureDearerThanJitaWithImportIsNotRecommendedEvenWithEnoughStock(): void
    {
        $sourcing = $this->compareForMarketChoice(jitaPrices: [self::PYERITE => 10.0], structurePrices: [self::PYERITE => 30.0], sellVolumes: [self::PYERITE => 100], structureSystemId: self::HUB_SYSTEM_ID);

        self::assertSame(
            [1_850.0, 3_000.0, 50.0, 3_050.0, 100, StructureStock::Enough, SourcingSide::Jita, 1_200.0],
            $this->deliveredFieldsOf($sourcing),
        );
    }

    #[Group('issue-1287')]
    public function testExample5WithoutJitaPriceAndInsufficientStructureStockNoSideIsEligible(): void
    {
        $sourcing = $this->compareForMarketChoice(jitaPrices: [], structurePrices: [self::PYERITE => 12.0], sellVolumes: [self::PYERITE => 99], structureSystemId: self::HUB_SYSTEM_ID);

        self::assertSame(
            [null, 1_200.0, 50.0, 1_250.0, 99, StructureStock::Insufficient, SourcingSide::Unknown, null],
            $this->deliveredFieldsOf($sourcing),
        );
        self::assertFalse($sourcing->bestCost()->isKnown());
        self::assertFalse($sourcing->structureFallback);
    }

    #[Group('issue-1287')]
    public function testExample6WithoutJitaPriceAndEnoughStructureStockTheStructureIsRecommended(): void
    {
        $sourcing = $this->compareForMarketChoice(jitaPrices: [], structurePrices: [self::PYERITE => 12.0], sellVolumes: [self::PYERITE => 100], structureSystemId: self::HUB_SYSTEM_ID);

        self::assertSame(
            [null, 1_200.0, 50.0, 1_250.0, 100, StructureStock::Enough, SourcingSide::Structure, null],
            $this->deliveredFieldsOf($sourcing),
        );
        self::assertSame(1_250.0, $sourcing->bestCost()->amount()->amount);
    }

    #[Group('issue-1287')]
    public function testExample7UnknownStructureSystemMakesTheStructureDeliveredCostUnknownWithTheMissingJumpRouteAndJitaIsRecommended(): void
    {
        $sourcing = $this->compareForMarketChoice(jitaPrices: [self::PYERITE => 10.0], structurePrices: [self::PYERITE => 12.0], sellVolumes: [self::PYERITE => 100], structureSystemId: null);

        self::assertSame(
            [1_850.0, 1_200.0, null, null, 100, StructureStock::Enough, SourcingSide::Jita, null],
            $this->deliveredFieldsOf($sourcing),
        );
        self::assertEquals([MissingData::jumpRoute()], $sourcing->structureBridgeCost->missingData);
        self::assertEquals([MissingData::jumpRoute()], $sourcing->structureDeliveredCost()->missingData);
    }

    #[Group('issue-1287')]
    public function testExample8WithoutRecentSnapshotTheStructureStockIsUnknownAndJitaIsRecommended(): void
    {
        $sourcing = $this->compareForMarketChoice(jitaPrices: [self::PYERITE => 10.0], structurePrices: [self::PYERITE => 12.0], sellVolumes: [], structureSystemId: self::HUB_SYSTEM_ID);

        self::assertSame(
            [1_850.0, 1_200.0, 50.0, 1_250.0, null, StructureStock::Unknown, SourcingSide::Jita, 600.0],
            $this->deliveredFieldsOf($sourcing),
        );
    }

    #[Group('issue-1287')]
    public function testExample9StoredStructureChoiceIsKeptEvenWhenTheStockIsInsufficientWhileTheRecommendationStaysJita(): void
    {
        $sourcing = $this->compareForMarketChoice(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::PYERITE => 12.0],
            sellVolumes: [self::PYERITE => 99],
            structureSystemId: self::HUB_SYSTEM_ID,
            chosenMarket: SourcingSide::Structure,
        );

        self::assertSame(
            [SourcingSide::Jita, SourcingSide::Structure, SourcingSide::Structure, StructureStock::Insufficient, 1_250.0],
            [$sourcing->side, $sourcing->chosenMarket, $sourcing->market(), $sourcing->structureStock(), $sourcing->selectedCost()->amount()->amount],
        );
    }

    #[Group('issue-1287')]
    public function testExample10StoredJitaChoiceIsKeptWhileTheStructureIsCheaperAndRecommended(): void
    {
        $sourcing = $this->compareForMarketChoice(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::PYERITE => 12.0],
            sellVolumes: [self::PYERITE => 100],
            structureSystemId: self::HUB_SYSTEM_ID,
            chosenMarket: SourcingSide::Jita,
        );

        self::assertSame(
            [SourcingSide::Structure, SourcingSide::Jita, SourcingSide::Jita, 1_850.0],
            [$sourcing->side, $sourcing->chosenMarket, $sourcing->market(), $sourcing->selectedCost()->amount()->amount],
        );
    }

    #[Group('issue-1287')]
    public function testWithoutStoredChoiceTheEffectiveMarketIsTheRecommendedSideAndTheSelectedCostIsItsDeliveredTotal(): void
    {
        $sourcing = $this->compareForMarketChoice(jitaPrices: [self::PYERITE => 10.0], structurePrices: [self::PYERITE => 12.0], sellVolumes: [self::PYERITE => 100], structureSystemId: self::HUB_SYSTEM_ID);

        self::assertSame(
            [null, SourcingSide::Structure, 1_250.0],
            [$sourcing->chosenMarket, $sourcing->market(), $sourcing->selectedCost()->amount()->amount],
        );
    }

    #[Group('issue-1287')]
    public function testStoredStructureChoiceWithAnUnknownStructureDeliveredCostGivesAnUnknownSelectedCostAndNeverZero(): void
    {
        $sourcing = $this->compareForMarketChoice(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::PYERITE => 12.0],
            sellVolumes: [self::PYERITE => 100],
            structureSystemId: null,
            chosenMarket: SourcingSide::Structure,
        );

        self::assertSame(SourcingSide::Structure, $sourcing->market());
        self::assertFalse($sourcing->selectedCost()->isKnown());
        self::assertEquals([MissingData::jumpRoute()], $sourcing->selectedCost()->missingData);
    }

    #[Group('issue-1287')]
    public function testSideUnknownWithoutStoredChoiceSelectsTheSumOfBothDeliveredCostsAndStaysUnknown(): void
    {
        $sourcing = $this->compareForMarketChoice(jitaPrices: [], structurePrices: [self::PYERITE => 12.0], sellVolumes: [self::PYERITE => 99], structureSystemId: self::HUB_SYSTEM_ID);

        self::assertSame(SourcingSide::Unknown, $sourcing->market());
        self::assertFalse($sourcing->selectedCost()->isKnown());
        self::assertEquals([MissingData::marketPrice(self::PYERITE)], $sourcing->selectedCost()->missingData);
    }

    #[Group('issue-1287')]
    public function testStructureDeliveredCostEqualToJitaWithImportGoesToJitaAndSavesNothing(): void
    {
        $sourcing = $this->compareForMarketChoice(jitaPrices: [self::PYERITE => 10.0], structurePrices: [self::PYERITE => 18.5], sellVolumes: [self::PYERITE => 100], structureSystemId: self::MANUFACTURING_SYSTEM_ID);

        self::assertSame(
            [1_850.0, 1_850.0, 0.0, 1_850.0, 100, StructureStock::Enough, SourcingSide::Jita, 0.0],
            $this->deliveredFieldsOf($sourcing),
        );
    }

    #[Group('issue-1287')]
    public function testStructureWithNothingInSaleHasAKnownZeroStockWhichIsInsufficient(): void
    {
        $sourcing = $this->compareForMarketChoice(jitaPrices: [self::PYERITE => 10.0], structurePrices: [self::PYERITE => 12.0], sellVolumes: [self::PYERITE => 0], structureSystemId: self::HUB_SYSTEM_ID);

        self::assertSame([0, StructureStock::Insufficient, SourcingSide::Jita], [$sourcing->structureAvailableQuantity, $sourcing->structureStock(), $sourcing->side]);
    }

    #[Group('issue-1287')]
    public function testJitaWithPartialOrderBookCoverageIsStillEligibleAndKeepsItsPartialPriceWarning(): void
    {
        $sourcings = $this->comparatorWith(
            new InMemoryMarketPrices([self::PYERITE => 10.0], [self::PYERITE => 0.6]),
            new InMemoryStructureMarketPrices([self::STRUCTURE_ID => [self::PYERITE => 30.0]], null, [self::STRUCTURE_ID => [self::PYERITE => 100]]),
            [self::PYERITE => 0.01],
            [self::MANUFACTURING_SYSTEM_ID => self::MANUFACTURING_JUMPS],
        )->compare([$this->material(self::PYERITE, 100)], self::STRUCTURE_ID, $this->deliveryFavoriteSystems(), self::HUB_SYSTEM_ID);

        self::assertSame(SourcingSide::Jita, $sourcings[0]->side);
        self::assertCount(1, $sourcings[0]->jitaWithImportCost()->partialPrices);
    }

    #[Group('issue-1287')]
    public function testStructureInAnotherSystemThanTheWorkshopWithoutCourierHubHasAnUnknownBridgeBecauseOfTheCourierHub(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::PYERITE => 12.0],
            volumes: [self::PYERITE => 0.01],
            sellVolumes: [self::PYERITE => 100],
        )->compare([$this->material(self::PYERITE, 100)], self::STRUCTURE_ID, new FavoriteSystems(self::MANUFACTURING_SYSTEM_ID, self::REACTION_SYSTEM_ID), self::HUB_SYSTEM_ID);

        self::assertEquals([MissingData::courierHub()], $sourcings[0]->structureBridgeCost->missingData);
        self::assertEquals([MissingData::courierHub()], $sourcings[0]->importCost->missingData);
        self::assertFalse($sourcings[0]->structureDeliveredCost()->isKnown());
        self::assertSame(SourcingSide::Unknown, $sourcings[0]->side);
    }

    #[Group('issue-1287')]
    public function testWithoutStructureOfTheReaderTheStructureTransportIsUnknownEvenWhenTheStructureSystemIsGiven(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::PYERITE => 10.0],
            structurePrices: [self::PYERITE => 12.0],
            volumes: [self::PYERITE => 0.01],
            sellVolumes: [self::PYERITE => 100],
        )->compare([$this->material(self::PYERITE, 100)], null, $this->deliveryFavoriteSystems(), self::HUB_SYSTEM_ID);

        self::assertFalse($sourcings[0]->structureBridgeCost->isKnown());
        self::assertSame([null, StructureStock::Unknown, SourcingSide::Jita], [$sourcings[0]->structureAvailableQuantity, $sourcings[0]->structureStock(), $sourcings[0]->side]);
    }

    #[Group('issue-1287')]
    public function testEveryTypeIsComparedWithItsOwnPriceStockAndVolume(): void
    {
        $structurePrices = new InMemoryStructureMarketPrices(
            [self::STRUCTURE_ID => [self::TRITANIUM => 4.0, self::PYERITE => 12.0, self::MEXALLON => 90.0]],
            null,
            [self::STRUCTURE_ID => [self::TRITANIUM => 50_000, self::PYERITE => 99, self::MEXALLON => 400]],
        );

        $sourcings = $this->comparatorWith(
            new InMemoryMarketPrices([self::TRITANIUM => 5.0, self::PYERITE => 10.0, self::MEXALLON => 100.0]),
            $structurePrices,
            [self::TRITANIUM => 0.01, self::PYERITE => 0.01, self::MEXALLON => 1.0],
            [self::MANUFACTURING_SYSTEM_ID => self::MANUFACTURING_JUMPS],
        )->compare(
            [$this->material(self::TRITANIUM, 10_000), $this->material(self::PYERITE, 100), $this->material(self::MEXALLON, 300)],
            self::STRUCTURE_ID,
            $this->deliveryFavoriteSystems(),
            self::MANUFACTURING_SYSTEM_ID,
        );

        self::assertSame(
            [
                [self::TRITANIUM, 50_000, StructureStock::Enough, SourcingSide::Structure],
                [self::PYERITE, 99, StructureStock::Insufficient, SourcingSide::Jita],
                [self::MEXALLON, 400, StructureStock::Enough, SourcingSide::Structure],
            ],
            array_map(
                static fn (MaterialSourcing $sourcing): array => [$sourcing->typeId, $sourcing->structureAvailableQuantity, $sourcing->structureStock(), $sourcing->side],
                $sourcings,
            ),
        );
        self::assertSame([[self::STRUCTURE_ID, [self::TRITANIUM, self::PYERITE, self::MEXALLON]]], $structurePrices->volumeRequests);
    }

    #[Group('issue-1287')]
    public function testLineIdAndStoredChoiceOfTheMaterialAreCarriedToItsSourcing(): void
    {
        $sourcings = $this->comparator(
            jitaPrices: [self::PYERITE => 10.0, self::TRITANIUM => 5.0],
            structurePrices: [],
            volumes: [],
        )->compare(
            [
                new MaterialToSource(self::PYERITE, new Quantity(100), ActivityKind::Manufacturing, lineId: self::PYERITE_LINE_ID, chosenMarket: SourcingSide::Jita),
                new MaterialToSource(self::TRITANIUM, new Quantity(10), ActivityKind::Manufacturing, lineId: self::TRITANIUM_LINE_ID),
            ],
            self::STRUCTURE_ID,
            $this->favoriteSystems(),
            self::HUB_SYSTEM_ID,
        );

        self::assertSame(
            [[self::PYERITE_LINE_ID, SourcingSide::Jita], [self::TRITANIUM_LINE_ID, null]],
            array_map(static fn (MaterialSourcing $sourcing): array => [$sourcing->lineId, $sourcing->chosenMarket], $sourcings),
        );
    }

    /**
     * @param array<int, float> $jitaPrices
     * @param array<int, float> $structurePrices
     * @param array<int, float> $volumes
     * @param array<int, int>   $jumps
     * @param array<int, int>   $sellVolumes
     */
    private function comparator(array $jitaPrices, array $structurePrices, array $volumes, ?array $jumps = null, array $sellVolumes = []): MaterialSourcingComparator
    {
        return $this->comparatorWith(
            new InMemoryMarketPrices($jitaPrices),
            new InMemoryStructureMarketPrices([self::STRUCTURE_ID => $structurePrices], null, [self::STRUCTURE_ID => $sellVolumes]),
            $volumes,
            $jumps ?? [self::MANUFACTURING_SYSTEM_ID => self::MANUFACTURING_JUMPS],
        );
    }

    /**
     * @param array<int, float> $volumes
     * @param array<int, int>   $jumps
     */
    private function comparatorWith(\App\Industry\Application\MarketPrices $marketPrices, InMemoryStructureMarketPrices $structurePrices, array $volumes, array $jumps): MaterialSourcingComparator
    {
        return new MaterialSourcingComparator(
            $marketPrices,
            $structurePrices,
            new InMemoryItemVolumes($volumes),
            new InMemoryJumpDistances([EveConstants::JITA_SOLAR_SYSTEM_ID => $jumps]),
        );
    }

    /**
     * @param array<int, float> $jitaPrices
     * @param array<int, float> $structurePrices
     * @param array<int, int>   $sellVolumes
     */
    private function compareForMarketChoice(array $jitaPrices, array $structurePrices, array $sellVolumes, ?int $structureSystemId, ?SourcingSide $chosenMarket = null): MaterialSourcing
    {
        $sourcings = $this->comparatorWith(
            new InMemoryMarketPrices($jitaPrices),
            new InMemoryStructureMarketPrices([self::STRUCTURE_ID => $structurePrices], null, [self::STRUCTURE_ID => $sellVolumes]),
            [self::PYERITE => 0.01],
            [self::MANUFACTURING_SYSTEM_ID => self::MANUFACTURING_JUMPS],
        )->compare(
            [new MaterialToSource(self::PYERITE, new Quantity(100), ActivityKind::Manufacturing, chosenMarket: $chosenMarket)],
            self::STRUCTURE_ID,
            $this->deliveryFavoriteSystems(),
            $structureSystemId,
        );

        self::assertCount(1, $sourcings);

        return $sourcings[0];
    }

    /**
     * @return list<mixed>
     */
    private function deliveredFieldsOf(MaterialSourcing $sourcing): array
    {
        $amountOf = static fn (Cost $cost): ?float => $cost->isKnown() ? $cost->amount()->amount : null;

        return [
            $amountOf($sourcing->jitaWithImportCost()),
            $amountOf($sourcing->structureCost),
            $amountOf($sourcing->structureBridgeCost),
            $amountOf($sourcing->structureDeliveredCost()),
            $sourcing->structureAvailableQuantity,
            $sourcing->structureStock(),
            $sourcing->side,
            $sourcing->saving?->amount,
        ];
    }

    private function deliveryFavoriteSystems(): FavoriteSystems
    {
        return new FavoriteSystems(self::MANUFACTURING_SYSTEM_ID, self::REACTION_SYSTEM_ID, new CourierHub(self::HUB_SYSTEM_ID, 800.0, 50.0));
    }

    private function favoriteSystems(): FavoriteSystems
    {
        return new FavoriteSystems(self::MANUFACTURING_SYSTEM_ID, self::REACTION_SYSTEM_ID, $this->hub());
    }

    private function hub(): CourierHub
    {
        return new CourierHub(self::HUB_SYSTEM_ID, 1_200.0, 50.0);
    }

    private function material(int $typeId, int $quantity): MaterialToSource
    {
        return new MaterialToSource($typeId, new Quantity($quantity), ActivityKind::Manufacturing);
    }

    /**
     * @return list<mixed>
     */
    private function rowOf(MaterialSourcing $sourcing): array
    {
        return [
            $sourcing->typeId,
            $sourcing->side,
            $sourcing->jitaCost->isKnown() ? $sourcing->jitaCost->amount()->amount : null,
            $sourcing->importCost->isKnown() ? $sourcing->importCost->amount()->amount : null,
            $sourcing->jitaWithImportCost()->isKnown() ? $sourcing->jitaWithImportCost()->amount()->amount : null,
            $sourcing->structureCost->isKnown() ? $sourcing->structureCost->amount()->amount : null,
            $sourcing->saving?->amount,
            $sourcing->structureFallback,
            $sourcing->jumps,
            $sourcing->volumePerUnit,
        ];
    }
}
