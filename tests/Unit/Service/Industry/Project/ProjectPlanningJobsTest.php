<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Service\Industry\Project\PlanningCharacterLane;
use App\Service\Industry\Project\PlanningJob;
use App\Service\Industry\Project\PlanningRealSpan;
use App\Service\Industry\Project\PlanningSpan;
use App\Service\Industry\Project\ProjectPlanning;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectPlanning::class)]
#[Group('issue-1312')]
final class ProjectPlanningJobsTest extends TestCase
{
    public function testJobsOfEveryLaneComeFlatSortedByPlannedStartThenLineIdWithTheUnplannedLast(): void
    {
        $planning = $this->planningOf([
            $this->lane('First', [
                $this->job('line-d', '2026-03-01T12:00:00+00:00'),
                $this->job('line-undated-b', null),
                $this->job('line-b', '2026-03-01T08:00:00+00:00'),
            ]),
            $this->lane('Second', [
                $this->job('line-a', '2026-03-01T08:00:00+00:00'),
                $this->job('line-undated-a', null),
                $this->job('line-c', '2026-03-01T10:00:00+00:00'),
            ]),
        ]);

        self::assertSame(
            ['line-a', 'line-b', 'line-c', 'line-d', 'line-undated-a', 'line-undated-b'],
            array_map(static fn (PlanningJob $job): string => $job->lineId, $planning->jobs()),
        );
    }

    public function testNoLaneGivesNoJob(): void
    {
        self::assertSame([], $this->planningOf([])->jobs());
    }

    /**
     * @param list<PlanningCharacterLane> $lanes
     */
    private function planningOf(array $lanes): ProjectPlanning
    {
        return new ProjectPlanning(new \DateTimeImmutable('2026-03-01T00:00:00+00:00'), true, null, null, null, null, $lanes, []);
    }

    /**
     * @param list<PlanningJob> $jobs
     */
    private function lane(string $characterName, array $jobs): PlanningCharacterLane
    {
        return new PlanningCharacterLane($characterName, false, null, null, null, $jobs);
    }

    private function job(string $lineId, ?string $plannedStart): PlanningJob
    {
        $planned = null === $plannedStart
            ? null
            : new PlanningSpan(new \DateTimeImmutable($plannedStart), new \DateTimeImmutable($plannedStart)->modify('+1 hour'));
        $real = null === $planned ? new PlanningRealSpan(new \DateTimeImmutable('2026-03-01T01:00:00+00:00'), new \DateTimeImmutable('2026-03-01T02:00:00+00:00'), 'done') : null;

        return new PlanningJob($lineId, 1, 'Type', 'manufacturing', 1, $planned, $real, null);
    }
}
