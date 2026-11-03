<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Service\MiningOperation\MiningOperationRecurrenceSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningOperationRecurrenceSchedule::class)]
#[Group('issue-310')]
final class MiningOperationRecurrenceScheduleTest extends TestCase
{
    private MiningOperationRecurrenceSchedule $schedule;

    protected function setUp(): void
    {
        $this->schedule = new MiningOperationRecurrenceSchedule();
    }

    public function testThursdayOccurrenceMakesTheNextThursdayDueOnceItsPlannedStartHasPassed(): void
    {
        $lastPlannedStartAt = $this->at('2026-11-05 20:00');
        $now = $this->at('2026-11-05 20:05');

        $nextStartAt = $this->schedule->nextWeeklyStartAt($lastPlannedStartAt, $now);

        self::assertSame('2026-11-12 20:00', $nextStartAt->format('Y-m-d H:i'));
        self::assertTrue($this->schedule->isDue($nextStartAt, $now));
    }

    public function testNextThursdayIsNotDueTheDayBeforeTheLastOccurrence(): void
    {
        $now = $this->at('2026-11-04 12:00');

        $nextStartAt = $this->schedule->nextWeeklyStartAt($this->at('2026-11-05 20:00'), $now);

        self::assertSame('2026-11-12 20:00', $nextStartAt->format('Y-m-d H:i'));
        self::assertFalse($this->schedule->isDue($nextStartAt, $now));
    }

    public function testMissedThursdaysAreSkippedAndOnlyTheNextFutureOneIsDue(): void
    {
        $now = $this->at('2026-11-26 21:00');

        $nextStartAt = $this->schedule->nextWeeklyStartAt($this->at('2026-11-05 20:00'), $now);

        self::assertSame('2026-12-03 20:00', $nextStartAt->format('Y-m-d H:i'));
        self::assertTrue($this->schedule->isDue($nextStartAt, $now));
    }

    public function testSecondPassRightAfterTheCreationTargetsTheFollowingThursdayWhichIsNotDue(): void
    {
        $now = $this->at('2026-11-05 20:06');

        $nextStartAt = $this->schedule->nextWeeklyStartAt($this->at('2026-11-12 20:00'), $now);

        self::assertSame('2026-11-19 20:00', $nextStartAt->format('Y-m-d H:i'));
        self::assertFalse($this->schedule->isDue($nextStartAt, $now));
    }

    public function testNextStartExactlyNowIsKeptAndDue(): void
    {
        $now = $this->at('2026-11-12 20:00');

        $nextStartAt = $this->schedule->nextWeeklyStartAt($this->at('2026-11-05 20:00'), $now);

        self::assertSame('2026-11-12 20:00', $nextStartAt->format('Y-m-d H:i'));
        self::assertTrue($this->schedule->isDue($nextStartAt, $now));
    }

    public function testOccurrenceBecomesDueExactlySevenDaysBeforeItsPlannedStart(): void
    {
        $nextStartAt = $this->at('2026-11-12 20:00');

        self::assertTrue($this->schedule->isDue($nextStartAt, $this->at('2026-11-05 20:00:00')));
        self::assertFalse($this->schedule->isDue($nextStartAt, $this->at('2026-11-05 19:59:59')));
    }

    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }
}
