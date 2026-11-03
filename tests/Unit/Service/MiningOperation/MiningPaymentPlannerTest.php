<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Enum\MiningPaymentKind;
use App\Service\MiningOperation\MiningPaymentPlanner;
use App\Service\MiningOperation\MiningPlannedPayment;
use App\Service\MiningOperation\MiningShareCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningPaymentPlanner::class)]
#[CoversClass(MiningPlannedPayment::class)]
#[Group('issue-265')]
final class MiningPaymentPlannerTest extends TestCase
{
    private const int ARIA = 0;
    private const int BRANN = 1;
    private const int CYRA = 2;
    private const array E1_MINED_VALUES = [2_699_093, 2_868_460, 176];

    private MiningShareCalculator $calculator;
    private MiningPaymentPlanner $planner;

    protected function setUp(): void
    {
        $this->calculator = new MiningShareCalculator();
        $this->planner = new MiningPaymentPlanner();
    }

    public function testEachParticipantButTheBoosterGivesTheBoosterHisMinedValueMinusHisShare(): void
    {
        self::assertSame(
            [
                [0, 'booster_cut', self::ARIA, self::CYRA, 269_909],
                [1, 'booster_cut', self::BRANN, self::CYRA, 286_846],
            ],
            $this->planOf(self::E1_MINED_VALUES, 10, 0, self::CYRA),
        );
    }

    public function testBoosterEndsWithHisShareAndTheBoosterCutOnceTheTenPercentCutIsPaid(): void
    {
        $split = $this->calculator->split(self::E1_MINED_VALUES, 10, 0);
        $payments = $this->planner->plan(self::E1_MINED_VALUES, $split, self::CYRA, false);

        self::assertSame(
            [556_755, 556_931, 556_931],
            [
                $this->amountReceivedBy(self::CYRA, $payments),
                self::E1_MINED_VALUES[self::CYRA] + $this->amountReceivedBy(self::CYRA, $payments),
                $split->shares[self::CYRA] + $split->boosterCut,
            ],
        );
    }

    public function testBoosterPassesTheWholeCorporationCutOnAfterTheBoosterCutLines(): void
    {
        self::assertSame(
            [
                [0, 'booster_cut', self::ARIA, self::CYRA, 404_864],
                [1, 'booster_cut', self::BRANN, self::CYRA, 430_269],
                [2, 'collected_corporation_cut', self::CYRA, null, 278_386],
            ],
            $this->planOf(self::E1_MINED_VALUES, 10, 5, self::CYRA),
        );
    }

    public function testBoosterWhoCollectsTheCorporationCutEndsWithHisShareAndTheBoosterCut(): void
    {
        $split = $this->calculator->split(self::E1_MINED_VALUES, 10, 5);
        $payments = $this->planner->plan(self::E1_MINED_VALUES, $split, self::CYRA, true);

        self::assertSame(
            [556_923, 556_923],
            [
                self::E1_MINED_VALUES[self::CYRA] + $this->amountReceivedBy(self::CYRA, $payments) - $this->amountPaidBy(self::CYRA, $payments),
                $split->shares[self::CYRA] + $split->boosterCut,
            ],
        );
    }

    public function testWithoutBoosterEveryParticipantGivesTheCorporationHisMinedValueMinusHisShare(): void
    {
        self::assertSame(
            [
                [0, 'corporation_cut', self::ARIA, null, 134_955],
                [1, 'corporation_cut', self::BRANN, null, 143_423],
                [2, 'corporation_cut', self::CYRA, null, 9],
            ],
            $this->planOf(self::E1_MINED_VALUES, 0, 5, null),
        );
    }

    public function testCorporationOnlyLinesAddUpToTheCorporationCutWithItsRoundingIsk(): void
    {
        $split = $this->calculator->split(self::E1_MINED_VALUES, 0, 5);

        self::assertSame(
            [278_387, 278_387],
            [$this->amountOf($this->planner->plan(self::E1_MINED_VALUES, $split, null, true)), $split->corporationCut],
        );
    }

    public function testOperationWithoutBoosterNorCorporationCutHasNoPayment(): void
    {
        self::assertSame([], $this->planOf(self::E1_MINED_VALUES, 0, 0, null));
    }

    public function testBoosterWithoutBoosterCutHasNoPaymentWhenThereIsNoCorporationCut(): void
    {
        self::assertSame([], $this->planOf(self::E1_MINED_VALUES, 0, 0, self::CYRA));
    }

    public function testBoosterWhoseCutsTakeEverythingCollectsTheOtherMinedValueAndPassesTheCorporationCutOn(): void
    {
        self::assertSame(
            [
                [0, 'booster_cut', self::ARIA, self::BRANN, 1_000],
                [1, 'collected_corporation_cut', self::BRANN, null, 1_200],
            ],
            $this->planOf([1_000, 2_000], 60, 40, self::BRANN),
        );
    }

    public function testBoosterWhoseCutsTakeEverythingEndsWithTheBoosterCut(): void
    {
        $minedValues = [1_000, 2_000];
        $split = $this->calculator->split($minedValues, 60, 40);
        $payments = $this->planner->plan($minedValues, $split, self::BRANN, true);

        self::assertSame(
            [1_800, 1_800],
            [
                $minedValues[self::BRANN] + $this->amountReceivedBy(self::BRANN, $payments) - $this->amountPaidBy(self::BRANN, $payments),
                $split->boosterCut,
            ],
        );
    }

    public function testParticipantWithoutOreOwesNothingAndHasNoLine(): void
    {
        self::assertSame(
            [
                [0, 'booster_cut', self::ARIA, self::CYRA, 269_909],
                [1, 'booster_cut', self::BRANN, self::CYRA, 286_846],
            ],
            $this->planOf([2_699_093, 2_868_460, 0], 10, 0, self::CYRA),
        );
    }

    public function testDesignatedBoosterWithoutBoosterCutCollectsNothingAndEveryoneGivesTheCorporation(): void
    {
        self::assertSame(
            [
                [0, 'corporation_cut', self::ARIA, null, 134_955],
                [1, 'corporation_cut', self::BRANN, null, 143_423],
                [2, 'corporation_cut', self::CYRA, null, 9],
            ],
            $this->planOf(self::E1_MINED_VALUES, 0, 5, self::CYRA),
        );
    }

    #[DataProvider('plannedOperations')]
    public function testLinesOfTheParticipantsAddUpToWhatTheBoosterAndTheCorporationAreOwedAndNoAmountIsNegative(
        array $minedValues,
        int $boosterCutPercent,
        int $corporationCutPercent,
        ?int $boosterPosition,
        int $expectedParticipantLinesTotal,
    ): void {
        $split = $this->calculator->split($minedValues, $boosterCutPercent, $corporationCutPercent);
        $payments = $this->planner->plan($minedValues, $split, $boosterPosition, $corporationCutPercent > 0);
        $participantLines = array_values(array_filter(
            $payments,
            static fn (MiningPlannedPayment $payment): bool => $payment->kind !== MiningPaymentKind::CollectedCorporationCut,
        ));
        $amounts = array_map(static fn (MiningPlannedPayment $payment): int => $payment->amount, $payments);

        self::assertSame(
            [$expectedParticipantLinesTotal, true],
            [$this->amountOf($participantLines), $amounts === [] || min($amounts) > 0],
        );
    }

    public static function plannedOperations(): iterable
    {
        yield 'ten percent booster cut' => [self::E1_MINED_VALUES, 10, 0, self::CYRA, 556_773 + 0 - 18];
        yield 'booster and corporation cuts' => [self::E1_MINED_VALUES, 10, 5, self::CYRA, 556_773 + 278_386 - 26];
        yield 'corporation cut only' => [self::E1_MINED_VALUES, 0, 5, null, 278_387];
        yield 'no cut' => [self::E1_MINED_VALUES, 0, 0, null, 0];
        yield 'cuts take everything' => [[1_000, 2_000], 60, 40, self::BRANN, 1_800 + 1_200 - 2_000];
        yield 'participant without ore' => [[2_699_093, 2_868_460, 0], 10, 0, self::CYRA, 556_755];
        yield 'booster without booster cut' => [self::E1_MINED_VALUES, 0, 5, self::CYRA, 278_387];
    }

    private function planOf(array $minedValues, int $boosterCutPercent, int $corporationCutPercent, ?int $boosterPosition): array
    {
        $split = $this->calculator->split($minedValues, $boosterCutPercent, $corporationCutPercent);

        return array_map(
            static fn (MiningPlannedPayment $payment): array => [
                $payment->position,
                $payment->kind->value,
                $payment->payerPosition,
                $payment->beneficiaryPosition,
                $payment->amount,
            ],
            $this->planner->plan($minedValues, $split, $boosterPosition, $corporationCutPercent > 0),
        );
    }

    private function amountReceivedBy(int $position, array $payments): int
    {
        return $this->amountOf(array_filter($payments, static fn (MiningPlannedPayment $payment): bool => $payment->beneficiaryPosition === $position));
    }

    private function amountPaidBy(int $position, array $payments): int
    {
        return $this->amountOf(array_filter($payments, static fn (MiningPlannedPayment $payment): bool => $payment->payerPosition === $position));
    }

    private function amountOf(array $payments): int
    {
        return array_sum(array_map(static fn (MiningPlannedPayment $payment): int => $payment->amount, $payments));
    }
}
