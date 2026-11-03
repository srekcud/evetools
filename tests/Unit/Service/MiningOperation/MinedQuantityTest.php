<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Service\MiningOperation\MinedQuantity;
use App\Service\MiningOperation\MinedQuantityCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MinedQuantityCalculator::class)]
#[CoversClass(MinedQuantity::class)]
#[Group('issue-263')]
final class MinedQuantityTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;

    private const array UNKNOWN_WITHOUT_QUANTITY = ['quantities' => [], 'totalQuantity' => null];

    private MinedQuantityCalculator $calculator;
    private MiningOperation $operation;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->startedSpodumainOperation();
        $this->calculator = new MinedQuantityCalculator();
    }

    public function testCharacterWhoseStartSnapshotNeedsReconnectingIsUnknownAndSoIsItsParticipant(): void
    {
        $this->brannHoltMinedQ1();
        $this->failedStartSnapshot('Brann II', MiningLedgerSnapshotStatus::ReconnectRequired);
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));

        self::assertSame(
            [
                'Brann II' => ['state' => 'unknown', 'unknownReason' => 'reconnect_required'] + self::UNKNOWN_WITHOUT_QUANTITY,
                'Brann' => ['state' => 'unknown', 'unknownReason' => 'reconnect_required'] + self::UNKNOWN_WITHOUT_QUANTITY,
            ],
            [
                'Brann II' => $this->quantityOf($this->calculator->ofCharacter($this->enrolmentOf('Brann II'))),
                'Brann' => $this->quantityOf($this->calculator->ofParticipant($this->membershipOf($this->operation, $this->brann))),
            ],
        );
    }

    public function testParticipantQuantitiesAreTheSumByTypeOfItsCharacters(): void
    {
        $this->brannHoltMinedQ1();
        $this->takenStartSnapshot('Brann II', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->takenEndSnapshot('Brann II', [
            $this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000),
            $this->entry('2026-11-05', self::BISTOT, self::S1, 2_000),
        ]);
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));

        self::assertSame(
            ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 35_500, self::BISTOT => 2_000], 'totalQuantity' => 37_500],
            $this->quantityOf($this->calculator->ofParticipant($this->membershipOf($this->operation, $this->brann))),
        );
    }

    public function testCharacterStillMiningInAnOperationInProgressIsMeasuring(): void
    {
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->takenStartSnapshot('Brann II', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 3_000)]);

        self::assertSame(
            [
                'Brann Holt' => ['state' => 'measuring', 'unknownReason' => null, 'quantities' => [], 'totalQuantity' => null],
                'Brann' => ['state' => 'measuring', 'unknownReason' => null, 'quantities' => [], 'totalQuantity' => null],
                'operation' => 'in_progress',
            ],
            [
                'Brann Holt' => $this->quantityOf($this->calculator->ofCharacter($this->enrolmentOf('Brann Holt'))),
                'Brann' => $this->quantityOf($this->calculator->ofParticipant($this->membershipOf($this->operation, $this->brann))),
                'operation' => $this->operation->getStatus()->value,
            ],
        );
    }

    public function testCharacterWhoseEndSnapshotIsWaitingForItsReadTimeIsMeasuring(): void
    {
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->waitingEndSnapshot('Brann Holt', '2026-11-05T21:37:17+00:00');
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));

        self::assertSame(
            [
                'quantity' => ['state' => 'measuring', 'unknownReason' => null, 'quantities' => [], 'totalQuantity' => null],
                'operation' => 'closing',
            ],
            [
                'quantity' => $this->quantityOf($this->calculator->ofCharacter($this->enrolmentOf('Brann Holt'))),
                'operation' => $this->operation->getStatus()->value,
            ],
        );
    }

    public function testCharacterWhoseEndSnapshotCouldNotBeReadIsUnknown(): void
    {
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->failedEndSnapshot('Brann Holt', MiningLedgerSnapshotStatus::Failed);
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));

        self::assertSame(
            ['state' => 'unknown', 'unknownReason' => 'read_failed'] + self::UNKNOWN_WITHOUT_QUANTITY,
            $this->quantityOf($this->calculator->ofCharacter($this->enrolmentOf('Brann Holt'))),
        );
    }

    public function testCharacterWhoseEndSnapshotIsBeyondTheThirtyDayLedgerIsUnknown(): void
    {
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->failedEndSnapshot('Brann Holt', MiningLedgerSnapshotStatus::OutOfWindow);
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));

        self::assertSame(
            ['state' => 'unknown', 'unknownReason' => 'out_of_window'] + self::UNKNOWN_WITHOUT_QUANTITY,
            $this->quantityOf($this->calculator->ofCharacter($this->enrolmentOf('Brann Holt'))),
        );
    }

    public function testCharacterWithoutStartSnapshotOrWithAWaitingOneIsMeasuring(): void
    {
        $this->identify($this->enrolmentOf('Brann II')->scheduleStartSnapshot(new \DateTimeImmutable('2026-11-05T20:13:00+00:00')));
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));

        self::assertSame(
            ['Brann Holt' => 'measuring', 'Brann II' => 'measuring', 'operation' => 'closing'],
            [
                'Brann Holt' => $this->calculator->ofCharacter($this->enrolmentOf('Brann Holt'))->state->value,
                'Brann II' => $this->calculator->ofCharacter($this->enrolmentOf('Brann II'))->state->value,
                'operation' => $this->operation->getStatus()->value,
            ],
        );
    }

    public function testDepartedParticipantIsKnownOnceItsEndSnapshotIsTakenWhileTheOperationGoesOn(): void
    {
        $this->brannHoltMinedQ1('2026-11-05T21:05:00+00:00');
        $this->membershipOf($this->operation, $this->brann)->depart(new \DateTimeImmutable('2026-11-05T21:05:00+00:00'));

        self::assertSame(
            ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 35_500], 'totalQuantity' => 35_500],
            $this->quantityOf($this->calculator->ofCharacter($this->enrolmentOf('Brann Holt'))),
        );
    }

    public function testParticipantWithAMeasuringCharacterAndNoUnknownOneIsMeasuring(): void
    {
        $this->brannHoltMinedQ1();
        $this->takenStartSnapshot('Brann II', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 3_000)]);
        $this->waitingEndSnapshot('Brann II', '2026-11-05T21:39:50+00:00');
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));

        self::assertSame(
            [
                'Brann Holt' => ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 35_500], 'totalQuantity' => 35_500],
                'Brann' => ['state' => 'measuring', 'unknownReason' => null, 'quantities' => [], 'totalQuantity' => null],
            ],
            [
                'Brann Holt' => $this->quantityOf($this->calculator->ofCharacter($this->enrolmentOf('Brann Holt'))),
                'Brann' => $this->quantityOf($this->calculator->ofParticipant($this->membershipOf($this->operation, $this->brann))),
            ],
        );
    }

    public function testCorrectedUnknownCharacterBecomesKnownWithItsCorrectedTypesOnly(): void
    {
        $this->brannHoltMinedQ1();
        $this->failedStartSnapshot('Brann II', MiningLedgerSnapshotStatus::ReconnectRequired);
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));

        $this->enrolmentOf('Brann II')->correct(self::SPODUMAIN, null, 20_000, $this->aria, new \DateTimeImmutable('2026-11-05T21:50:00+00:00'));

        self::assertSame(
            [
                'Brann II' => ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 20_000], 'totalQuantity' => 20_000],
                'Brann II measured' => ['state' => 'unknown', 'unknownReason' => 'reconnect_required'] + self::UNKNOWN_WITHOUT_QUANTITY,
                'Brann' => ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 55_500], 'totalQuantity' => 55_500],
            ],
            [
                'Brann II' => $this->quantityOf($this->calculator->ofCharacter($this->enrolmentOf('Brann II'))),
                'Brann II measured' => $this->quantityOf($this->calculator->measuredOf($this->enrolmentOf('Brann II'))),
                'Brann' => $this->quantityOf($this->calculator->ofParticipant($this->membershipOf($this->operation, $this->brann))),
            ],
        );
    }

    public function testLatestCorrectionOfATypeReplacesItsMeasuredQuantity(): void
    {
        $this->brannHoltMinedQ1();
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));
        $brannHolt = $this->enrolmentOf('Brann Holt');

        $brannHolt->correct(self::SPODUMAIN, 35_500, 30_000, $this->aria, new \DateTimeImmutable('2026-11-05T21:50:00+00:00'));
        $brannHolt->correct(self::SPODUMAIN, 35_500, 33_000, $this->aria, new \DateTimeImmutable('2026-11-05T21:52:00+00:00'));

        self::assertSame(
            [
                'corrected' => ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 33_000], 'totalQuantity' => 33_000],
                'measured' => ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 35_500], 'totalQuantity' => 35_500],
            ],
            [
                'corrected' => $this->quantityOf($this->calculator->ofCharacter($brannHolt)),
                'measured' => $this->quantityOf($this->calculator->measuredOf($brannHolt)),
            ],
        );
    }

    public function testCorrectionOfAnUnmeasuredTypeAddsItNextToTheMeasuredOnes(): void
    {
        $this->brannHoltMinedQ1();
        $this->operation->end(new \DateTimeImmutable(self::ENDED_AT));

        $this->enrolmentOf('Brann Holt')->correct(self::BISTOT, 0, 1_000, $this->aria, new \DateTimeImmutable('2026-11-05T21:50:00+00:00'));

        self::assertSame(
            ['state' => 'known', 'unknownReason' => null, 'quantities' => [self::SPODUMAIN => 35_500, self::BISTOT => 1_000], 'totalQuantity' => 36_500],
            $this->quantityOf($this->calculator->ofCharacter($this->enrolmentOf('Brann Holt'))),
        );
    }

    private function brannHoltMinedQ1(string $referenceAt = self::ENDED_AT): void
    {
        $this->takenStartSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 12_000)]);
        $this->takenEndSnapshot('Brann Holt', [$this->entry('2026-11-05', self::SPODUMAIN, self::S1, 47_500)], '2026-11-05T21:42:00+00:00', $referenceAt);
    }
}
