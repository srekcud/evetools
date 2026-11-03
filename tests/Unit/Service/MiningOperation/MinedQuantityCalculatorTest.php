<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Service\MiningOperation\MinedQuantity;
use App\Service\MiningOperation\MinedQuantityCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MinedQuantityCalculator::class)]
#[CoversClass(MinedQuantity::class)]
#[Group('issue-263')]
final class MinedQuantityCalculatorTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private MinedQuantityCalculator $calculator;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->startedSpodumainOperation();
        $this->calculator = new MinedQuantityCalculator();
    }

    public function testSpodumainMinedDuringTheOperationIsTheEndMinusTheStartOfTheSameLedgerEntry(): void
    {
        $start = $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $end = $this->takenEndSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 47_500)]);

        self::assertSame(
            ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 35_500], 'totalQuantity' => 35_500],
            $this->quantityOf($this->calculator->between($start, $end, null)),
        );
    }

    public function testOperationCrossingMidnightUtcAddsTheMinedQuantitiesOfBothDays(): void
    {
        $start = $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 5_000)], '2026-11-05T23:00:00+00:00');
        $end = $this->takenEndSnapshot('Brann Holt', [
            $this->entry('2026-11-05', self::SPODUMAIN, self::S1, 9_000),
            $this->entry('2026-11-06', self::SPODUMAIN, self::S1, 3_000),
        ], '2026-11-06T01:40:00+00:00', '2026-11-06T01:30:00+00:00');

        self::assertSame(
            ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 7_000], 'totalQuantity' => 7_000],
            $this->quantityOf($this->calculator->between($start, $end, null)),
        );
    }

    public function testNewTypeAtTheEndIsMinedFromZeroAndAnUnchangedTypeIsNotListed(): void
    {
        $start = $this->takenStartSnapshot('Brann II', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $end = $this->takenEndSnapshot('Brann II', [
            $this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000),
            $this->entry('2026-11-05', self::BISTOT, self::S1, 2_000),
        ]);

        self::assertSame(
            ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::BISTOT => 2_000], 'totalQuantity' => 2_000],
            $this->quantityOf($this->calculator->between($start, $end, null)),
        );
    }

    public function testOperationInS1CountsOnlyTheLedgerEntriesOfS1(): void
    {
        $start = $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $end = $this->takenEndSnapshot('Brann Holt', [
            $this->entry('2026-11-05', self::SPODUMAIN, self::S1, 47_500),
            $this->entry('2026-11-05', self::SPODUMAIN, self::S2, 8_000),
        ]);

        self::assertSame(
            ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 35_500], 'totalQuantity' => 35_500],
            $this->quantityOf($this->calculator->between($start, $end, self::S1)),
        );
    }

    public function testOperationWithoutSolarSystemCountsTheLedgerEntriesOfEverySystem(): void
    {
        $start = $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $end = $this->takenEndSnapshot('Brann Holt', [
            $this->entry('2026-11-05', self::SPODUMAIN, self::S1, 47_500),
            $this->entry('2026-11-05', self::SPODUMAIN, self::S2, 8_000),
        ]);

        self::assertSame(
            ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 43_500], 'totalQuantity' => 43_500],
            $this->quantityOf($this->calculator->between($start, $end, null)),
        );
    }

    public function testEmptyLedgerAtTheStartCountsTheWholeEndLedgerEntry(): void
    {
        $start = $this->takenStartSnapshot('Aria II', []);
        $end = $this->takenEndSnapshot('Aria II', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 6_000)]);

        self::assertSame(
            ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 6_000], 'totalQuantity' => 6_000],
            $this->quantityOf($this->calculator->between($start, $end, null)),
        );
    }

    public function testNothingMinedIsAKnownQuantityOfZeroUnitsWithoutAnyType(): void
    {
        $start = $this->takenStartSnapshot('Aria Solberg', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $end = $this->takenEndSnapshot('Aria Solberg', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);

        self::assertSame(
            ['state' => 'known', 'unknownReason' => null, 'quantities' => [], 'totalQuantity' => 0],
            $this->quantityOf($this->calculator->between($start, $end, null)),
        );
    }

    public function testLedgerEntryThatLeftTheThirtyDayWindowIsIgnored(): void
    {
        $start = $this->takenStartSnapshot('Brann Holt', [
            $this->entry('2026-10-06', self::BISTOT, self::S1, 900),
            $this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000),
        ]);
        $end = $this->takenEndSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 47_500)]);

        self::assertSame(
            ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 35_500], 'totalQuantity' => 35_500],
            $this->quantityOf($this->calculator->between($start, $end, null)),
        );
    }

    public function testLedgerEntryThatDecreasedMakesTheQuantityUnknownInsteadOfNegative(): void
    {
        $start = $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $end = $this->takenEndSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 11_000)]);

        self::assertSame(
            ['state' => 'unknown', 'unknownReason' => 'ledger_inconsistent', 'quantities' => [], 'totalQuantity' => null],
            $this->quantityOf($this->calculator->between($start, $end, null)),
        );
    }
}
