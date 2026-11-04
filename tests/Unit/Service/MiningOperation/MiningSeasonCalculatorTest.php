<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Service\MiningOperation\MiningSeasonCalculator;
use App\Service\MiningOperation\MiningSeasonOperation;
use App\Service\MiningOperation\MiningSeasonParticipant;
use App\Service\MiningOperation\MiningSeasonSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningSeasonCalculator::class)]
#[CoversClass(MiningSeasonSummary::class)]
#[Group('issue-317')]
final class MiningSeasonCalculatorTest extends TestCase
{
    private const string ARIA = 'Aria Solberg';
    private const string BRANN = 'Brann Holt';
    private const string CYRA = 'Cyra Lune';

    private MiningSeasonCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new MiningSeasonCalculator();
    }

    public function testTwoKnownOperationsAddTheirValuesMinutesAndTheMinutesOfEachPlayer(): void
    {
        $summary = $this->calculator->summarise($this->knownOperationsAandB());

        self::assertSame(
            [
                'totals' => [2, 0, 150_000_000, 180, 270, 50_000_000],
                'players' => [
                    [self::ARIA, 2, 180, 100_000_000, false],
                    [self::BRANN, 1, 90, 50_000_000, false],
                ],
            ],
            ['totals' => $this->totalsOf($summary), 'players' => $this->playersOf($summary)],
        );
    }

    public function testUnknownOperationCountsItsHoursButNeitherItsValueNorItsMinutesInTheIskPerHour(): void
    {
        $unknownC = new MiningSeasonOperation(90, null, [
            $this->participant('u-aria', self::ARIA, 90, null),
            $this->participant('u-cyra', self::CYRA, 90, null),
        ]);

        $summary = $this->calculator->summarise([...$this->knownOperationsAandB(), $unknownC]);

        self::assertSame(
            [
                'totals' => [3, 1, 150_000_000, 180, 450, 50_000_000],
                'players' => [
                    [self::ARIA, 3, 270, 100_000_000, true],
                    [self::BRANN, 1, 90, 50_000_000, false],
                    [self::CYRA, 1, 90, null, true],
                ],
            ],
            ['totals' => $this->totalsOf($summary), 'players' => $this->playersOf($summary)],
        );
    }

    public function testNoOperationGivesZerosAndNoKnownValueNorIskPerHour(): void
    {
        $summary = $this->calculator->summarise([]);

        self::assertSame(
            ['totals' => [0, 0, null, 0, 0, null], 'players' => []],
            ['totals' => $this->totalsOf($summary), 'players' => $this->playersOf($summary)],
        );
    }

    public function testOnlyAnUnknownOperationKeepsTheKnownValueAndTheIskPerHourUnknownInsteadOfZero(): void
    {
        $summary = $this->calculator->summarise([
            new MiningSeasonOperation(90, null, [$this->participant('u-cyra', self::CYRA, 90, null)]),
        ]);

        self::assertSame(
            ['totals' => [1, 1, null, 0, 90, null], 'players' => [[self::CYRA, 1, 90, null, true]]],
            ['totals' => $this->totalsOf($summary), 'players' => $this->playersOf($summary)],
        );
    }

    public function testIskPerHourIsRoundedToTheNearestIsk(): void
    {
        $summary = $this->calculator->summarise([new MiningSeasonOperation(7, 1_000_000, [])]);

        self::assertSame(8_571_429, $summary->iskPerHour);
    }

    public function testIskPerHourRoundsAHalfIskUp(): void
    {
        $summary = $this->calculator->summarise([new MiningSeasonOperation(24, 5, [])]);

        self::assertSame(13, $summary->iskPerHour);
    }

    public function testPlayersWithTheSameMinutesAreSortedByName(): void
    {
        $summary = $this->calculator->summarise([
            new MiningSeasonOperation(60, 4_000_000, [
                $this->participant('u-zed', 'Zed', 60, 2_000_000),
                $this->participant('u-abel', 'Abel', 60, 2_000_000),
            ]),
        ]);

        self::assertSame(['Abel', 'Zed'], array_column($this->playersOf($summary), 0));
    }

    private function knownOperationsAandB(): array
    {
        return [
            new MiningSeasonOperation(120, 120_000_000, [
                $this->participant('u-aria', self::ARIA, 120, 70_000_000),
                $this->participant('u-brann', self::BRANN, 90, 50_000_000),
            ]),
            new MiningSeasonOperation(60, 30_000_000, [
                $this->participant('u-aria', self::ARIA, 60, 30_000_000),
            ]),
        ];
    }

    private function participant(string $userKey, string $name, int $minutes, ?int $minedValue): MiningSeasonParticipant
    {
        return new MiningSeasonParticipant($userKey, $name, $minutes, $minedValue);
    }

    private function totalsOf(MiningSeasonSummary $summary): array
    {
        return [
            $summary->operationsCount,
            $summary->unknownValueOperationsCount,
            $summary->knownValue,
            $summary->knownOperationMinutes,
            $summary->playedMinutes,
            $summary->iskPerHour,
        ];
    }

    private function playersOf(MiningSeasonSummary $summary): array
    {
        return array_map(
            static fn (array $player): array => [$player['name'], $player['operationsCount'], $player['minutes'], $player['minedValue'], $player['hasUnknownValue']],
            $summary->players,
        );
    }
}
