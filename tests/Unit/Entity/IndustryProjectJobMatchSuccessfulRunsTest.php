<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectJobMatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectJobMatch::class)]
#[Group('issue-1316')]
final class IndustryProjectJobMatchSuccessfulRunsTest extends TestCase
{
    public function testMatchCoveringAllTheRunsOfTheJobTakesItsSuccessfulRuns(): void
    {
        $match = (new IndustryProjectJobMatch())->setRuns(59);

        $match->copyJobState($this->job(59, 21));

        self::assertSame(21, $match->getSuccessfulRuns());
    }

    public function testMatchSharingTheJobKeepsTheSuccessfulRunsUnknownInsteadOfAProportion(): void
    {
        $match = (new IndustryProjectJobMatch())->setRuns(30);

        $match->copyJobState($this->job(59, 21));

        self::assertNull($match->getSuccessfulRuns());
    }

    public function testJobWithoutResultLeavesTheSuccessfulRunsUnknown(): void
    {
        $match = (new IndustryProjectJobMatch())->setRuns(59);

        $match->copyJobState($this->job(59, null));

        self::assertNull($match->getSuccessfulRuns());
    }

    public function testResultAlreadyCopiedGoesBackToUnknownWhenTheJobNoLongerHasIt(): void
    {
        $match = (new IndustryProjectJobMatch())->setRuns(59)->setSuccessfulRuns(21);

        $match->copyJobState($this->job(59, null));

        self::assertNull($match->getSuccessfulRuns());
    }

    private function job(int $runs, ?int $successfulRuns): CachedIndustryJob
    {
        return (new CachedIndustryJob())
            ->setStatus('delivered')
            ->setStartDate(new \DateTimeImmutable('2026-11-02T08:00:00+00:00'))
            ->setEndDate(new \DateTimeImmutable('2026-11-04T08:00:00+00:00'))
            ->setRuns($runs)
            ->setSuccessfulRuns($successfulRuns);
    }
}
