<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Service\Industry\Project\PlannedTransfer;
use App\Service\Industry\Project\TransferPlanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransferPlannerTest extends TestCase
{
    private const int EXACT_SEARCH_LIMIT = 12;

    /**
     * @param list<PlannedTransfer> $transfers
     *
     * @return list<array{string, string, int}>
     */
    private static function triplesOf(array $transfers): array
    {
        $triples = array_map(
            static fn (PlannedTransfer $transfer): array => [$transfer->fromParticipantId, $transfer->toParticipantId, $transfer->amountCents],
            $transfers,
        );
        usort($triples, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $triples;
    }

    public function testTwoSellersPayTheSingleParticipantWhoHoldsNothing(): void
    {
        $plan = (new TransferPlanner())->plan(
            ['alpha' => 9_500_000_000, 'bravo' => 3_800_000_000, 'charlie' => 0],
            ['alpha' => 5_000_000_000, 'bravo' => 3_300_000_000, 'charlie' => 5_000_000_000],
        );

        self::assertSame(
            [['alpha', 'charlie', 4_500_000_000], ['bravo', 'charlie', 500_000_000]],
            self::triplesOf($plan->transfers),
        );
        self::assertSame(0, $plan->roundingGapCents);
    }

    public function testTransfersAreSplitInTheGreatestNumberOfIndependentGroupsWhenAGreedyMatchWouldUseMore(): void
    {
        $plan = (new TransferPlanner())->plan(
            ['a' => 600, 'b' => 500, 'c' => 0, 'd' => 0, 'e' => 0],
            ['a' => 0, 'b' => 0, 'c' => 500, 'd' => 400, 'e' => 200],
        );

        self::assertSame(
            [['a', 'd', 400], ['a', 'e', 200], ['b', 'c', 500]],
            self::triplesOf($plan->transfers),
        );
    }

    public function testNothingIsTransferredWhenEveryoneAlreadyHoldsExactlyTheirPayout(): void
    {
        $plan = (new TransferPlanner())->plan(['a' => 700, 'b' => 300], ['a' => 700, 'b' => 300]);

        self::assertSame([[], 0], [$plan->transfers, $plan->roundingGapCents]);
    }

    public function testAParticipantWhoSoldWithoutAnyPayoutPaysEverythingHeld(): void
    {
        $plan = (new TransferPlanner())->plan(['seller' => 1_000], ['member' => 1_000]);

        self::assertSame([['seller', 'member', 1_000]], self::triplesOf($plan->transfers));
    }

    public function testRoundingGapIsAbsorbedByTheLargestPayoutAndTheSmallestIdOnATie(): void
    {
        $plan = (new TransferPlanner())->plan(
            ['x' => 10_000],
            ['a' => 3_333, 'b' => 3_333, 'c' => 3_333],
        );

        self::assertSame(1, $plan->roundingGapCents);
        self::assertSame(
            [['x', 'a', 3_334], ['x', 'b', 3_333], ['x', 'c', 3_333]],
            self::triplesOf($plan->transfers),
        );
    }

    public function testNegativeRoundingGapTakesOneCentLessFromTheLargestPayout(): void
    {
        $plan = (new TransferPlanner())->plan(
            ['x' => 9_998],
            ['a' => 3_333, 'b' => 3_333, 'c' => 3_333],
        );

        self::assertSame(-1, $plan->roundingGapCents);
        self::assertSame(
            [['x', 'a', 3_332], ['x', 'b', 3_333], ['x', 'c', 3_333]],
            self::triplesOf($plan->transfers),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function memberCounts(): iterable
    {
        yield 'within the exact search' => [self::EXACT_SEARCH_LIMIT];
        yield 'beyond the exact search' => [self::EXACT_SEARCH_LIMIT + 5];
    }

    #[DataProvider('memberCounts')]
    public function testEveryParticipantEndsExactlyWithTheirPayoutWhateverTheNumberOfParticipants(int $memberCount): void
    {
        $held = [];
        $due = [];
        for ($index = 0; $index < $memberCount; ++$index) {
            $id = sprintf('member-%02d', $index);
            $held[$id] = ($index * 7_919) % 5_000;
            $due[$id] = (($memberCount - $index) * 6_113) % 5_000;
        }
        $gap = array_sum($held) - array_sum($due);

        $plan = (new TransferPlanner())->plan($held, $due);

        $final = $held;
        foreach ($plan->transfers as $transfer) {
            $final[$transfer->fromParticipantId] -= $transfer->amountCents;
            $final[$transfer->toParticipantId] += $transfer->amountCents;
        }
        self::assertSame($gap, $plan->roundingGapCents);
        self::assertSame(array_sum($due) + $gap, array_sum($final));
        $largestDueId = array_keys($due, max($due), true)[0];
        $expected = $due;
        $expected[$largestDueId] += $gap;
        self::assertSame($expected, $final);
        self::assertLessThanOrEqual($memberCount - 1, count($plan->transfers));
        foreach ($plan->transfers as $transfer) {
            self::assertGreaterThan(0, $transfer->amountCents);
        }
    }
}
