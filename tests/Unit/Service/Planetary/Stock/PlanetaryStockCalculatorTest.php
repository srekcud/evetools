<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary\Stock;

use App\Service\Industry\Stock\PersonalStockContainerContents;
use App\Service\Industry\Stock\PersonalStockContainerReading;
use App\Service\Planetary\Stock\PlanetaryBuyOrder;
use App\Service\Planetary\Stock\PlanetaryStock;
use App\Service\Planetary\Stock\PlanetaryStockCalculator;
use App\Service\Planetary\Stock\PlanetaryStockReadings;
use App\Service\Planetary\Stock\PlanetaryStockRow;
use App\Service\Planetary\Stock\PlanetaryWeeklyNeed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryStockCalculator::class)]
#[Group('issue-1395')]
final class PlanetaryStockCalculatorTest extends TestCase
{
    private const int HOME_STATION = 60_003_760;
    private const int HUB_STATION = 60_008_494;
    private const int OTHER_STATION = 60_011_866;

    private const int ROBOTICS = 9848;
    private const int PLASMOIDS = 2311;
    private const int COOLANT = 9832;
    private const int WATER = 3645;
    private const int BACTERIA = 2393;
    private const int MECHANICAL_PARTS = 3689;
    private const int UNNAMED_FIRST = 999;
    private const int UNNAMED_SECOND = 1111;
    private const int WATER_DUPLICATE = 3000;

    private const string NOT_FOUND = 'container_not_found';
    private const string HIDDEN_DIVISION = 'container_in_hidden_division';

    private int $containerSequence = 0;

    public function testRowCoversTheHomeHubAndCorporationQuantitiesAgainstTheWeeklyConsumption(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::ROBOTICS, 'Robotics', 1000.0, 0.15)],
            [self::HOME_STATION => [self::ROBOTICS => 1500], self::HUB_STATION => [self::ROBOTICS => 500]],
        ));

        self::assertCount(1, $stock->rows);
        $row = $this->rowOf($stock, self::ROBOTICS);
        self::assertSame(['Robotics', 0.15], [$row->typeName, $row->volumePerUnit]);
        self::assertSame(
            ['weeklyConsumption' => 1000.0, 'home' => 1500, 'hub' => 500, 'corp' => 0, 'openOrders' => 0, 'coverageWeeks' => 2.0, 'coverageWithOrdersWeeks' => 2.0, 'toBuy' => 1000],
            $this->figures($row),
        );
        self::assertNull($stock->corpUnknownReason);
        self::assertTrue($stock->complete);
    }

    public function testOpenOrdersAtTheHubRaiseTheCoverageWithOrdersAndLowerWhatToBuy(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::PLASMOIDS, 'Plasmoids', 1000.0, 0.01)],
            [self::HOME_STATION => [self::PLASMOIDS => 1500], self::HUB_STATION => [self::PLASMOIDS => 500]],
            orders: [new PlanetaryBuyOrder(self::PLASMOIDS, self::HUB_STATION, 400)],
        ));

        self::assertSame(
            ['weeklyConsumption' => 1000.0, 'home' => 1500, 'hub' => 500, 'corp' => 0, 'openOrders' => 400, 'coverageWeeks' => 2.0, 'coverageWithOrdersWeeks' => 2.4, 'toBuy' => 600],
            $this->figures($this->rowOf($stock, self::PLASMOIDS)),
        );
    }

    public function testWhatToBuyIsNeverNegativeAndStopsExactlyAtTheTarget(): void
    {
        $stock = $this->calculate($this->readings(
            [
                new PlanetaryWeeklyNeed(self::COOLANT, 'Coolant', 1000.0, 1.5),
                new PlanetaryWeeklyNeed(self::WATER, 'Water', 800.0, 0.38),
                new PlanetaryWeeklyNeed(self::BACTERIA, 'Bacteria', 1000.0, 0.2),
            ],
            [self::HOME_STATION => [self::COOLANT => 3000, self::WATER => 3600, self::BACTERIA => 2999]],
        ));

        self::assertSame(
            ['weeklyConsumption' => 1000.0, 'home' => 3000, 'hub' => 0, 'corp' => 0, 'openOrders' => 0, 'coverageWeeks' => 3.0, 'coverageWithOrdersWeeks' => 3.0, 'toBuy' => 0],
            $this->figures($this->rowOf($stock, self::COOLANT)),
        );
        self::assertSame(
            ['weeklyConsumption' => 800.0, 'home' => 3600, 'hub' => 0, 'corp' => 0, 'openOrders' => 0, 'coverageWeeks' => 4.5, 'coverageWithOrdersWeeks' => 4.5, 'toBuy' => 0],
            $this->figures($this->rowOf($stock, self::WATER)),
        );
        self::assertSame(
            ['weeklyConsumption' => 1000.0, 'home' => 2999, 'hub' => 0, 'corp' => 0, 'openOrders' => 0, 'coverageWeeks' => 2.999, 'coverageWithOrdersWeeks' => 2.999, 'toBuy' => 1],
            $this->figures($this->rowOf($stock, self::BACTERIA)),
        );
    }

    public function testFractionalDeficitIsRoundedUpToAWholeQuantity(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::ROBOTICS, 'Robotics', 333.4, 0.15)],
            [self::HOME_STATION => [self::PLASMOIDS => 5]],
        ));

        self::assertSame(
            ['weeklyConsumption' => 333.4, 'home' => 0, 'hub' => 0, 'corp' => 0, 'openOrders' => 0, 'coverageWeeks' => 0.0, 'coverageWithOrdersWeeks' => 0.0, 'toBuy' => 1001],
            $this->figures($this->rowOf($stock, self::ROBOTICS)),
        );
    }

    public function testFloatingPointNoiseBelowSixDecimalsDoesNotAddAUnitButSixDecimalsDo(): void
    {
        $stock = $this->calculate($this->readings(
            [
                new PlanetaryWeeklyNeed(self::COOLANT, 'Coolant', 1000.000000001, 1.5),
                new PlanetaryWeeklyNeed(self::WATER, 'Water', 1000.000001, 0.38),
            ],
            [],
            coverageWeeks: 1,
        ));

        self::assertSame(1000, $this->rowOf($stock, self::COOLANT)->toBuy);
        self::assertSame(1001, $this->rowOf($stock, self::WATER)->toBuy);
    }

    public function testHubIsZeroWhenItIsTheHomeLocationAndItsOrdersAreStillCounted(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::MECHANICAL_PARTS, 'Mechanical Parts', 600.0, 0.5)],
            [self::HOME_STATION => [self::MECHANICAL_PARTS => 1200]],
            hub: self::HOME_STATION,
            orders: [new PlanetaryBuyOrder(self::MECHANICAL_PARTS, self::HOME_STATION, 300)],
        ));

        self::assertSame(
            ['weeklyConsumption' => 600.0, 'home' => 1200, 'hub' => 0, 'corp' => 0, 'openOrders' => 300, 'coverageWeeks' => 2.0, 'coverageWithOrdersWeeks' => 2.5, 'toBuy' => 300],
            $this->figures($this->rowOf($stock, self::MECHANICAL_PARTS)),
        );
    }

    public function testOnlyTheOrdersAtTheHubOfTheConsumedTypeAreSummed(): void
    {
        $stock = $this->calculate($this->readings(
            [
                new PlanetaryWeeklyNeed(self::PLASMOIDS, 'Plasmoids', 1000.0, 0.01),
                new PlanetaryWeeklyNeed(self::ROBOTICS, 'Robotics', 200.0, 0.15),
            ],
            [],
            orders: [
                new PlanetaryBuyOrder(self::PLASMOIDS, self::HUB_STATION, 150),
                new PlanetaryBuyOrder(self::PLASMOIDS, self::HUB_STATION, 250),
                new PlanetaryBuyOrder(self::PLASMOIDS, self::HOME_STATION, 250),
                new PlanetaryBuyOrder(self::PLASMOIDS, self::OTHER_STATION, 90),
                new PlanetaryBuyOrder(self::ROBOTICS, self::HUB_STATION, 75),
                new PlanetaryBuyOrder(self::BACTERIA, self::HUB_STATION, 999),
            ],
        ));

        self::assertCount(2, $stock->rows);
        self::assertSame(400, $this->rowOf($stock, self::PLASMOIDS)->openOrders);
        self::assertSame(75, $this->rowOf($stock, self::ROBOTICS)->openOrders);
    }

    public function testCorporationContainerAtTheHomeLocationIsCounted(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::PLASMOIDS, 'Plasmoids', 1000.0, 0.01)],
            [self::HOME_STATION => [self::PLASMOIDS => 100]],
            containers: [$this->knownContainer(self::HOME_STATION, [self::PLASMOIDS => 700])],
        ));

        self::assertSame(
            ['weeklyConsumption' => 1000.0, 'home' => 100, 'hub' => 0, 'corp' => 700, 'openOrders' => 0, 'coverageWeeks' => 0.8, 'coverageWithOrdersWeeks' => 0.8, 'toBuy' => 2200],
            $this->figures($this->rowOf($stock, self::PLASMOIDS)),
        );
        self::assertNull($stock->corpUnknownReason);
    }

    public function testCorporationContainersAreSummedPerTypeAndContainersOfAnotherStationAreIgnored(): void
    {
        $stock = $this->calculate($this->readings(
            [
                new PlanetaryWeeklyNeed(self::PLASMOIDS, 'Plasmoids', 1000.0, 0.01),
                new PlanetaryWeeklyNeed(self::ROBOTICS, 'Robotics', 500.0, 0.15),
                new PlanetaryWeeklyNeed(self::WATER, 'Water', 100.0, 0.38),
            ],
            [],
            containers: [
                $this->knownContainer(self::HOME_STATION, [self::PLASMOIDS => 700, self::WATER => 40]),
                $this->knownContainer(self::HOME_STATION, [self::PLASMOIDS => 300]),
                $this->knownContainer(self::OTHER_STATION, [self::PLASMOIDS => 5000, self::ROBOTICS => 800]),
            ],
        ));

        self::assertSame(1000, $this->rowOf($stock, self::PLASMOIDS)->corp);
        self::assertSame(0, $this->rowOf($stock, self::ROBOTICS)->corp);
        self::assertSame(40, $this->rowOf($stock, self::WATER)->corp);
        self::assertSame(2000, $this->rowOf($stock, self::PLASMOIDS)->toBuy);
    }

    public function testContainersAllAtAnotherStationLeaveTheCorporationColumnAtZero(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::ROBOTICS, 'Robotics', 500.0, 0.15)],
            [],
            containers: [$this->knownContainer(self::OTHER_STATION, [self::ROBOTICS => 800])],
        ));

        self::assertSame([0, 1500], [$this->rowOf($stock, self::ROBOTICS)->corp, $this->rowOf($stock, self::ROBOTICS)->toBuy]);
        self::assertNull($stock->corpUnknownReason);
        self::assertTrue($stock->complete);
    }

    public function testUnknownContainerReadingHidesTheCorporationColumnAndWhatToBuyEverywhere(): void
    {
        $stock = $this->calculate($this->readings(
            [
                new PlanetaryWeeklyNeed(self::PLASMOIDS, 'Plasmoids', 1000.0, 0.01),
                new PlanetaryWeeklyNeed(self::ROBOTICS, 'Robotics', 500.0, 0.15),
            ],
            [self::HOME_STATION => [self::PLASMOIDS => 1500], self::HUB_STATION => [self::PLASMOIDS => 500]],
            containers: [
                $this->knownContainer(self::HOME_STATION, [self::PLASMOIDS => 700]),
                $this->unknownContainer(self::OTHER_STATION, self::NOT_FOUND),
                $this->unknownContainer(null, self::HIDDEN_DIVISION),
            ],
            orders: [new PlanetaryBuyOrder(self::PLASMOIDS, self::HUB_STATION, 400)],
        ));

        self::assertSame(self::NOT_FOUND, $stock->corpUnknownReason);
        self::assertFalse($stock->complete);
        self::assertSame(
            ['weeklyConsumption' => 1000.0, 'home' => 1500, 'hub' => 500, 'corp' => null, 'openOrders' => 400, 'coverageWeeks' => null, 'coverageWithOrdersWeeks' => null, 'toBuy' => null],
            $this->figures($this->rowOf($stock, self::PLASMOIDS)),
        );
        self::assertSame(
            ['weeklyConsumption' => 500.0, 'home' => 0, 'hub' => 0, 'corp' => null, 'openOrders' => 0, 'coverageWeeks' => null, 'coverageWithOrdersWeeks' => null, 'toBuy' => null],
            $this->figures($this->rowOf($stock, self::ROBOTICS)),
        );
    }

    public function testWithoutAHomeLocationTheHomeAndCorporationColumnsAreUnknown(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::PLASMOIDS, 'Plasmoids', 1000.0, 0.01)],
            [self::HOME_STATION => [self::PLASMOIDS => 1500], self::HUB_STATION => [self::PLASMOIDS => 500]],
            home: null,
            containers: [$this->knownContainer(self::HOME_STATION, [self::PLASMOIDS => 700])],
            orders: [new PlanetaryBuyOrder(self::PLASMOIDS, self::HUB_STATION, 400)],
        ));

        self::assertSame(
            ['weeklyConsumption' => 1000.0, 'home' => null, 'hub' => 500, 'corp' => null, 'openOrders' => 400, 'coverageWeeks' => null, 'coverageWithOrdersWeeks' => null, 'toBuy' => null],
            $this->figures($this->rowOf($stock, self::PLASMOIDS)),
        );
        self::assertNull($stock->corpUnknownReason);
        self::assertFalse($stock->complete);
    }

    public function testUnknownExtractionLeavesTheConsumptionCoverageAndWhatToBuyUnknown(): void
    {
        $stock = $this->calculate($this->readings(
            [
                new PlanetaryWeeklyNeed(self::COOLANT, 'Coolant', null, 1.5),
                new PlanetaryWeeklyNeed(self::WATER, 'Water', 800.0, 0.38),
            ],
            [self::HOME_STATION => [self::COOLANT => 1500, self::WATER => 1000], self::HUB_STATION => [self::COOLANT => 200]],
            orders: [new PlanetaryBuyOrder(self::COOLANT, self::HUB_STATION, 60)],
        ));

        self::assertSame(
            ['weeklyConsumption' => null, 'home' => 1500, 'hub' => 200, 'corp' => 0, 'openOrders' => 60, 'coverageWeeks' => null, 'coverageWithOrdersWeeks' => null, 'toBuy' => null],
            $this->figures($this->rowOf($stock, self::COOLANT)),
        );
        self::assertSame(1400, $this->rowOf($stock, self::WATER)->toBuy);
        self::assertFalse($stock->complete);
    }

    public function testNoConsumptionLeavesTheCoverageUnknownButNothingToBuy(): void
    {
        $stock = $this->calculate($this->readings(
            [
                new PlanetaryWeeklyNeed(self::BACTERIA, 'Bacteria', 0.0, 0.2),
                new PlanetaryWeeklyNeed(self::ROBOTICS, 'Robotics', 0.5, 0.15),
            ],
            [self::HOME_STATION => [self::BACTERIA => 800]],
        ));

        self::assertSame(
            ['weeklyConsumption' => 0.0, 'home' => 800, 'hub' => 0, 'corp' => 0, 'openOrders' => 0, 'coverageWeeks' => null, 'coverageWithOrdersWeeks' => null, 'toBuy' => 0],
            $this->figures($this->rowOf($stock, self::BACTERIA)),
        );
        self::assertSame(
            ['weeklyConsumption' => 0.5, 'home' => 0, 'hub' => 0, 'corp' => 0, 'openOrders' => 0, 'coverageWeeks' => 0.0, 'coverageWithOrdersWeeks' => 0.0, 'toBuy' => 2],
            $this->figures($this->rowOf($stock, self::ROBOTICS)),
        );
        self::assertTrue($stock->complete);
    }

    public function testTypeAbsentFromEveryLocationCountsZeroNeverUnknown(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::WATER, 'Water', 250.0, 0.38)],
            [self::HOME_STATION => [self::ROBOTICS => 5]],
        ));

        self::assertSame(
            ['weeklyConsumption' => 250.0, 'home' => 0, 'hub' => 0, 'corp' => 0, 'openOrders' => 0, 'coverageWeeks' => 0.0, 'coverageWithOrdersWeeks' => 0.0, 'toBuy' => 750],
            $this->figures($this->rowOf($stock, self::WATER)),
        );
    }

    public function testIncompleteInputNeedsMakeTheStockIncompleteWhileTheRowsAreStillComputed(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::ROBOTICS, 'Robotics', 1000.0, 0.15)],
            [self::HOME_STATION => [self::ROBOTICS => 2000]],
            needsComplete: false,
        ));

        self::assertFalse($stock->complete);
        self::assertSame([2.0, 1000], [$this->rowOf($stock, self::ROBOTICS)->coverageWeeks, $this->rowOf($stock, self::ROBOTICS)->toBuy]);
        self::assertNull($stock->corpUnknownReason);
    }

    public function testNothingBuiltGivesNoRowAndTheCompletenessOfTheSettings(): void
    {
        $withHome = $this->calculate($this->readings([], [self::HOME_STATION => [self::ROBOTICS => 2000]]));
        $withoutHome = $this->calculate($this->readings([], [], home: null));

        self::assertSame([], $withHome->rows);
        self::assertTrue($withHome->complete);
        self::assertSame([], $withoutHome->rows);
        self::assertFalse($withoutHome->complete);
    }

    public function testRowsAreSortedByNameWithUnnamedTypesLastThenByTypeId(): void
    {
        $stock = $this->calculate($this->readings(
            [
                new PlanetaryWeeklyNeed(self::UNNAMED_SECOND, null, 10.0, null),
                new PlanetaryWeeklyNeed(self::WATER, 'Water', 10.0, 0.38),
                new PlanetaryWeeklyNeed(self::ROBOTICS, 'Robotics', 10.0, 0.15),
                new PlanetaryWeeklyNeed(self::UNNAMED_FIRST, null, 10.0, null),
                new PlanetaryWeeklyNeed(self::WATER_DUPLICATE, 'Water', 10.0, 0.38),
                new PlanetaryWeeklyNeed(self::PLASMOIDS, 'Plasmoids', 10.0, 0.01),
            ],
            [],
        ));

        self::assertSame(
            [self::PLASMOIDS, self::ROBOTICS, self::WATER_DUPLICATE, self::WATER, self::UNNAMED_FIRST, self::UNNAMED_SECOND],
            array_map(static fn (PlanetaryStockRow $row): int => $row->typeId, $stock->rows),
        );
        self::assertNull($this->rowOf($stock, self::UNNAMED_FIRST)->typeName);
        self::assertNull($this->rowOf($stock, self::UNNAMED_FIRST)->volumePerUnit);
    }

    #[Group('issue-1390')]
    public function testCustomsOfficeQuantityIsHeldStockInTheCoverageAndWhatToBuy(): void
    {
        $stock = $this->calculate($this->readings(
            [
                new PlanetaryWeeklyNeed(self::ROBOTICS, 'Robotics', 1000.0, 0.15),
                new PlanetaryWeeklyNeed(self::COOLANT, 'Coolant', 500.0, 1.5),
            ],
            [self::HOME_STATION => [self::ROBOTICS => 1500, self::COOLANT => 200], self::HUB_STATION => [self::ROBOTICS => 500]],
            customsOffice: [self::ROBOTICS => 400, self::COOLANT => 1300],
        ));

        $robotics = $this->rowOf($stock, self::ROBOTICS);
        self::assertSame(400, $robotics->customsOffice);
        self::assertSame(
            ['weeklyConsumption' => 1000.0, 'home' => 1500, 'hub' => 500, 'corp' => 0, 'openOrders' => 0, 'coverageWeeks' => 2.4, 'coverageWithOrdersWeeks' => 2.4, 'toBuy' => 600],
            $this->figures($robotics),
        );
        $coolant = $this->rowOf($stock, self::COOLANT);
        self::assertSame(1300, $coolant->customsOffice);
        self::assertSame(
            ['weeklyConsumption' => 500.0, 'home' => 200, 'hub' => 0, 'corp' => 0, 'openOrders' => 0, 'coverageWeeks' => 3.0, 'coverageWithOrdersWeeks' => 3.0, 'toBuy' => 0],
            $this->figures($coolant),
        );
        self::assertTrue($stock->complete);
    }

    #[Group('issue-1390')]
    public function testCustomsOfficeQuantityAddsToTheOpenOrdersInTheCoverageWithOrders(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::PLASMOIDS, 'Plasmoids', 1000.0, 0.01)],
            [self::HOME_STATION => [self::PLASMOIDS => 1500], self::HUB_STATION => [self::PLASMOIDS => 500]],
            orders: [new PlanetaryBuyOrder(self::PLASMOIDS, self::HUB_STATION, 200)],
            customsOffice: [self::PLASMOIDS => 400],
        ));

        self::assertSame(
            ['weeklyConsumption' => 1000.0, 'home' => 1500, 'hub' => 500, 'corp' => 0, 'openOrders' => 200, 'coverageWeeks' => 2.4, 'coverageWithOrdersWeeks' => 2.6, 'toBuy' => 400],
            $this->figures($this->rowOf($stock, self::PLASMOIDS)),
        );
    }

    #[Group('issue-1390')]
    public function testCustomsOfficeOfAnotherTypeNeverLeaksIntoARowAndAnAbsentOneIsZero(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::ROBOTICS, 'Robotics', 1000.0, 0.15)],
            [self::HOME_STATION => [self::ROBOTICS => 1500], self::HUB_STATION => [self::ROBOTICS => 500]],
            customsOffice: [self::WATER => 999],
        ));

        $row = $this->rowOf($stock, self::ROBOTICS);
        self::assertSame(0, $row->customsOffice);
        self::assertSame(
            ['weeklyConsumption' => 1000.0, 'home' => 1500, 'hub' => 500, 'corp' => 0, 'openOrders' => 0, 'coverageWeeks' => 2.0, 'coverageWithOrdersWeeks' => 2.0, 'toBuy' => 1000],
            $this->figures($row),
        );
    }

    #[Group('issue-1390')]
    public function testCustomsOfficeStaysShownWhileTheCorporationColumnIsUnknownAndTheCoverageStaysUnknown(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::PLASMOIDS, 'Plasmoids', 1000.0, 0.01)],
            [self::HOME_STATION => [self::PLASMOIDS => 1500]],
            containers: [$this->unknownContainer(self::OTHER_STATION, self::NOT_FOUND)],
            customsOffice: [self::PLASMOIDS => 400],
        ));

        $row = $this->rowOf($stock, self::PLASMOIDS);
        self::assertSame(400, $row->customsOffice);
        self::assertSame(
            ['weeklyConsumption' => 1000.0, 'home' => 1500, 'hub' => 0, 'corp' => null, 'openOrders' => 0, 'coverageWeeks' => null, 'coverageWithOrdersWeeks' => null, 'toBuy' => null],
            $this->figures($row),
        );
    }

    #[Group('issue-1390')]
    public function testCustomsOfficeStaysShownWithoutAHomeLocationAndTheCoverageStaysUnknown(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::PLASMOIDS, 'Plasmoids', 1000.0, 0.01)],
            [self::HUB_STATION => [self::PLASMOIDS => 500]],
            home: null,
            customsOffice: [self::PLASMOIDS => 400],
        ));

        $row = $this->rowOf($stock, self::PLASMOIDS);
        self::assertSame(400, $row->customsOffice);
        self::assertNull($row->coverageWeeks);
        self::assertNull($row->toBuy);
    }

    #[Group('issue-1451')]
    public function testElsewhereQuantityIsHeldStockInTheCoverageAndWhatToBuyAndNeverInTheHubColumn(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::ROBOTICS, 'Robotics', 1000.0, 0.15)],
            [self::HOME_STATION => [self::ROBOTICS => 1500], self::HUB_STATION => [self::ROBOTICS => 500]],
            elsewhere: [self::ROBOTICS => 400],
        ));

        $row = $this->rowOf($stock, self::ROBOTICS);
        self::assertSame(400, $row->elsewhere);
        self::assertSame(
            ['weeklyConsumption' => 1000.0, 'home' => 1500, 'hub' => 500, 'corp' => 0, 'openOrders' => 0, 'coverageWeeks' => 2.4, 'coverageWithOrdersWeeks' => 2.4, 'toBuy' => 600],
            $this->figures($row),
        );
        self::assertTrue($stock->complete);
    }

    #[Group('issue-1451')]
    public function testElsewhereQuantityAddsToTheOpenOrdersInTheCoverageWithOrders(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::PLASMOIDS, 'Plasmoids', 1000.0, 0.01)],
            [self::HOME_STATION => [self::PLASMOIDS => 1500], self::HUB_STATION => [self::PLASMOIDS => 500]],
            orders: [new PlanetaryBuyOrder(self::PLASMOIDS, self::HUB_STATION, 400)],
            elsewhere: [self::PLASMOIDS => 400],
        ));

        self::assertSame(
            ['weeklyConsumption' => 1000.0, 'home' => 1500, 'hub' => 500, 'corp' => 0, 'openOrders' => 400, 'coverageWeeks' => 2.4, 'coverageWithOrdersWeeks' => 2.8, 'toBuy' => 200],
            $this->figures($this->rowOf($stock, self::PLASMOIDS)),
        );
    }

    #[Group('issue-1451')]
    public function testElsewhereStockAloneCanCoverTheTargetAndLeaveNothingToBuy(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::COOLANT, 'Coolant', 1000.0, 1.5)],
            [],
            elsewhere: [self::COOLANT => 3000],
        ));

        self::assertSame(
            ['weeklyConsumption' => 1000.0, 'home' => 0, 'hub' => 0, 'corp' => 0, 'openOrders' => 0, 'coverageWeeks' => 3.0, 'coverageWithOrdersWeeks' => 3.0, 'toBuy' => 0],
            $this->figures($this->rowOf($stock, self::COOLANT)),
        );
    }

    #[Group('issue-1451')]
    public function testElsewhereOfAnotherTypeNeverLeaksIntoARowAndAnAbsentOneIsZero(): void
    {
        $stock = $this->calculate($this->readings(
            [
                new PlanetaryWeeklyNeed(self::ROBOTICS, 'Robotics', 1000.0, 0.15),
                new PlanetaryWeeklyNeed(self::WATER, 'Water', 800.0, 0.38),
            ],
            [self::HOME_STATION => [self::ROBOTICS => 1500, self::WATER => 100]],
            elsewhere: [self::ROBOTICS => 400, self::BACTERIA => 9000],
        ));

        self::assertSame(400, $this->rowOf($stock, self::ROBOTICS)->elsewhere);
        self::assertSame(0, $this->rowOf($stock, self::WATER)->elsewhere);
        self::assertSame(100, $this->rowOf($stock, self::WATER)->home);
    }

    #[Group('issue-1451')]
    public function testElsewhereStaysShownWithoutAHomeLocationAndTheCoverageStaysUnknown(): void
    {
        $stock = $this->calculate($this->readings(
            [new PlanetaryWeeklyNeed(self::ROBOTICS, 'Robotics', 1000.0, 0.15)],
            [self::HUB_STATION => [self::ROBOTICS => 500]],
            home: null,
            elsewhere: [self::ROBOTICS => 700],
        ));

        $row = $this->rowOf($stock, self::ROBOTICS);
        self::assertSame(700, $row->elsewhere);
        self::assertNull($row->home);
        self::assertNull($row->coverageWeeks);
        self::assertNull($row->toBuy);
    }

    private function calculate(PlanetaryStockReadings $readings): PlanetaryStock
    {
        return (new PlanetaryStockCalculator())->calculate($readings);
    }

    /**
     * @param list<PlanetaryWeeklyNeed> $weeklyNeeds
     * @param array<int, array<int, int>> $quantities
     * @param list<PersonalStockContainerContents> $containers
     * @param list<PlanetaryBuyOrder> $orders
     * @param array<int, int> $customsOffice
     * @param array<int, int> $elsewhere
     */
    private function readings(
        array $weeklyNeeds,
        array $quantities,
        ?int $home = self::HOME_STATION,
        int $hub = self::HUB_STATION,
        int $coverageWeeks = 3,
        array $containers = [],
        array $orders = [],
        bool $needsComplete = true,
        array $customsOffice = [],
        array $elsewhere = [],
    ): PlanetaryStockReadings {
        return new PlanetaryStockReadings($weeklyNeeds, $home, $hub, $coverageWeeks, $quantities, $containers, $orders, $needsComplete, $customsOffice, $elsewhere);
    }

    /**
     * @param array<int, int> $articles
     */
    private function knownContainer(int $stationId, array $articles): PersonalStockContainerContents
    {
        ++$this->containerSequence;

        return new PersonalStockContainerContents(
            new PersonalStockContainerReading(
                'container-'.$this->containerSequence,
                1_000_000 + $this->containerSequence,
                'Container '.$this->containerSequence,
                'Division 1',
                $stationId,
                'Station '.$stationId,
                PersonalStockContainerReading::STATUS_KNOWN,
                null,
                \count($articles),
            ),
            $articles,
        );
    }

    private function unknownContainer(?int $stationId, string $reason): PersonalStockContainerContents
    {
        ++$this->containerSequence;

        return new PersonalStockContainerContents(
            new PersonalStockContainerReading(
                'container-'.$this->containerSequence,
                1_000_000 + $this->containerSequence,
                'Container '.$this->containerSequence,
                'Division 1',
                $stationId,
                null,
                PersonalStockContainerReading::STATUS_UNKNOWN,
                $reason,
                null,
            ),
            [],
        );
    }

    private function rowOf(PlanetaryStock $stock, int $typeId): PlanetaryStockRow
    {
        foreach ($stock->rows as $row) {
            if ($row->typeId === $typeId) {
                return $row;
            }
        }

        self::fail(\sprintf('No row for type %d among %d rows', $typeId, \count($stock->rows)));
    }

    /**
     * @return array<string, int|float|null>
     */
    private function figures(PlanetaryStockRow $row): array
    {
        return [
            'weeklyConsumption' => $row->weeklyConsumption,
            'home' => $row->home,
            'hub' => $row->hub,
            'corp' => $row->corp,
            'openOrders' => $row->openOrders,
            'coverageWeeks' => $row->coverageWeeks,
            'coverageWithOrdersWeeks' => $row->coverageWithOrdersWeeks,
            'toBuy' => $row->toBuy,
        ];
    }
}
