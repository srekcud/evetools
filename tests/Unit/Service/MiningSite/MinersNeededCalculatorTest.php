<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningSite;

use App\Enum\MinersEstimateStatus;
use App\Service\MiningSite\MinersNeededCalculator;
use App\Service\MiningSite\SiteLifespanSample;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MinersNeededCalculator::class)]
#[CoversClass(SiteLifespanSample::class)]
#[Group('issue-309')]
final class MinersNeededCalculatorTest extends TestCase
{
    private const float DELTA = 0.01;

    public function testThreeSitesGiveTheMeanWorkloadTheMinersNeededBeforeDowntimeAndTheDurationForEachCrewSize(): void
    {
        $needed = (new MinersNeededCalculator())->calculate($this->e1Sites(), new \DateTimeImmutable('2026-11-11T09:40:00+00:00'));

        self::assertSame(MinersEstimateStatus::Estimated, $needed->status);
        self::assertSame(3, $needed->knownSiteCount);
        self::assertEqualsWithDelta(605.67, $needed->workMinerMinutes, self::DELTA);
        self::assertSame(8, $needed->minersNeeded);
        self::assertSame('2026-11-11T11:00:00+00:00', $needed->deadline?->format(\DateTimeInterface::ATOM));
        self::assertEqualsWithDelta(80.0, $needed->availableMinutes, self::DELTA);
        self::assertSame([4, 5, 6, 7, 8, 9], array_column($needed->durations, 'miners'));
    }

    public function testDurationOfACrewIsTheMeanWorkloadDividedByItsSizeAndTellsWhetherItEndsBeforeDowntime(): void
    {
        $needed = (new MinersNeededCalculator())->calculate($this->e1Sites(), new \DateTimeImmutable('2026-11-11T09:40:00+00:00'));

        $byMiners = array_column($needed->durations, null, 'miners');
        self::assertEqualsWithDelta(100.94, $byMiners[6]['minutes'], self::DELTA);
        self::assertEqualsWithDelta(75.71, $byMiners[8]['minutes'], self::DELTA);
        self::assertEqualsWithDelta(86.52, $byMiners[7]['minutes'], self::DELTA);
        self::assertSame([false, true, false], [$byMiners[6]['beforeDeadline'], $byMiners[8]['beforeDeadline'], $byMiners[7]['beforeDeadline']]);
    }

    public function testTwoSitesAreNotEnoughHistoryAndProposeNoDurationAndNoMiners(): void
    {
        $needed = (new MinersNeededCalculator())->calculate(array_slice($this->e1Sites(), 0, 2), new \DateTimeImmutable('2026-11-11T09:40:00+00:00'));

        self::assertSame(
            [MinersEstimateStatus::InsufficientHistory, 2, null, null, []],
            [$needed->status, $needed->knownSiteCount, $needed->workMinerMinutes, $needed->minersNeeded, $needed->durations],
        );
    }

    public function testSiteWithoutAnyMinerStaysOutOfTheMeanAndOfTheCount(): void
    {
        $withUnknown = [...$this->e1Sites(), $this->site('D', 0, 80.0)];

        $needed = (new MinersNeededCalculator())->calculate($withUnknown, new \DateTimeImmutable('2026-11-11T09:40:00+00:00'));

        self::assertSame(3, $needed->knownSiteCount);
        self::assertEqualsWithDelta(605.67, $needed->workMinerMinutes, self::DELTA);
    }

    public function testTwoKnownSitesPlusAnUnknownOneRemainInsufficientHistory(): void
    {
        $sites = [$this->site('A', 6, 98.0), $this->site('B', 5, 121.0), $this->site('D', 0, 80.0)];

        $needed = (new MinersNeededCalculator())->calculate($sites, new \DateTimeImmutable('2026-11-11T09:40:00+00:00'));

        self::assertSame([MinersEstimateStatus::InsufficientHistory, 2], [$needed->status, $needed->knownSiteCount]);
    }

    public function testWorkloadOfASiteIsItsMinersTimesItsLifespanAndUnknownWithoutMiner(): void
    {
        self::assertSame([588.0, null], [$this->site('A', 6, 98.0)->workMinerMinutes(), $this->site('D', 0, 80.0)->workMinerMinutes()]);
    }

    public function testStartAtDowntimeSharpTargetsTheDowntimeOfTheNextDay(): void
    {
        $needed = (new MinersNeededCalculator())->calculate($this->e1Sites(), new \DateTimeImmutable('2026-11-11T11:00:00+00:00'));

        self::assertSame('2026-11-12T11:00:00+00:00', $needed->deadline?->format(\DateTimeInterface::ATOM));
        self::assertEqualsWithDelta(1_440.0, $needed->availableMinutes, self::DELTA);
    }

    public function testStartAfterDowntimeOfTheDayTargetsTheNextDay(): void
    {
        $needed = (new MinersNeededCalculator())->calculate($this->e1Sites(), new \DateTimeImmutable('2026-11-11T12:30:00+00:00'));

        self::assertSame('2026-11-12T11:00:00+00:00', $needed->deadline?->format(\DateTimeInterface::ATOM));
    }

    public function testAtLeastOneMinerIsNeededEvenWhenTheWorkloadIsSmall(): void
    {
        $sites = [$this->site('A', 1, 50.0), $this->site('B', 1, 50.0), $this->site('C', 1, 50.0)];

        $needed = (new MinersNeededCalculator())->calculate($sites, new \DateTimeImmutable('2026-11-11T09:40:00+00:00'));

        self::assertSame(1, $needed->minersNeeded);
    }

    /**
     * @return list<SiteLifespanSample>
     */
    private function e1Sites(): array
    {
        return [$this->site('A', 6, 98.0), $this->site('B', 5, 121.0), $this->site('C', 6, 104.0)];
    }

    private function site(string $label, int $miners, float $lifespanMinutes): SiteLifespanSample
    {
        return new SiteLifespanSample($label, new \DateTimeImmutable('2026-10-22T20:00:00+00:00'), $lifespanMinutes, $miners);
    }
}
