<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\ProductionSchedule;
use App\Industry\Domain\Schedule;
use App\Industry\Domain\ScheduleJob;
use App\Industry\Domain\ScheduleWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScheduleWorker::class)]
#[CoversClass(ProductionSchedule::class)]
#[CoversClass(Schedule::class)]
final class ScheduleWorkerTest extends TestCase
{
    public function testAWorkerBuiltWithoutLaboratorySlotsHasNone(): void
    {
        $worker = new ScheduleWorker('W1', 3, 2);

        self::assertSame(0, $worker->laboratorySlots);
        self::assertSame(3, $worker->manufacturingSlots);
        self::assertSame(2, $worker->reactionSlots);
    }

    public function testAWorkerBuiltWithoutLaboratorySlotsCannotRunAnInvention(): void
    {
        $schedule = ProductionSchedule::earliest(
            [new ScheduleJob('invention-hornet', ActivityKind::Invention, ['W1' => 5400])],
            [new ScheduleWorker('W1', 3, 2)],
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        self::assertSame([], $schedule->assignments);
        self::assertSame(['invention-hornet' => Schedule::NO_ELIGIBLE_WORKER], $schedule->unscheduled);
        self::assertNull($schedule->end);
    }

    public function testAWorkerWithANegativeLaboratorySlotCountCannotRunACopy(): void
    {
        $schedule = ProductionSchedule::earliest(
            [new ScheduleJob('copy-hornet', ActivityKind::Copying, ['W1' => 3600])],
            [new ScheduleWorker('W1', 4, 3, -1)],
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        self::assertSame([], $schedule->assignments);
        self::assertSame(['copy-hornet' => Schedule::NO_ELIGIBLE_WORKER], $schedule->unscheduled);
        self::assertNull($schedule->end);
    }
}
