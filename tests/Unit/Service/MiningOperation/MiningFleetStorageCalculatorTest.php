<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Service\MiningOperation\MiningFleetStorageCalculator;
use App\Service\MiningOperation\MiningFleetStorageSettlement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningFleetStorageCalculator::class)]
#[CoversClass(MiningFleetStorageSettlement::class)]
#[Group('issue-266')]
final class MiningFleetStorageCalculatorTest extends TestCase
{
    private const array E1_MINED_VALUES = [2_699_093, 2_868_460, 176];
    private const int E1_TOTAL_VALUE = 5_567_729;
    private const array S1_SHARES = [2_429_184, 2_581_614, 158];
    private const array S2_SHARES = [2_294_229, 2_438_191, 150];
    private const array PA3_SHARES = [2_564_138, 2_725_037, 167];
    private const int ARIA = 0;
    private const int BRANN = 1;
    private const int CYRA = 2;

    public function testWithoutRecipientThereIsNoDebtAndEveryoneHoldsWhatHeMined(): void
    {
        $settlement = $this->settle(self::E1_MINED_VALUES, null, self::S1_SHARES);

        self::assertSame(['debts' => [0, 0, 0], 'heldValues' => self::E1_MINED_VALUES], $this->outcomeOf($settlement));
    }

    public function testRecipientWhoReceivesEverythingOwesEachDepositorHisWholeShare(): void
    {
        $settlement = $this->settle([0, 0, 176], self::CYRA, self::S1_SHARES);

        self::assertSame(
            ['debts' => [2_429_184, 2_581_614, 0], 'heldValues' => [2_429_184, 2_581_614, 556_931]],
            $this->outcomeOf($settlement),
        );
    }

    public function testPartialDepositorIsOwedHisShareMinusTheValueHeKept(): void
    {
        $settlement = $this->settle([738_093, 2_868_460, 176], self::BRANN, self::S1_SHARES);

        self::assertSame(
            ['debts' => [1_691_091, 0, 0], 'heldValues' => [2_429_184, 3_138_369, 176]],
            $this->outcomeOf($settlement),
        );
    }

    public function testDepositorWhoKeptMoreThanHisShareIsOwedNothingAndHoldsWhatHeKept(): void
    {
        $settlement = $this->settle([2_502_993, 2_868_460, 176], self::BRANN, self::S1_SHARES);

        self::assertSame(
            ['debts' => [0, 0, 0], 'heldValues' => [2_502_993, 3_064_560, 176]],
            $this->outcomeOf($settlement),
        );
    }

    public function testBoosterRecipientWithACorporationCutOwesTheDepositorHisShare(): void
    {
        $settlement = $this->settle([0, 2_868_460, 176], self::CYRA, self::S2_SHARES);

        self::assertSame(
            ['debts' => [2_294_229, 0, 0], 'heldValues' => [2_294_229, 2_868_460, 405_040]],
            $this->outcomeOf($settlement),
        );
    }

    public function testRecipientOfACorporationOnlyOperationOwesTheDepositorHisShare(): void
    {
        $settlement = $this->settle([0, 2_868_460, 176], self::BRANN, self::PA3_SHARES);

        self::assertSame(
            ['debts' => [2_564_138, 0, 0], 'heldValues' => [2_564_138, 3_003_415, 176]],
            $this->outcomeOf($settlement),
        );
    }

    #[DataProvider('settlementCases')]
    public function testHeldValuesSumToTheTotalValueCoverEveryShareAndNoDebtIsNegative(array $keptValues, ?int $recipientPosition, array $shares): void
    {
        $settlement = $this->settle($keptValues, $recipientPosition, $shares);

        self::assertSame(
            ['total' => self::E1_TOTAL_VALUE, 'participants' => 3, 'everyShareCovered' => true, 'noNegativeDebt' => true],
            [
                'total' => array_sum($settlement->heldValues),
                'participants' => count($settlement->heldValues),
                'everyShareCovered' => array_filter($settlement->heldValues, static fn (int $held, int $position): bool => $held < $shares[$position], ARRAY_FILTER_USE_BOTH) === [],
                'noNegativeDebt' => count($settlement->debts) === 3 && array_filter($settlement->debts, static fn (int $debt): bool => $debt < 0) === [],
            ],
        );
    }

    public static function settlementCases(): iterable
    {
        yield 'FC0 without fleet storage' => [self::E1_MINED_VALUES, null, self::S1_SHARES];
        yield 'FC1 everything to Cyra' => [[0, 0, 176], self::CYRA, self::S1_SHARES];
        yield 'FC2 partial towards Brann' => [[738_093, 2_868_460, 176], self::BRANN, self::S1_SHARES];
        yield 'FC3 kept more than the share' => [[2_502_993, 2_868_460, 176], self::BRANN, self::S1_SHARES];
        yield 'FC4 everything to Cyra with a corporation cut' => [[0, 2_868_460, 176], self::CYRA, self::S2_SHARES];
        yield 'FC5 corporation only, everything to Brann' => [[0, 2_868_460, 176], self::BRANN, self::PA3_SHARES];
    }

    public function testKeptValueAboveTheMinedValueIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        $this->settle([2_699_094, 2_868_460, 176], self::BRANN, self::S1_SHARES);
    }

    private function settle(array $keptValues, ?int $recipientPosition, array $shares): MiningFleetStorageSettlement
    {
        return (new MiningFleetStorageCalculator())->settle(self::E1_MINED_VALUES, $keptValues, $recipientPosition, $shares);
    }

    private function outcomeOf(MiningFleetStorageSettlement $settlement): array
    {
        return ['debts' => $settlement->debts, 'heldValues' => $settlement->heldValues];
    }
}
