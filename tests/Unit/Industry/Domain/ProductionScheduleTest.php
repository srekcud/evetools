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

#[CoversClass(ProductionSchedule::class)]
#[CoversClass(Schedule::class)]
final class ProductionScheduleTest extends TestCase
{
    private const string DAY = '2026-01-01';

    public function testE1SingleManufacturingSlotRunsIndependentJobsOneAfterAnother(): void
    {
        $schedule = ProductionSchedule::earliest(
            [$this->manufacturing('A', ['W1' => 3600]), $this->manufacturing('B', ['W1' => 7200])],
            [new ScheduleWorker('W1', 1, 0)],
            $this->at('00:00'),
        );

        self::assertEquals([
            'assignments' => ['A' => 'W1 00:00-01:00', 'B' => 'W1 01:00-03:00'],
            'unscheduled' => [],
            'end' => '03:00',
        ], $this->outcomeOf($schedule));
    }

    public function testE2TwoManufacturingSlotsRunIndependentJobsInParallel(): void
    {
        $schedule = ProductionSchedule::earliest(
            [$this->manufacturing('A', ['W1' => 3600]), $this->manufacturing('B', ['W1' => 7200])],
            [new ScheduleWorker('W1', 2, 0)],
            $this->at('00:00'),
        );

        self::assertEquals([
            'assignments' => ['A' => 'W1 00:00-01:00', 'B' => 'W1 00:00-02:00'],
            'unscheduled' => [],
            'end' => '02:00',
        ], $this->outcomeOf($schedule));
    }

    public function testE3JobStartsWhenTheLastJobItDependsOnEnds(): void
    {
        $schedule = ProductionSchedule::earliest(
            [
                $this->manufacturing('A', ['W1' => 3600]),
                $this->manufacturing('B', ['W1' => 7200]),
                $this->manufacturing('C', ['W1' => 1800], ['A', 'B']),
            ],
            [new ScheduleWorker('W1', 1, 0)],
            $this->at('00:00'),
        );

        self::assertEquals([
            'assignments' => ['A' => 'W1 00:00-01:00', 'B' => 'W1 01:00-03:00', 'C' => 'W1 03:00-03:30'],
            'unscheduled' => [],
            'end' => '03:30',
        ], $this->outcomeOf($schedule));
    }

    public function testE4JobGoesToTheWorkerThatEndsItFirst(): void
    {
        $schedule = ProductionSchedule::earliest(
            [$this->manufacturing('X', ['W1' => 3600, 'W2' => 3000])],
            [new ScheduleWorker('W1', 1, 0), new ScheduleWorker('W2', 1, 0)],
            $this->at('00:00'),
        );

        self::assertEquals([
            'assignments' => ['X' => 'W2 00:00-00:50'],
            'unscheduled' => [],
            'end' => '00:50',
        ], $this->outcomeOf($schedule));
    }

    public function testE5JobWithoutDurationForAnyWorkerIsUnscheduledWithNoEligibleWorker(): void
    {
        $schedule = ProductionSchedule::earliest(
            [$this->manufacturing('Y', [])],
            [new ScheduleWorker('W1', 1, 0), new ScheduleWorker('W2', 1, 0)],
            $this->at('00:00'),
        );

        self::assertEquals([
            'assignments' => [],
            'unscheduled' => ['Y' => Schedule::NO_ELIGIBLE_WORKER],
            'end' => null,
        ], $this->outcomeOf($schedule));
    }

    public function testE6JobDependingOnAnUnscheduledJobIsUnscheduledWithDependencyUnscheduled(): void
    {
        $schedule = ProductionSchedule::earliest(
            [$this->manufacturing('Y', []), $this->manufacturing('Z', ['W1' => 3600], ['Y'])],
            [new ScheduleWorker('W1', 1, 0)],
            $this->at('00:00'),
        );

        self::assertEquals([
            'assignments' => [],
            'unscheduled' => ['Y' => Schedule::NO_ELIGIBLE_WORKER, 'Z' => Schedule::DEPENDENCY_UNSCHEDULED],
            'end' => null,
        ], $this->outcomeOf($schedule));
    }

    public function testSameEndForTwoWorkersGoesToTheFirstWorkerOfTheList(): void
    {
        $schedule = ProductionSchedule::earliest(
            [$this->manufacturing('T', ['W1' => 3600, 'W2' => 3600])],
            [new ScheduleWorker('W2', 1, 0), new ScheduleWorker('W1', 1, 0)],
            $this->at('00:00'),
        );

        self::assertEquals(['T' => 'W2 00:00-01:00'], $this->outcomeOf($schedule)['assignments']);
    }

    public function testThirdJobOnTwoSlotsStartsWhenTheFirstSlotFrees(): void
    {
        $schedule = ProductionSchedule::earliest(
            [
                $this->manufacturing('A', ['W1' => 3600]),
                $this->manufacturing('B', ['W1' => 7200]),
                $this->manufacturing('C', ['W1' => 1800]),
            ],
            [new ScheduleWorker('W1', 2, 0)],
            $this->at('00:00'),
        );

        self::assertEquals([
            'assignments' => ['A' => 'W1 00:00-01:00', 'B' => 'W1 00:00-02:00', 'C' => 'W1 01:00-01:30'],
            'unscheduled' => [],
            'end' => '02:00',
        ], $this->outcomeOf($schedule));
    }

    public function testReactionUsesOnlyReactionSlots(): void
    {
        $schedule = ProductionSchedule::earliest(
            [new ScheduleJob('R', ActivityKind::Reaction, ['W1' => 1800, 'W2' => 3600])],
            [new ScheduleWorker('W1', 1, 0), new ScheduleWorker('W2', 0, 1)],
            $this->at('00:00'),
        );

        self::assertEquals(['R' => 'W2 00:00-01:00'], $this->outcomeOf($schedule)['assignments']);
    }

    public function testManufacturingUsesOnlyManufacturingSlots(): void
    {
        $schedule = ProductionSchedule::earliest(
            [$this->manufacturing('M', ['W1' => 5400, 'W2' => 1800])],
            [new ScheduleWorker('W1', 1, 0), new ScheduleWorker('W2', 0, 3)],
            $this->at('00:00'),
        );

        self::assertEquals(['M' => 'W1 00:00-01:30'], $this->outcomeOf($schedule)['assignments']);
    }

    public function testWorkerWithoutSlotOfTheJobActivityIsNotEligible(): void
    {
        $schedule = ProductionSchedule::earliest(
            [new ScheduleJob('R', ActivityKind::Reaction, ['W1' => 2400])],
            [new ScheduleWorker('W1', 2, 0)],
            $this->at('00:00'),
        );

        self::assertEquals([
            'assignments' => [],
            'unscheduled' => ['R' => Schedule::NO_ELIGIBLE_WORKER],
            'end' => null,
        ], $this->outcomeOf($schedule));
    }

    public function testJobWithFixedEndIsNotScheduledAndItsDependentStartsAtThatEnd(): void
    {
        $schedule = ProductionSchedule::earliest(
            [$this->manufacturing('A', ['W1' => 3600]), $this->manufacturing('B', ['W1' => 1800], ['A'])],
            [new ScheduleWorker('W1', 1, 0)],
            $this->at('02:00'),
            ['A' => $this->at('05:00')],
        );

        self::assertEquals([
            'assignments' => ['B' => 'W1 05:00-05:30'],
            'unscheduled' => [],
            'end' => '05:30',
        ], $this->outcomeOf($schedule));
    }

    public function testDependentOfAFixedEndBeforeTheStartStartsAtTheStart(): void
    {
        $schedule = ProductionSchedule::earliest(
            [$this->manufacturing('A', ['W1' => 3600]), $this->manufacturing('B', ['W1' => 2700], ['A'])],
            [new ScheduleWorker('W1', 1, 0)],
            $this->at('04:00'),
            ['A' => $this->at('01:00')],
        );

        self::assertEquals(['B' => 'W1 04:00-04:45'], $this->outcomeOf($schedule)['assignments']);
    }

    public function testPlanEndIsNullWhenOneJobStaysUnscheduledBesideScheduledOnes(): void
    {
        $schedule = ProductionSchedule::earliest(
            [$this->manufacturing('A', ['W1' => 3600]), $this->manufacturing('Y', [])],
            [new ScheduleWorker('W1', 1, 0)],
            $this->at('00:00'),
        );

        self::assertEquals([
            'assignments' => ['A' => 'W1 00:00-01:00'],
            'unscheduled' => ['Y' => Schedule::NO_ELIGIBLE_WORKER],
            'end' => null,
        ], $this->outcomeOf($schedule));
    }

    public function testParentListedBeforeItsChildrenIsScheduledAfterThem(): void
    {
        $schedule = ProductionSchedule::earliest(
            [
                $this->manufacturing('P', ['W1' => 900], ['C1', 'C2']),
                $this->manufacturing('C1', ['W1' => 3600]),
                $this->manufacturing('C2', ['W1' => 7200]),
            ],
            [new ScheduleWorker('W1', 1, 0)],
            $this->at('00:00'),
        );

        self::assertEquals([
            'assignments' => ['C1' => 'W1 00:00-01:00', 'C2' => 'W1 01:00-03:00', 'P' => 'W1 03:00-03:15'],
            'unscheduled' => [],
            'end' => '03:15',
        ], $this->outcomeOf($schedule));
    }

    public function testDependencyCycleIsRejected(): void
    {
        $this->expectException(\DomainException::class);

        ProductionSchedule::earliest(
            [$this->manufacturing('A', ['W1' => 3600], ['B']), $this->manufacturing('B', ['W1' => 3600], ['A'])],
            [new ScheduleWorker('W1', 1, 0)],
            $this->at('00:00'),
        );
    }

    public function testDependencyOnAnUnknownJobIsRejected(): void
    {
        $this->expectException(\DomainException::class);

        ProductionSchedule::earliest(
            [$this->manufacturing('A', ['W1' => 3600], ['ghost'])],
            [new ScheduleWorker('W1', 1, 0)],
            $this->at('00:00'),
        );
    }

    public function testWorkerWithNegativeManufacturingSlotCountIsNotEligible(): void
    {
        $schedule = ProductionSchedule::earliest(
            [$this->manufacturing('M', ['W1' => 3300])],
            [new ScheduleWorker('W1', -1, 2)],
            $this->at('00:00'),
        );

        self::assertEquals([
            'assignments' => [],
            'unscheduled' => ['M' => Schedule::NO_ELIGIBLE_WORKER],
            'end' => null,
        ], $this->outcomeOf($schedule));
    }

    public function testWorkerWithNegativeReactionSlotCountIsNotEligible(): void
    {
        $schedule = ProductionSchedule::earliest(
            [new ScheduleJob('R', ActivityKind::Reaction, ['W1' => 4200])],
            [new ScheduleWorker('W1', 3, -2)],
            $this->at('00:00'),
        );

        self::assertEquals([
            'assignments' => [],
            'unscheduled' => ['R' => Schedule::NO_ELIGIBLE_WORKER],
            'end' => null,
        ], $this->outcomeOf($schedule));
    }

    public function testDependencyOnAnUnknownJobIsRejectedWithTheJobAndTheUnknownDependency(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Job "B" depends on the unknown job "ghost".');

        ProductionSchedule::earliest(
            [
                $this->manufacturing('A', ['W1' => 1200], ['B']),
                $this->manufacturing('B', ['W1' => 2400], ['ghost']),
            ],
            [new ScheduleWorker('W1', 1, 0)],
            $this->at('00:00'),
        );
    }

    /**
     * @param array<string, int> $durationSecondsByWorker
     * @param list<string>       $dependsOn
     */
    private function manufacturing(string $id, array $durationSecondsByWorker, array $dependsOn = []): ScheduleJob
    {
        return new ScheduleJob($id, ActivityKind::Manufacturing, $durationSecondsByWorker, $dependsOn);
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::DAY.'T'.$time.':00+00:00');
    }

    /**
     * @return array{assignments: array<string, string>, unscheduled: array<string, string>, end: ?string}
     */
    private function outcomeOf(Schedule $schedule): array
    {
        $assignments = [];
        foreach ($schedule->assignments as $assignment) {
            $assignments[$assignment->jobId] = sprintf(
                '%s %s-%s',
                $assignment->workerId,
                $assignment->start->format('H:i'),
                $assignment->end->format('H:i'),
            );
        }

        return [
            'assignments' => $assignments,
            'unscheduled' => $schedule->unscheduled,
            'end' => $schedule->end?->format('H:i'),
        ];
    }
}
