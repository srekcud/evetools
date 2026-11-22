<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain\JobMatching;

use App\Industry\Domain\JobMatching\AssignmentCandidate;
use App\Industry\Domain\JobMatching\CandidateJob;
use App\Industry\Domain\JobMatching\CandidateLine;
use App\Industry\Domain\JobMatching\CandidateProject;
use App\Industry\Domain\JobMatching\EligiblePair;
use App\Industry\Domain\JobMatching\JobAssignment;
use App\Industry\Domain\JobMatching\JobMatchPlanner;
use App\Industry\Domain\JobMatching\JobToAssign;
use App\Industry\Domain\JobMatching\MatchSignal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobMatchPlanner::class)]
#[CoversClass(MatchSignal::class)]
final class JobMatchPlannerCandidateSelectionTest extends TestCase
{
    private const string INSTALLER = 'installer-4';
    private const int STATION = 60_003_760;
    private const int BLUEPRINT_CONTAINER_ITEM = 1_040_000_000_777;
    private const int OTHER_CONTAINER_ITEM = 1_040_000_000_888;

    public function testTheUnassignedJobsAreSortedByAscendingJobIdWhateverTheOrderTheyWereFound(): void
    {
        $older = new CandidateProject('project-older', new \DateTimeImmutable('2026-01-10 09:00:00'));
        $newer = new CandidateProject('project-newer', new \DateTimeImmutable('2026-02-20 17:30:00'));
        $lines = [new CandidateLine('line-older', 'project-older', 10), new CandidateLine('line-newer', 'project-newer', 10)];
        $ambiguousJob = new CandidateJob(7_361_002, 5, self::INSTALLER);
        $firstOversizedJob = new CandidateJob(7_361_001, 12, self::INSTALLER);
        $lastOversizedJob = new CandidateJob(7_361_003, 14, self::INSTALLER);
        $jobs = [$ambiguousJob, $firstOversizedJob, $lastOversizedJob];

        $plan = JobMatchPlanner::plan([$older, $newer], $lines, $jobs, $this->everyPair($jobs, $lines));

        self::assertSame([], $plan->assignments);
        self::assertSame([7_361_001, 7_361_002, 7_361_003], array_map(static fn (JobToAssign $job): int => $job->jobId, $plan->unassignedJobs));
        self::assertTrue($plan->unassignedJobs[0]->candidates[0]->oversized);
        self::assertFalse($plan->unassignedJobs[1]->candidates[0]->oversized);
        self::assertTrue($plan->unassignedJobs[2]->candidates[0]->oversized);
    }

    public function testAProjectDecisiveScoreIsTheBestStaticScoreOfItsLinesWhateverTheOrderOfThePairs(): void
    {
        $older = new CandidateProject('project-older', new \DateTimeImmutable('2026-01-10 09:00:00'));
        $newer = new CandidateProject('project-newer', new \DateTimeImmutable('2026-02-20 17:30:00'));
        $lines = [
            new CandidateLine('line-older-structure', 'project-older', 10, self::STATION),
            new CandidateLine('line-older-plain', 'project-older', 10),
            new CandidateLine('line-newer', 'project-newer', 10),
        ];
        $job = new CandidateJob(7_362_001, 5, self::INSTALLER, stationId: self::STATION);
        $pairs = [
            new EligiblePair(7_362_001, 'line-older-structure'),
            new EligiblePair(7_362_001, 'line-older-plain'),
            new EligiblePair(7_362_001, 'line-newer'),
        ];

        $plan = JobMatchPlanner::plan([$older, $newer], $lines, [$job], $pairs);

        self::assertEquals([new JobAssignment(7_362_001, 'line-older-structure', [MatchSignal::Structure])], $plan->assignments);
        self::assertSame([], $plan->unassignedJobs);
    }

    public function testTwoPairsWhoseJobIdAndLineIdConcatenateToTheSameTextAreBothKept(): void
    {
        $older = new CandidateProject('project-older', new \DateTimeImmutable('2026-01-10 09:00:00'));
        $newer = new CandidateProject('project-newer', new \DateTimeImmutable('2026-02-20 17:30:00'));
        $lines = [new CandidateLine('3', 'project-older', 10), new CandidateLine('23', 'project-newer', 10)];
        $jobs = [new CandidateJob(12, 5, self::INSTALLER), new CandidateJob(1, 6, self::INSTALLER)];
        $pairs = [new EligiblePair(12, '3'), new EligiblePair(1, '23')];

        $plan = JobMatchPlanner::plan([$older, $newer], $lines, $jobs, $pairs);

        self::assertEquals([
            new JobAssignment(12, '3', []),
            new JobAssignment(1, '23', []),
        ], $plan->assignments);
        self::assertSame([], $plan->unassignedJobs);
    }

    public function testAnEligiblePairListedTwiceIsOneCandidateAndTheNextPairIsStillRead(): void
    {
        $older = new CandidateProject('project-older', new \DateTimeImmutable('2026-01-10 09:00:00'));
        $newer = new CandidateProject('project-newer', new \DateTimeImmutable('2026-02-20 17:30:00'));
        $lines = [new CandidateLine('line-older', 'project-older', 10), new CandidateLine('line-newer', 'project-newer', 10)];
        $job = new CandidateJob(7_363_001, 5, self::INSTALLER);
        $pairs = [
            new EligiblePair(7_363_001, 'line-older'),
            new EligiblePair(7_363_001, 'line-older'),
            new EligiblePair(7_363_001, 'line-newer'),
        ];

        $plan = JobMatchPlanner::plan([$older, $newer], $lines, [$job], $pairs);

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(7_363_001, [
            new AssignmentCandidate('project-older', 'line-older', 0, [], false),
            new AssignmentCandidate('project-newer', 'line-newer', 0, [], false),
        ])], $plan->unassignedJobs);
    }

    public function testAProjectNotHoldingTheBlueprintDoesNotHideTheContainerOfAProjectListedAfterIt(): void
    {
        $older = new CandidateProject('project-older', new \DateTimeImmutable('2026-01-10 09:00:00'), [self::INSTALLER], [self::OTHER_CONTAINER_ITEM]);
        $newer = new CandidateProject('project-newer', new \DateTimeImmutable('2026-02-20 17:30:00'), null, [self::BLUEPRINT_CONTAINER_ITEM]);
        $lines = [new CandidateLine('line-older', 'project-older', 10), new CandidateLine('line-newer', 'project-newer', 10)];
        $job = new CandidateJob(7_364_001, 5, self::INSTALLER, blueprintLocationId: self::BLUEPRINT_CONTAINER_ITEM);

        $plan = JobMatchPlanner::plan([$older, $newer], $lines, [$job], $this->everyPair([$job], $lines));

        self::assertEquals([new JobAssignment(7_364_001, 'line-newer', [MatchSignal::Place])], $plan->assignments);
        self::assertSame([], $plan->unassignedJobs);
    }

    public function testAnOlderProjectComesBeforeANewerOneEvenWhenItsProjectIdSortsAfter(): void
    {
        $older = new CandidateProject('project-z9', new \DateTimeImmutable('2026-01-10 09:00:00'));
        $newer = new CandidateProject('project-a1', new \DateTimeImmutable('2026-02-20 17:30:00'));
        $lines = [new CandidateLine('line-a1', 'project-a1', 10), new CandidateLine('line-z9', 'project-z9', 10)];
        $job = new CandidateJob(7_365_001, 5, self::INSTALLER);

        $plan = JobMatchPlanner::plan([$newer, $older], $lines, [$job], $this->everyPair([$job], $lines));

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(7_365_001, [
            new AssignmentCandidate('project-z9', 'line-z9', 0, [], false),
            new AssignmentCandidate('project-a1', 'line-a1', 0, [], false),
        ])], $plan->unassignedJobs);
    }

    private function everyPair(array $jobs, array $lines): array
    {
        $pairs = [];
        foreach ($jobs as $job) {
            foreach ($lines as $line) {
                $pairs[] = new EligiblePair($job->jobId, $line->id);
            }
        }

        return $pairs;
    }
}
