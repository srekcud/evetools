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
#[Group('issue-466')]
final class MaterialSourcingPresenterTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;

    public function testRowOfAStructureSideMaterialIsPresentedForTheRemainingQuantity(): void
    {
        $row = MaterialSourcingPresenter::rowOf($this->structureSideSourcing(), 'Pyerite');

        self::assertSame(
            [
                'typeId' => self::PYERITE,
                'typeName' => 'Pyerite',
                'quantity' => 1_000,
                'volumePerUnit' => 0.01,
                'jumps' => 9,
                'jitaTotal' => 10_000.0,
                'importTotal' => 13_500.0,
                'jitaWithImportTotal' => 23_500.0,
                'structureTotal' => 20_000.0,
                'bestTotal' => 20_000.0,
                'side' => 'structure',
                'structureFallback' => false,
                'saving' => 3_500.0,
                'missingData' => [],
                'warnings' => [],
            ],
            $row,
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

    public function testTotalsSumTheJitaWithImportTheStructureAndTheBestSideOfEveryRow(): void
    {
        $totals = MaterialSourcingPresenter::totalsOf([$this->structureSideSourcing(), $this->jitaSideSourcing()]);

        self::assertSame(['jitaWithImport' => 40_500.0, 'structure' => 35_000.0, 'best' => 37_000.0], $totals);
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
        );

        $totals = MaterialSourcingPresenter::totalsOf([$this->structureSideSourcing(), $withoutStructurePrice]);

        self::assertSame(['jitaWithImport' => 63_500.0, 'structure' => null, 'best' => 60_000.0], $totals);
    }

    public function testTotalsOfNothingToBuyAreZeroAndKnown(): void
    {
        self::assertSame(['jitaWithImport' => 0.0, 'structure' => 0.0, 'best' => 0.0], MaterialSourcingPresenter::totalsOf([]));
    }

    private function structureSideSourcing(): MaterialSourcing
    {
        return new MaterialSourcing(
            self::PYERITE,
            new Quantity(1_000),
            Cost::known(new Isk(10_000.0)),
            Cost::known(new Isk(13_500.0)),
            Cost::known(new Isk(20_000.0)),
            0.01,
            9,
            SourcingSide::Structure,
            false,
            new Isk(3_500.0),
        );
    }

    private function jitaSideSourcing(): MaterialSourcing
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
        );
    }
}
