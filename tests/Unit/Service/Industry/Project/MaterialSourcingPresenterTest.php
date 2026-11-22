<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Industry\Application\MaterialSourcing;
use App\Industry\Application\SourcingSide;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\OrderBookCoverage;
use App\Industry\Domain\PartialPrice;
use App\Industry\Domain\Quantity;
use App\Service\Industry\Project\MaterialSourcingPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaterialSourcingPresenter::class)]
#[CoversClass(MaterialSourcing::class)]
#[Group('issue-466')]
final class MaterialSourcingPresenterTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const string PYERITE_LINE_ID = '0192f3a4-5b6c-7d8e-9f01-23456789abcd';
    private const string TRITANIUM_LINE_ID = '0192f3a4-5b6c-7d8e-9f01-23456789abce';
    private const string MEXALLON_LINE_ID = '0192f3a4-5b6c-7d8e-9f01-23456789abcf';

    public function testRowOfAStructureSideMaterialIsPresentedForTheRemainingQuantity(): void
    {
        $row = MaterialSourcingPresenter::rowOf($this->structureSideSourcing(), 'Pyerite');

        self::assertSame(
            self::sortedByKey([
                'typeId' => self::PYERITE,
                'typeName' => 'Pyerite',
                'quantity' => 1_000,
                'volumePerUnit' => 0.01,
                'jumps' => 9,
                'jitaTotal' => 10_000.0,
                'importTotal' => 13_500.0,
                'jitaWithImportTotal' => 23_500.0,
                'structureTotal' => 20_000.0,
                'bestTotal' => 20_500.0,
                'side' => 'structure',
                'structureFallback' => false,
                'saving' => 3_000.0,
                'missingData' => [],
                'warnings' => [],
                'lineId' => self::PYERITE_LINE_ID,
                'chosenMarket' => null,
                'market' => 'structure',
                'selectedTotal' => 20_500.0,
                'structureBridgeTotal' => 500.0,
                'structureDeliveredTotal' => 20_500.0,
                'structureAvailableQuantity' => 1_200,
                'structureStock' => 'enough',
            ]),
            self::sortedByKey($row),
        );
    }

    #[Group('issue-1287')]
    public function testRowOfAStoredChoiceDifferentFromTheRecommendationPresentsBothAndTheSelectedTotalOfTheChosenMarket(): void
    {
        $row = MaterialSourcingPresenter::rowOf($this->structureSideSourcing(chosenMarket: SourcingSide::Jita), 'Pyerite');

        self::assertArrayHasKey('chosenMarket', $row);
        self::assertSame(
            ['structure', 'jita', 'jita', 23_500.0, 20_500.0],
            [$row['side'], $row['chosenMarket'], $row['market'], $row['selectedTotal'], $row['bestTotal']],
        );
    }

    #[Group('issue-1287')]
    public function testRowOfAnInsufficientStructureStockPresentsTheAvailableQuantityAndTheStockState(): void
    {
        $row = MaterialSourcingPresenter::rowOf($this->structureSideSourcing(structureAvailableQuantity: 999, side: SourcingSide::Jita, chosenMarket: SourcingSide::Structure), 'Pyerite');

        self::assertSame(
            [999, 'insufficient', 'jita', 'structure', 'structure', 20_500.0],
            [$row['structureAvailableQuantity'], $row['structureStock'], $row['side'], $row['chosenMarket'], $row['market'], $row['selectedTotal']],
        );
    }

    public function testRowOfAnUnknownCostPresentsNullAndKeepsTheMissingDataAndThePartialPrice(): void
    {
        $sourcing = new MaterialSourcing(
            self::TRITANIUM,
            new Quantity(250),
            Cost::known(new Isk(1_250.0))->withPartialPrice(new PartialPrice(self::TRITANIUM, new OrderBookCoverage(0.6))),
            Cost::unknown(MissingData::itemVolume(self::TRITANIUM)),
            Cost::unknown(MissingData::structurePrice(self::TRITANIUM)),
            null,
            9,
            SourcingSide::Unknown,
            true,
            null,
            structureBridgeCost: Cost::unknown(MissingData::jumpRoute()),
            structureAvailableQuantity: null,
            chosenMarket: null,
            lineId: self::TRITANIUM_LINE_ID,
        );

        $row = MaterialSourcingPresenter::rowOf($sourcing, null);

        self::assertSame(
            [null, null, 1_250.0, null, null, null, null, 'unknown', true, [['reason' => 'item_volume', 'typeId' => self::TRITANIUM]], 60.0],
            [
                $row['typeName'], $row['volumePerUnit'], $row['jitaTotal'], $row['importTotal'], $row['jitaWithImportTotal'],
                $row['structureTotal'], $row['bestTotal'], $row['side'], $row['structureFallback'], $row['missingData'],
                $row['warnings'][0]['coveragePercent'],
            ],
        );
    }

    #[Group('issue-1287')]
    public function testRowOfAnUnknownStructureDeliveryPresentsNullBridgeDeliveredAndSelectedTotalsAndAnUnknownStock(): void
    {
        $sourcing = new MaterialSourcing(
            self::TRITANIUM,
            new Quantity(250),
            Cost::known(new Isk(1_250.0)),
            Cost::known(new Isk(2_125.0)),
            Cost::known(new Isk(1_000.0)),
            0.01,
            9,
            SourcingSide::Jita,
            false,
            null,
            structureBridgeCost: Cost::unknown(MissingData::jumpRoute()),
            structureAvailableQuantity: null,
            chosenMarket: SourcingSide::Structure,
            lineId: self::TRITANIUM_LINE_ID,
        );

        $row = MaterialSourcingPresenter::rowOf($sourcing, 'Tritanium');

        foreach (['structureBridgeTotal', 'structureDeliveredTotal', 'selectedTotal', 'structureAvailableQuantity'] as $key) {
            self::assertArrayHasKey($key, $row);
            self::assertNull($row[$key], $key);
        }
        self::assertSame([1_000.0, 'unknown', 'structure', 'structure', self::TRITANIUM_LINE_ID], [$row['structureTotal'], $row['structureStock'], $row['chosenMarket'], $row['market'], $row['lineId']]);
    }

    public function testTotalsSumTheJitaWithImportTheStructureAndTheBestSideOfEveryRow(): void
    {
        $totals = MaterialSourcingPresenter::totalsOf([$this->structureSideSourcing(), $this->jitaSideSourcing()]);

        self::assertSame(
            self::sortedByKey(['jitaWithImport' => 40_500.0, 'structure' => 35_000.0, 'structureDelivered' => 35_800.0, 'best' => 37_500.0, 'selected' => 35_800.0]),
            self::sortedByKey($totals),
        );
    }

    #[Group('issue-1287')]
    public function testSelectedTotalFollowsTheStoredChoiceOfEachRowWhileBestFollowsTheRecommendation(): void
    {
        $totals = MaterialSourcingPresenter::totalsOf([
            $this->structureSideSourcing(chosenMarket: SourcingSide::Jita),
            $this->jitaSideSourcing(chosenMarket: SourcingSide::Jita),
        ]);

        self::assertSame([37_500.0, 40_500.0], [$totals['best'], $totals['selected']]);
    }

    public function testTotalIsNullAsSoonAsOneRowIsUnknownOnThatSide(): void
    {
        $withoutStructurePrice = new MaterialSourcing(
            self::MEXALLON,
            new Quantity(100),
            Cost::known(new Isk(10_000.0)),
            Cost::known(new Isk(30_000.0)),
            Cost::unknown(MissingData::structurePrice(self::MEXALLON)),
            1.0,
            2,
            SourcingSide::Jita,
            true,
            null,
            structureBridgeCost: Cost::known(new Isk(200.0)),
            structureAvailableQuantity: null,
            chosenMarket: null,
            lineId: self::MEXALLON_LINE_ID,
        );

        $totals = MaterialSourcingPresenter::totalsOf([$this->structureSideSourcing(), $withoutStructurePrice]);

        self::assertSame(
            self::sortedByKey(['jitaWithImport' => 63_500.0, 'structure' => null, 'structureDelivered' => null, 'best' => 60_500.0, 'selected' => 60_500.0]),
            self::sortedByKey($totals),
        );
    }

    #[Group('issue-1287')]
    public function testSelectedTotalIsNullWhenARowStoredOnTheStructureHasAnUnknownStructureDeliveredCostAndNeverZero(): void
    {
        $storedOnTheUnpricedStructure = new MaterialSourcing(
            self::MEXALLON,
            new Quantity(100),
            Cost::known(new Isk(10_000.0)),
            Cost::known(new Isk(30_000.0)),
            Cost::unknown(MissingData::structurePrice(self::MEXALLON)),
            1.0,
            2,
            SourcingSide::Jita,
            true,
            null,
            structureBridgeCost: Cost::known(new Isk(200.0)),
            structureAvailableQuantity: null,
            chosenMarket: SourcingSide::Structure,
            lineId: self::MEXALLON_LINE_ID,
        );

        $totals = MaterialSourcingPresenter::totalsOf([$this->structureSideSourcing(), $storedOnTheUnpricedStructure]);

        self::assertSame([60_500.0, null], [$totals['best'], $totals['selected']]);
    }

    public function testTotalsOfNothingToBuyAreZeroAndKnown(): void
    {
        self::assertSame(
            self::sortedByKey(['jitaWithImport' => 0.0, 'structure' => 0.0, 'structureDelivered' => 0.0, 'best' => 0.0, 'selected' => 0.0]),
            self::sortedByKey(MaterialSourcingPresenter::totalsOf([])),
        );
    }

    private function structureSideSourcing(int $structureAvailableQuantity = 1_200, SourcingSide $side = SourcingSide::Structure, ?SourcingSide $chosenMarket = null): MaterialSourcing
    {
        return new MaterialSourcing(
            self::PYERITE,
            new Quantity(1_000),
            Cost::known(new Isk(10_000.0)),
            Cost::known(new Isk(13_500.0)),
            Cost::known(new Isk(20_000.0)),
            0.01,
            9,
            $side,
            false,
            new Isk(3_000.0),
            structureBridgeCost: Cost::known(new Isk(500.0)),
            structureAvailableQuantity: $structureAvailableQuantity,
            chosenMarket: $chosenMarket,
            lineId: self::PYERITE_LINE_ID,
        );
    }

    private function jitaSideSourcing(?SourcingSide $chosenMarket = SourcingSide::Structure): MaterialSourcing
    {
        return new MaterialSourcing(
            self::TRITANIUM,
            new Quantity(10_000),
            Cost::known(new Isk(5_000.0)),
            Cost::known(new Isk(12_000.0)),
            Cost::known(new Isk(15_000.0)),
            0.01,
            9,
            SourcingSide::Jita,
            false,
            new Isk(0.0),
            structureBridgeCost: Cost::known(new Isk(300.0)),
            structureAvailableQuantity: 8_000,
            chosenMarket: $chosenMarket,
            lineId: self::TRITANIUM_LINE_ID,
        );
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function sortedByKey(array $values): array
    {
        ksort($values);

        return $values;
    }
}
