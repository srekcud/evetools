<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain\JobMatching;

use App\Industry\Domain\JobMatching\CandidateJob;
use App\Industry\Domain\JobMatching\CandidateLine;
use App\Industry\Domain\JobMatching\CandidateProject;
use App\Industry\Domain\JobMatching\EligiblePair;
use App\Industry\Domain\JobMatching\JobAssignment;
use App\Industry\Domain\JobMatching\JobMatchPlanner;
use App\Industry\Domain\JobMatching\MatchSignal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobMatchPlanner::class)]
#[CoversClass(MatchSignal::class)]
final class JobMatchPlannerTest extends TestCase
{
    private const string OLDER_PROJECT = '01900000-0000-7000-8000-0000000000a1';
    private const string NEWER_PROJECT = '01900000-0000-7000-8000-0000000000a2';
    private const string LINE_OF_OLDER = '01900000-0000-7000-8000-0000000000b1';
    private const string LINE_OF_NEWER = '01900000-0000-7000-8000-0000000000b2';
    private const string SECOND_LINE_OF_OLDER = '01900000-0000-7000-8000-0000000000b3';
    private const string INSTALLER = 'installer-1';
    private const string OTHER_INSTALLER = 'installer-2';
    private const int JOB = 7_361_001;
    private const int OTHER_JOB = 7_361_002;
    private const int THIRD_JOB = 7_361_003;
    private const int CONTAINER_ITEM = 1_040_000_000_321;
    private const int OTHER_CONTAINER_ITEM = 1_040_000_000_654;
    private const int PLANNED_STRUCTURE = 1_030_000_000_123;
    private const int OTHER_STRUCTURE = 1_030_000_000_456;

    public function testTheProducerCharactersOfAProjectGiveItTheIntentSignal(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(), $this->newer(producerInstallerIds: [self::INSTALLER, self::OTHER_INSTALLER])],
            [$this->lineOfOlder(10), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Intent])], $assignments);
    }

    public function testAProducerListWithoutTheInstallerGivesNoPointAndDoesNotExcludeTheProject(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(producerInstallerIds: [self::OTHER_INSTALLER])],
            [$this->lineOfOlder(10)],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $assignments);
    }

    public function testALineReservedByTheInstallerGivesItTheIntentSignal(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->lineOfNewer(10, reservingInstallerIds: [self::OTHER_INSTALLER, self::INSTALLER])],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Intent])], $assignments);
    }

    public function testAReservationByAnotherInstallerGivesNoPoint(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->lineOfNewer(10, reservingInstallerIds: [self::OTHER_INSTALLER])],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $assignments);
    }

    public function testTheContainerHoldingTheOutputOfTheJobGivesItsProjectThePlaceSignal(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(containerItemIds: [self::OTHER_CONTAINER_ITEM]), $this->newer(containerItemIds: [self::CONTAINER_ITEM])],
            [$this->lineOfOlder(10), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 5, outputLocationId: self::CONTAINER_ITEM)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Place])], $assignments);
    }

    public function testTheContainerHoldingTheBlueprintOfTheJobGivesItsProjectThePlaceSignal(): void
    {
        $assignments = $this->planAllPairs(
            [$this->newer(containerItemIds: [self::OTHER_CONTAINER_ITEM, self::CONTAINER_ITEM])],
            [$this->lineOfNewer(10)],
            [$this->job(self::JOB, 5, blueprintLocationId: self::CONTAINER_ITEM)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Place])], $assignments);
    }

    public function testThePlannedStructureOfALineEqualToTheStationOfTheJobGivesItTheStructureSignal(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10, plannedStructureId: self::OTHER_STRUCTURE), $this->lineOfNewer(10, plannedStructureId: self::PLANNED_STRUCTURE)],
            [$this->job(self::JOB, 5, stationId: self::PLANNED_STRUCTURE)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Structure])], $assignments);
    }

    public function testRunsEqualToTheRemainderOfALineGiveItTheExactFitSignal(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->lineOfNewer(5)],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::ExactFit])], $assignments);
    }

    public function testASignalPairKeepsEverySignalInTheEnumOrder(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(), $this->newer(producerInstallerIds: [self::INSTALLER], containerItemIds: [self::CONTAINER_ITEM])],
            [$this->lineOfOlder(7), $this->lineOfNewer(7, plannedStructureId: self::PLANNED_STRUCTURE)],
            [$this->job(self::JOB, 7, outputLocationId: self::CONTAINER_ITEM, stationId: self::PLANNED_STRUCTURE)],
        );

        self::assertEquals([new JobAssignment(
            self::JOB,
            self::LINE_OF_NEWER,
            [MatchSignal::Intent, MatchSignal::Place, MatchSignal::Structure, MatchSignal::ExactFit],
        )], $assignments);
    }

    public function testTheIntentOfAReservationBeatsPlaceStructureAndExactFitTogether(): void
    {
        $assignments = $this->planAllPairs(
            [
                $this->older(containerItemIds: [self::CONTAINER_ITEM]),
                $this->newer(containerItemIds: [self::OTHER_CONTAINER_ITEM]),
            ],
            [
                $this->lineOfOlder(5, plannedStructureId: self::PLANNED_STRUCTURE),
                $this->lineOfNewer(10, reservingInstallerIds: [self::INSTALLER]),
            ],
            [$this->job(self::JOB, 5, outputLocationId: self::CONTAINER_ITEM, stationId: self::PLANNED_STRUCTURE)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Intent])], $assignments);
    }

    public function testThePlaceSignalBeatsStructureAndExactFitTogether(): void
    {
        $assignments = $this->planAllPairs(
            [
                $this->older(containerItemIds: [self::OTHER_CONTAINER_ITEM]),
                $this->newer(containerItemIds: [self::CONTAINER_ITEM]),
            ],
            [
                $this->lineOfOlder(5, plannedStructureId: self::PLANNED_STRUCTURE),
                $this->lineOfNewer(10),
            ],
            [$this->job(self::JOB, 5, outputLocationId: self::CONTAINER_ITEM, stationId: self::PLANNED_STRUCTURE)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Place])], $assignments);
    }

    public function testTheStructureSignalBeatsExactFit(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(5), $this->lineOfNewer(10, plannedStructureId: self::PLANNED_STRUCTURE)],
            [$this->job(self::JOB, 5, stationId: self::PLANNED_STRUCTURE)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Structure])], $assignments);
    }

    public function testTheWeightsOfTheSignalsAreEightFourTwoOne(): void
    {
        self::assertSame(
            [8, 4, 2, 1],
            array_map(static fn (MatchSignal $signal): int => $signal->weight(), MatchSignal::cases()),
        );
        self::assertSame(
            ['intent', 'place', 'structure', 'exact_fit'],
            array_map(static fn (MatchSignal $signal): string => $signal->value, MatchSignal::cases()),
        );
    }

    public function testTheOldestProjectTakesAJobNothingDistinguishes(): void
    {
        $assignments = $this->planAllPairs(
            [$this->newer(), $this->older()],
            [$this->lineOfNewer(10), $this->lineOfOlder(10)],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $assignments);
    }

    public function testProjectsCreatedAtTheSameTimeAreOrderedByTheirId(): void
    {
        $createdAt = new \DateTimeImmutable('2026-03-01 08:00:00');

        $assignments = $this->planAllPairs(
            [
                new CandidateProject(self::NEWER_PROJECT, $createdAt),
                new CandidateProject(self::OLDER_PROJECT, $createdAt),
            ],
            [$this->lineOfNewer(10), $this->lineOfOlder(10)],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $assignments);
    }

    public function testProjectsCreatedAtTheSameTimeAreOrderedByProjectIdNotByLineId(): void
    {
        $createdAt = new \DateTimeImmutable('2026-03-01 08:00:00');
        $lineOfFirstProject = new CandidateLine('01900000-0000-7000-8000-0000000000b9', self::OLDER_PROJECT, 10);
        $lineOfSecondProject = new CandidateLine('01900000-0000-7000-8000-0000000000b1', self::NEWER_PROJECT, 10);

        $assignments = $this->planAllPairs(
            [new CandidateProject(self::NEWER_PROJECT, $createdAt), new CandidateProject(self::OLDER_PROJECT, $createdAt)],
            [$lineOfSecondProject, $lineOfFirstProject],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, $lineOfFirstProject->id, [])], $assignments);
    }

    public function testAJobWhoseBlueprintIsInTheContainerOfTwoProjectsGoesToTheOldestOne(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(null, [self::CONTAINER_ITEM]), $this->newer(null, [self::CONTAINER_ITEM])],
            [$this->lineOfOlder(10), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 5, blueprintLocationId: self::CONTAINER_ITEM)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [MatchSignal::Place])], $assignments);
    }

    public function testTheLineWithTheLowestIdOfAProjectTakesAJobNothingDistinguishes(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older()],
            [$this->secondLineOfOlder(10), $this->lineOfOlder(10)],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $assignments);
    }

    public function testTheJobWithTheLowestIdIsLinkedFirstWhenTheRemainderFitsOnlyOne(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older()],
            [$this->lineOfOlder(10)],
            [$this->job(self::OTHER_JOB, 9), $this->job(self::JOB, 8)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $assignments);
    }

    public function testEveryUnknownDataGivesNoPointAndExcludesNoPair(): void
    {
        $assignments = $this->planAllPairs(
            [new CandidateProject(self::OLDER_PROJECT, new \DateTimeImmutable('2026-01-10'), null, null)],
            [new CandidateLine(self::LINE_OF_OLDER, self::OLDER_PROJECT, 10, null, [])],
            [new CandidateJob(self::JOB, 5, self::INSTALLER, null, null, null)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $assignments);
    }

    public function testAJobWithoutLocationsGetsNoPlaceNorStructurePointFromAKnownContainerAndAPlannedStructure(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(containerItemIds: [self::CONTAINER_ITEM]), $this->newer(containerItemIds: [self::OTHER_CONTAINER_ITEM])],
            [$this->lineOfOlder(10, plannedStructureId: self::PLANNED_STRUCTURE), $this->lineOfNewer(10, plannedStructureId: self::OTHER_STRUCTURE)],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $assignments);
    }

    public function testAContainerThatHoldsNeitherLocationOfTheJobGivesNoPointAndExcludesNoPair(): void
    {
        $assignments = $this->planAllPairs(
            [$this->newer(containerItemIds: [self::OTHER_CONTAINER_ITEM])],
            [$this->lineOfNewer(10)],
            [$this->job(self::JOB, 5, blueprintLocationId: self::CONTAINER_ITEM, outputLocationId: 60_003_760)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [])], $assignments);
    }

    public function testAJobWhoseBlueprintIsInTheContainerOfAnotherProjectIsNotEligibleForThisOne(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(containerItemIds: [self::CONTAINER_ITEM]), $this->newer()],
            [$this->lineOfOlder(3), $this->lineOfNewer(5)],
            [
                $this->job(self::JOB, 5, blueprintLocationId: self::CONTAINER_ITEM),
                $this->job(self::OTHER_JOB, 5),
            ],
        );

        self::assertEquals([new JobAssignment(self::OTHER_JOB, self::LINE_OF_NEWER, [MatchSignal::ExactFit])], $assignments);
    }

    public function testAJobWhoseBlueprintIsInTheContainerOfAProjectStaysWithItEvenWhenAnotherProjectHasAStrongerSignal(): void
    {
        $assignments = $this->planAllPairs(
            [
                $this->older(containerItemIds: [self::CONTAINER_ITEM]),
                $this->newer(producerInstallerIds: [self::INSTALLER], containerItemIds: [self::OTHER_CONTAINER_ITEM]),
            ],
            [$this->lineOfOlder(10), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 5, blueprintLocationId: self::CONTAINER_ITEM, outputLocationId: self::OTHER_CONTAINER_ITEM)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [MatchSignal::Place])], $assignments);
    }

    public function testAProjectWithAnUnknownScopeDoesNotExcludeTheJobFromOtherProjects(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(containerItemIds: null), $this->newer(containerItemIds: [self::CONTAINER_ITEM])],
            [$this->lineOfOlder(10), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 5, blueprintLocationId: 60_008_494)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $assignments);
    }

    public function testAJobWithMoreRunsThanTheRemainderOfEveryLineIsLeftOut(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older()],
            [$this->lineOfOlder(5)],
            [$this->job(self::JOB, 6), $this->job(self::OTHER_JOB, 3)],
        );

        self::assertEquals([new JobAssignment(self::OTHER_JOB, self::LINE_OF_OLDER, [])], $assignments);
    }

    public function testAJobOfSixRunsAndAJobOfFourRunsFillTheExactLineThenTheLargerLine(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->lineOfNewer(6)],
            [$this->job(self::JOB, 6), $this->job(self::OTHER_JOB, 4)],
        );

        self::assertEquals([
            new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::ExactFit]),
            new JobAssignment(self::OTHER_JOB, self::LINE_OF_OLDER, []),
        ], $assignments);
    }

    public function testSeveralJobsFillALineAndTheRemainderIsCountedForEachNextScore(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older()],
            [$this->lineOfOlder(10)],
            [$this->job(self::JOB, 6), $this->job(self::OTHER_JOB, 4), $this->job(self::THIRD_JOB, 4)],
        );

        self::assertEquals([
            new JobAssignment(self::JOB, self::LINE_OF_OLDER, []),
            new JobAssignment(self::OTHER_JOB, self::LINE_OF_OLDER, [MatchSignal::ExactFit]),
        ], $assignments);
    }

    public function testAJobIsLinkedOnceEvenWhenItIsEligibleForSeveralLines(): void
    {
        $assignments = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->secondLineOfOlder(10), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 4)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $assignments);
    }

    public function testOnlyTheEligiblePairsAreConsidered(): void
    {
        $assignments = JobMatchPlanner::plan(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 5)],
            [new EligiblePair(self::JOB, self::LINE_OF_NEWER)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [])], $assignments);
    }

    public function testTheResultDoesNotDependOnTheOrderOfTheInputs(): void
    {
        $projects = [
            $this->older(containerItemIds: [self::CONTAINER_ITEM]),
            $this->newer(producerInstallerIds: [self::OTHER_INSTALLER]),
        ];
        $lines = [
            $this->lineOfOlder(10),
            $this->secondLineOfOlder(6, plannedStructureId: self::PLANNED_STRUCTURE),
            $this->lineOfNewer(8),
        ];
        $jobs = [
            $this->job(self::JOB, 6, stationId: self::PLANNED_STRUCTURE),
            $this->job(self::OTHER_JOB, 4, outputLocationId: self::CONTAINER_ITEM),
            $this->job(self::THIRD_JOB, 8, installerId: self::OTHER_INSTALLER),
        ];
        $expected = [
            new JobAssignment(self::THIRD_JOB, self::LINE_OF_NEWER, [MatchSignal::Intent, MatchSignal::ExactFit]),
            new JobAssignment(self::OTHER_JOB, self::LINE_OF_OLDER, [MatchSignal::Place]),
            new JobAssignment(self::JOB, self::SECOND_LINE_OF_OLDER, [MatchSignal::Structure, MatchSignal::ExactFit]),
        ];

        self::assertEquals($expected, $this->planAllPairs($projects, $lines, $jobs));
        self::assertEquals($expected, $this->planAllPairs(array_reverse($projects), array_reverse($lines), array_reverse($jobs)));
        self::assertEquals($expected, $this->planAllPairs(
            [$projects[1], $projects[0]],
            [$lines[1], $lines[2], $lines[0]],
            [$jobs[2], $jobs[0], $jobs[1]],
        ));
    }

    public function testTheResultDoesNotDependOnTheOrderOfTheEligiblePairs(): void
    {
        $projects = [$this->older(), $this->newer()];
        $lines = [$this->lineOfOlder(10), $this->lineOfNewer(6)];
        $jobs = [$this->job(self::JOB, 6), $this->job(self::OTHER_JOB, 4)];
        $pairs = [
            new EligiblePair(self::JOB, self::LINE_OF_OLDER),
            new EligiblePair(self::JOB, self::LINE_OF_NEWER),
            new EligiblePair(self::OTHER_JOB, self::LINE_OF_OLDER),
            new EligiblePair(self::OTHER_JOB, self::LINE_OF_NEWER),
        ];
        $expected = [
            new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::ExactFit]),
            new JobAssignment(self::OTHER_JOB, self::LINE_OF_OLDER, []),
        ];

        self::assertEquals($expected, JobMatchPlanner::plan($projects, $lines, $jobs, $pairs));
        self::assertEquals($expected, JobMatchPlanner::plan($projects, $lines, $jobs, array_reverse($pairs)));
    }

    private function older(?array $producerInstallerIds = null, ?array $containerItemIds = null): CandidateProject
    {
        return new CandidateProject(self::OLDER_PROJECT, new \DateTimeImmutable('2026-01-10 09:00:00'), $producerInstallerIds, $containerItemIds);
    }

    private function newer(?array $producerInstallerIds = null, ?array $containerItemIds = null): CandidateProject
    {
        return new CandidateProject(self::NEWER_PROJECT, new \DateTimeImmutable('2026-02-20 17:30:00'), $producerInstallerIds, $containerItemIds);
    }

    private function lineOfOlder(int $remainingRuns, ?int $plannedStructureId = null, array $reservingInstallerIds = []): CandidateLine
    {
        return new CandidateLine(self::LINE_OF_OLDER, self::OLDER_PROJECT, $remainingRuns, $plannedStructureId, $reservingInstallerIds);
    }

    private function secondLineOfOlder(int $remainingRuns, ?int $plannedStructureId = null): CandidateLine
    {
        return new CandidateLine(self::SECOND_LINE_OF_OLDER, self::OLDER_PROJECT, $remainingRuns, $plannedStructureId);
    }

    private function lineOfNewer(int $remainingRuns, ?int $plannedStructureId = null, array $reservingInstallerIds = []): CandidateLine
    {
        return new CandidateLine(self::LINE_OF_NEWER, self::NEWER_PROJECT, $remainingRuns, $plannedStructureId, $reservingInstallerIds);
    }

    private function job(
        int $jobId,
        int $runs,
        string $installerId = self::INSTALLER,
        ?int $blueprintLocationId = null,
        ?int $outputLocationId = null,
        ?int $stationId = null,
    ): CandidateJob {
        return new CandidateJob($jobId, $runs, $installerId, $blueprintLocationId, $outputLocationId, $stationId);
    }

    private function planAllPairs(array $projects, array $lines, array $jobs): array
    {
        $pairs = [];
        foreach ($jobs as $job) {
            foreach ($lines as $line) {
                $pairs[] = new EligiblePair($job->jobId, $line->id);
            }
        }

        return JobMatchPlanner::plan($projects, $lines, $jobs, $pairs);
    }
}
