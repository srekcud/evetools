<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectJobMatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectJobMatch::class)]
#[Group('issue-1250')]
final class IndustryProjectJobMatchSharedJobTest extends TestCase
{
    public function testTheSyncKeepsTheShareOfTheMatchAndRecomputesItsCostFromTheNewCostOfTheJob(): void
    {
        $match = (new IndustryProjectJobMatch())->setRuns(10)->setCost(500_000.0)->setStatus('active');
        $job = $this->job(20, 1_200_000.0)
            ->setStatus('delivered')
            ->setStartDate(new \DateTimeImmutable('2026-11-09T18:30:00+00:00'))
            ->setEndDate(new \DateTimeImmutable('2026-11-10T02:45:00+00:00'));

        $match->copyJobState($job);

        self::assertSame([10, 600_000.0], [$match->getRuns(), $match->getCost()]);
        self::assertSame(
            ['delivered', '2026-11-09T18:30:00+00:00', '2026-11-10T02:45:00+00:00'],
            [$match->getStatus(), $match->getStartDate()?->format(\DateTimeInterface::ATOM), $match->getEndDate()?->format(\DateTimeInterface::ATOM)],
        );
    }

    public function testTheSyncGivesTheSameShareOfTheCostToTwoMatchesOfTheSameJob(): void
    {
        $first = (new IndustryProjectJobMatch())->setRuns(12)->setCost(1.0);
        $second = (new IndustryProjectJobMatch())->setRuns(8)->setCost(1.0);
        $job = $this->job(20, 1_000_000.0);

        $first->copyJobState($job);
        $second->copyJobState($job);

        self::assertSame([[12, 600_000.0], [8, 400_000.0]], [[$first->getRuns(), $first->getCost()], [$second->getRuns(), $second->getCost()]]);
    }

    public function testTheSyncKeepsTheCostUnknownWhenTheJobHasNoCost(): void
    {
        $match = (new IndustryProjectJobMatch())->setRuns(5)->setCost(250_000.0);

        $match->copyJobState($this->job(20, null));

        self::assertSame(5, $match->getRuns());
        self::assertNull($match->getCost());
    }

    public function testAMatchWithoutShareTakesTheRunsAndTheWholeCostOfTheJob(): void
    {
        $match = new IndustryProjectJobMatch();

        $match->copyJobState($this->job(7, 1_482_350.25));

        self::assertSame([7, 1_482_350.25], [$match->getRuns(), $match->getCost()]);
    }

    private function job(int $runs, ?float $cost): CachedIndustryJob
    {
        return (new CachedIndustryJob())
            ->setStatus('active')
            ->setStartDate(new \DateTimeImmutable('2026-11-02T08:00:00+00:00'))
            ->setEndDate(new \DateTimeImmutable('2026-11-04T08:00:00+00:00'))
            ->setRuns($runs)
            ->setCost($cost);
    }
}
