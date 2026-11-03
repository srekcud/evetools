<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Service\MiningOperation\MiningShareCalculator;
use App\Service\MiningOperation\MiningShareSplit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningShareCalculator::class)]
#[CoversClass(MiningShareSplit::class)]
#[Group('issue-264')]
final class MiningShareCalculatorTest extends TestCase
{
    private const int ARIA_MINED_VALUE = 2_699_093;
    private const int BRANN_MINED_VALUE = 2_868_460;
    private const int CYRA_MINED_VALUE = 176;
    private const array E1_MINED_VALUES = [self::ARIA_MINED_VALUE, self::BRANN_MINED_VALUE, self::CYRA_MINED_VALUE];

    private MiningShareCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new MiningShareCalculator();
    }

    public function testTenPercentBoosterCutGivesTheTwoIskRoundingRemainderToTheBoosterThenToAria(): void
    {
        self::assertSame(
            [
                'totalValue' => 5_567_729,
                'shares' => [2_429_184, 2_581_614, 158],
                'boosterCut' => 556_773,
                'corporationCut' => 0,
                'roundingAdjustments' => [1, 0, 0],
                'boosterRoundingAdjustment' => 1,
                'corporationRoundingAdjustment' => 0,
                'roundingRemainder' => 2,
            ],
            $this->splitOf($this->calculator->split(self::E1_MINED_VALUES, 10, 0)),
        );
    }

    public function testSharesPlusBoosterAndCorporationCutsAddUpToTheTotalValue(): void
    {
        $split = $this->calculator->split(self::E1_MINED_VALUES, 10, 0);

        self::assertSame(
            [5_010_956, 5_567_729],
            [array_sum($split->shares), array_sum($split->shares) + $split->boosterCut + $split->corporationCut],
        );
    }

    public function testCorporationCutTakesItsShareAndTheRemainderGoesToTheBoosterThenToCyra(): void
    {
        self::assertSame(
            [
                'totalValue' => 5_567_729,
                'shares' => [2_294_229, 2_438_191, 150],
                'boosterCut' => 556_773,
                'corporationCut' => 278_386,
                'roundingAdjustments' => [0, 0, 1],
                'boosterRoundingAdjustment' => 1,
                'corporationRoundingAdjustment' => 0,
                'roundingRemainder' => 2,
            ],
            $this->splitOf($this->calculator->split(self::E1_MINED_VALUES, 10, 5)),
        );
    }

    public function testEqualRemaindersServeTheParticipantsInLedgerOrderBeforeTheBooster(): void
    {
        self::assertSame(
            [
                'totalValue' => 3,
                'shares' => [1, 1, 0],
                'boosterCut' => 1,
                'corporationCut' => 0,
                'roundingAdjustments' => [1, 1, 0],
                'boosterRoundingAdjustment' => 0,
                'corporationRoundingAdjustment' => 0,
                'roundingRemainder' => 2,
            ],
            $this->splitOf($this->calculator->split([1, 1, 1], 50, 0)),
        );
    }

    public function testWithoutBoosterOrCorporationCutEachShareIsTheMinedValue(): void
    {
        self::assertSame(
            [
                'totalValue' => 5_567_729,
                'shares' => self::E1_MINED_VALUES,
                'boosterCut' => 0,
                'corporationCut' => 0,
                'roundingAdjustments' => [0, 0, 0],
                'boosterRoundingAdjustment' => 0,
                'corporationRoundingAdjustment' => 0,
                'roundingRemainder' => 0,
            ],
            $this->splitOf($this->calculator->split(self::E1_MINED_VALUES, 0, 0)),
        );
    }

    public function testBoosterAndCorporationTakingEverythingLeaveNoShareToTheParticipants(): void
    {
        self::assertSame(
            [
                'totalValue' => 3_000,
                'shares' => [0, 0],
                'boosterCut' => 1_800,
                'corporationCut' => 1_200,
                'roundingAdjustments' => [0, 0],
                'boosterRoundingAdjustment' => 0,
                'corporationRoundingAdjustment' => 0,
                'roundingRemainder' => 0,
            ],
            $this->splitOf($this->calculator->split([1_000, 2_000], 60, 40)),
        );
    }

    public function testOneUnknownMinedValueMakesTheTotalTheCutsAndEveryShareUnknown(): void
    {
        self::assertSame(
            [
                'totalValue' => null,
                'shares' => [null, null, null],
                'boosterCut' => null,
                'corporationCut' => null,
                'roundingAdjustments' => [null, null, null],
                'boosterRoundingAdjustment' => null,
                'corporationRoundingAdjustment' => null,
                'roundingRemainder' => null,
            ],
            $this->splitOf($this->calculator->split([self::ARIA_MINED_VALUE, null, self::CYRA_MINED_VALUE], 10, 0)),
        );
    }

    public function testOperationWorthNothingSplitsIntoZeroShares(): void
    {
        self::assertSame(
            [
                'totalValue' => 0,
                'shares' => [0, 0],
                'boosterCut' => 0,
                'corporationCut' => 0,
                'roundingAdjustments' => [0, 0],
                'boosterRoundingAdjustment' => 0,
                'corporationRoundingAdjustment' => 0,
                'roundingRemainder' => 0,
            ],
            $this->splitOf($this->calculator->split([0, 0], 10, 0)),
        );
    }

    public function testHundredBillionIskOperationIsSplitInExactIntegersWithoutOverflow(): void
    {
        $split = $this->calculator->split([90_000_000_000, 10_000_000_001], 7, 3);

        self::assertSame(
            [
                'totalValue' => 100_000_000_001,
                'shares' => [81_000_000_000, 9_000_000_001],
                'boosterCut' => 7_000_000_000,
                'corporationCut' => 3_000_000_000,
                'roundingAdjustments' => [0, 1],
                'boosterRoundingAdjustment' => 0,
                'corporationRoundingAdjustment' => 0,
                'roundingRemainder' => 1,
                'sum' => 100_000_000_001,
            ],
            $this->splitOf($split) + ['sum' => array_sum($split->shares) + $split->boosterCut + $split->corporationCut],
        );
    }

    private function splitOf(MiningShareSplit $split): array
    {
        return [
            'totalValue' => $split->totalValue,
            'shares' => $split->shares,
            'boosterCut' => $split->boosterCut,
            'corporationCut' => $split->corporationCut,
            'roundingAdjustments' => $split->roundingAdjustments,
            'boosterRoundingAdjustment' => $split->boosterRoundingAdjustment,
            'corporationRoundingAdjustment' => $split->corporationRoundingAdjustment,
            'roundingRemainder' => $split->roundingRemainder,
        ];
    }
}
