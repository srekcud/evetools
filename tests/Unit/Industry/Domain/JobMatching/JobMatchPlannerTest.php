<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain\JobMatching;

use App\Industry\Domain\JobMatching\AssignmentCandidate;
use App\Industry\Domain\JobMatching\CandidateJob;
use App\Industry\Domain\JobMatching\CandidateLine;
use App\Industry\Domain\JobMatching\CandidateProject;
use App\Industry\Domain\JobMatching\EligiblePair;
use App\Industry\Domain\JobMatching\JobAssignment;
use App\Industry\Domain\JobMatching\JobMatchPlan;
use App\Industry\Domain\JobMatching\JobMatchPlanner;
use App\Industry\Domain\JobMatching\JobToAssign;
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
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer(producerInstallerIds: [self::INSTALLER, self::OTHER_INSTALLER])],
            [$this->lineOfOlder(10), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Intent])], $plan->assignments);
    }

    public function testAProducerListWithoutTheInstallerGivesNoPointAndDoesNotExcludeTheProject(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(producerInstallerIds: [self::OTHER_INSTALLER])],
            [$this->lineOfOlder(10)],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $plan->assignments);
    }

    public function testALineReservedByTheInstallerGivesItTheIntentSignal(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->lineOfNewer(10, reservingInstallerIds: [self::OTHER_INSTALLER, self::INSTALLER])],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Intent])], $plan->assignments);
    }

    public function testAReservationByAnotherInstallerGivesNoPointSoTheJobStaysUnassigned(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->lineOfNewer(10, reservingInstallerIds: [self::OTHER_INSTALLER])],
            [$this->job(self::JOB, 5)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0),
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 0),
        ])], $plan->unassignedJobs);
    }

    public function testTheContainerHoldingTheOutputOfTheJobGivesItsProjectThePlaceSignal(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(containerItemIds: [self::OTHER_CONTAINER_ITEM]), $this->newer(containerItemIds: [self::CONTAINER_ITEM])],
            [$this->lineOfOlder(10), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 5, outputLocationId: self::CONTAINER_ITEM)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Place])], $plan->assignments);
    }

    public function testTheContainerHoldingTheBlueprintOfTheJobGivesItsProjectThePlaceSignal(): void
    {
        $plan = $this->planAllPairs(
            [$this->newer(containerItemIds: [self::OTHER_CONTAINER_ITEM, self::CONTAINER_ITEM])],
            [$this->lineOfNewer(10)],
            [$this->job(self::JOB, 5, blueprintLocationId: self::CONTAINER_ITEM)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Place])], $plan->assignments);
    }

    public function testThePlannedStructureOfALineEqualToTheStationOfTheJobGivesItTheStructureSignal(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10, plannedStructureId: self::OTHER_STRUCTURE), $this->lineOfNewer(10, plannedStructureId: self::PLANNED_STRUCTURE)],
            [$this->job(self::JOB, 5, stationId: self::PLANNED_STRUCTURE)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Structure])], $plan->assignments);
    }

    public function testRunsEqualToTheRemainderOfALineGiveItTheExactFitSignalButNeverDecideBetweenProjects(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(8), $this->lineOfNewer(5)],
            [$this->job(self::JOB, 5)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 1, [MatchSignal::ExactFit]),
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0),
        ])], $plan->unassignedJobs);
    }

    public function testExactFitOnTheOnlyProjectHoldingAFittingLineLinksTheJob(): void
    {
        $plan = $this->planAllPairs(
            [$this->older()],
            [$this->lineOfOlder(5)],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [MatchSignal::ExactFit])], $plan->assignments);
        self::assertSame([], $plan->unassignedJobs);
    }

    public function testASignalPairKeepsEverySignalInTheEnumOrder(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer(producerInstallerIds: [self::INSTALLER], containerItemIds: [self::CONTAINER_ITEM])],
            [$this->lineOfOlder(7), $this->lineOfNewer(7, plannedStructureId: self::PLANNED_STRUCTURE)],
            [$this->job(self::JOB, 7, outputLocationId: self::CONTAINER_ITEM, stationId: self::PLANNED_STRUCTURE)],
        );

        self::assertEquals([new JobAssignment(
            self::JOB,
            self::LINE_OF_NEWER,
            [MatchSignal::Intent, MatchSignal::Place, MatchSignal::Structure, MatchSignal::ExactFit],
        )], $plan->assignments);
    }

    public function testTheIntentOfAReservationBeatsPlaceStructureAndExactFitTogether(): void
    {
        $plan = $this->planAllPairs(
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

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Intent])], $plan->assignments);
    }

    public function testThePlaceSignalBeatsStructureAndExactFitTogether(): void
    {
        $plan = $this->planAllPairs(
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

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Place])], $plan->assignments);
    }

    public function testTheStructureSignalBeatsExactFit(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(5), $this->lineOfNewer(10, plannedStructureId: self::PLANNED_STRUCTURE)],
            [$this->job(self::JOB, 5, stationId: self::PLANNED_STRUCTURE)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Structure])], $plan->assignments);
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

    public function testAJobNothingDistinguishesIsUnassignedWithTheOldestProjectFirstWhateverTheInputOrder(): void
    {
        $plan = $this->planAllPairs(
            [$this->newer(), $this->older()],
            [$this->lineOfNewer(10), $this->lineOfOlder(10)],
            [$this->job(self::JOB, 5)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0),
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 0),
        ])], $plan->unassignedJobs);
    }

    public function testCandidatesOfProjectsCreatedAtTheSameTimeAreOrderedByTheirProjectId(): void
    {
        $createdAt = new \DateTimeImmutable('2026-03-01 08:00:00');

        $plan = $this->planAllPairs(
            [
                new CandidateProject(self::NEWER_PROJECT, $createdAt),
                new CandidateProject(self::OLDER_PROJECT, $createdAt),
            ],
            [$this->lineOfNewer(10), $this->lineOfOlder(10)],
            [$this->job(self::JOB, 5)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0),
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 0),
        ])], $plan->unassignedJobs);
    }

    public function testCandidatesOfProjectsCreatedAtTheSameTimeAreOrderedByProjectIdNotByLineId(): void
    {
        $createdAt = new \DateTimeImmutable('2026-03-01 08:00:00');
        $lineOfFirstProject = new CandidateLine('01900000-0000-7000-8000-0000000000b9', self::OLDER_PROJECT, 10);
        $lineOfSecondProject = new CandidateLine('01900000-0000-7000-8000-0000000000b1', self::NEWER_PROJECT, 10);

        $plan = $this->planAllPairs(
            [new CandidateProject(self::NEWER_PROJECT, $createdAt), new CandidateProject(self::OLDER_PROJECT, $createdAt)],
            [$lineOfSecondProject, $lineOfFirstProject],
            [$this->job(self::JOB, 5)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, $lineOfFirstProject->id, 0),
            $this->candidate(self::NEWER_PROJECT, $lineOfSecondProject->id, 0),
        ])], $plan->unassignedJobs);
    }

    public function testAJobWhoseBlueprintIsInTheContainerOfTwoProjectsIsUnassignedBecauseThePlacePointIsTied(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(null, [self::CONTAINER_ITEM]), $this->newer(null, [self::CONTAINER_ITEM])],
            [$this->lineOfOlder(10), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 5, blueprintLocationId: self::CONTAINER_ITEM)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 4, [MatchSignal::Place]),
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 4, [MatchSignal::Place]),
        ])], $plan->unassignedJobs);
    }

    public function testTheLineWithTheLowestIdOfAProjectTakesAJobNothingDistinguishes(): void
    {
        $plan = $this->planAllPairs(
            [$this->older()],
            [$this->secondLineOfOlder(10), $this->lineOfOlder(10)],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $plan->assignments);
    }

    public function testTheJobWithTheLowestIdIsLinkedFirstWhenTheRemainderFitsOnlyOne(): void
    {
        $plan = $this->planAllPairs(
            [$this->older()],
            [$this->lineOfOlder(10)],
            [$this->job(self::OTHER_JOB, 9), $this->job(self::JOB, 8)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $plan->assignments);
    }

    public function testEveryUnknownDataGivesNoPointAndExcludesNoPair(): void
    {
        $plan = $this->planAllPairs(
            [new CandidateProject(self::OLDER_PROJECT, new \DateTimeImmutable('2026-01-10'), null, null)],
            [new CandidateLine(self::LINE_OF_OLDER, self::OLDER_PROJECT, 10, null, [])],
            [new CandidateJob(self::JOB, 5, self::INSTALLER, null, null, null)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $plan->assignments);
    }

    public function testAJobWithoutLocationsGetsNoPlaceNorStructurePointFromAKnownContainerAndAPlannedStructure(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(containerItemIds: [self::CONTAINER_ITEM]), $this->newer(containerItemIds: [self::OTHER_CONTAINER_ITEM])],
            [$this->lineOfOlder(10, plannedStructureId: self::PLANNED_STRUCTURE), $this->lineOfNewer(10, plannedStructureId: self::OTHER_STRUCTURE)],
            [$this->job(self::JOB, 5)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0),
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 0),
        ])], $plan->unassignedJobs);
    }

    public function testAContainerThatHoldsNeitherLocationOfTheJobGivesNoPointAndExcludesNoPair(): void
    {
        $plan = $this->planAllPairs(
            [$this->newer(containerItemIds: [self::OTHER_CONTAINER_ITEM])],
            [$this->lineOfNewer(10)],
            [$this->job(self::JOB, 5, blueprintLocationId: self::CONTAINER_ITEM, outputLocationId: 60_003_760)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [])], $plan->assignments);
    }

    public function testAJobWhoseBlueprintIsInTheContainerOfAnotherProjectIsNotACandidateForThisOne(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(containerItemIds: [self::CONTAINER_ITEM]), $this->newer()],
            [$this->lineOfOlder(3), $this->lineOfNewer(5)],
            [
                $this->job(self::JOB, 5, blueprintLocationId: self::CONTAINER_ITEM),
                $this->job(self::OTHER_JOB, 5),
            ],
        );

        self::assertEquals([new JobAssignment(self::OTHER_JOB, self::LINE_OF_NEWER, [MatchSignal::ExactFit])], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 4, [MatchSignal::Place], true),
        ])], $plan->unassignedJobs);
    }

    public function testAJobWhoseBlueprintIsInTheContainerOfAProjectStaysWithItEvenWhenAnotherProjectHasAStrongerSignal(): void
    {
        $plan = $this->planAllPairs(
            [
                $this->older(containerItemIds: [self::CONTAINER_ITEM]),
                $this->newer(producerInstallerIds: [self::INSTALLER], containerItemIds: [self::OTHER_CONTAINER_ITEM]),
            ],
            [$this->lineOfOlder(10), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 5, blueprintLocationId: self::CONTAINER_ITEM, outputLocationId: self::OTHER_CONTAINER_ITEM)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [MatchSignal::Place])], $plan->assignments);
    }

    public function testAProjectWithAnUnknownScopeDoesNotExcludeTheJobFromOtherProjects(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(containerItemIds: null), $this->newer(containerItemIds: [self::CONTAINER_ITEM])],
            [$this->lineOfOlder(10), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 5, blueprintLocationId: 60_008_494)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0),
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 0),
        ])], $plan->unassignedJobs);
    }

    public function testAJobWithMoreRunsThanTheRemainderOfEveryLineIsNeverLinkedAndBecomesAnOversizedCandidate(): void
    {
        $plan = $this->planAllPairs(
            [$this->older()],
            [$this->lineOfOlder(5)],
            [$this->job(self::JOB, 6), $this->job(self::OTHER_JOB, 3)],
        );

        self::assertEquals([new JobAssignment(self::OTHER_JOB, self::LINE_OF_OLDER, [])], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0, [], true),
        ])], $plan->unassignedJobs);
    }

    public function testAJobOfSixRunsAndAJobOfFourRunsFittingTwoProjectsAreBothUnassignedWhenNothingDecides(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->lineOfNewer(6)],
            [$this->job(self::JOB, 6), $this->job(self::OTHER_JOB, 4)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([
            self::JOB => [
                $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 1, [MatchSignal::ExactFit]),
                $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0),
            ],
            self::OTHER_JOB => [
                $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0),
                $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 0),
            ],
        ], $this->candidatesByJobId($plan));
    }

    public function testSeveralJobsFillALineAndTheRemainderIsCountedForEachNextScore(): void
    {
        $plan = $this->planAllPairs(
            [$this->older()],
            [$this->lineOfOlder(10)],
            [$this->job(self::JOB, 6), $this->job(self::OTHER_JOB, 4), $this->job(self::THIRD_JOB, 4)],
        );

        self::assertEquals([
            new JobAssignment(self::JOB, self::LINE_OF_OLDER, []),
            new JobAssignment(self::OTHER_JOB, self::LINE_OF_OLDER, [MatchSignal::ExactFit]),
        ], $plan->assignments);
    }

    public function testAJobIsLinkedOnceToTheBestLineOfTheOnlyProjectHoldingFittingLines(): void
    {
        $plan = $this->planAllPairs(
            [$this->older()],
            [$this->lineOfOlder(10), $this->secondLineOfOlder(10, plannedStructureId: self::PLANNED_STRUCTURE)],
            [$this->job(self::JOB, 4, stationId: self::PLANNED_STRUCTURE)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::SECOND_LINE_OF_OLDER, [MatchSignal::Structure])], $plan->assignments);
        self::assertSame([], $plan->unassignedJobs);
    }

    public function testEveryFittingLineOfEveryProjectIsACandidateOfAnAmbiguousJob(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->secondLineOfOlder(10), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 4)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0),
            $this->candidate(self::OLDER_PROJECT, self::SECOND_LINE_OF_OLDER, 0),
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 0),
        ])], $plan->unassignedJobs);
    }

    public function testOnlyTheEligiblePairsAreConsidered(): void
    {
        $plan = JobMatchPlanner::plan(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 5)],
            [new EligiblePair(self::JOB, self::LINE_OF_NEWER)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [])], $plan->assignments);
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

        self::assertEquals($expected, $this->planAllPairs($projects, $lines, $jobs)->assignments);
        self::assertEquals($expected, $this->planAllPairs(array_reverse($projects), array_reverse($lines), array_reverse($jobs))->assignments);
        self::assertEquals($expected, $this->planAllPairs(
            [$projects[1], $projects[0]],
            [$lines[1], $lines[2], $lines[0]],
            [$jobs[2], $jobs[0], $jobs[1]],
        )->assignments);
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
        $expected = JobMatchPlanner::plan($projects, $lines, $jobs, $pairs);

        self::assertSame([], $expected->assignments);
        self::assertCount(2, $expected->unassignedJobs);
        self::assertEquals($expected, JobMatchPlanner::plan($projects, $lines, $jobs, array_reverse($pairs)));
        self::assertEquals($expected, JobMatchPlanner::plan(array_reverse($projects), array_reverse($lines), array_reverse($jobs), $pairs));
    }

    public function testAJobWithFittingLinesInOneProjectOnlyIsLinkedEvenWhenAnotherProjectLineIsTooSmall(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->lineOfNewer(3)],
            [$this->job(self::JOB, 5)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $plan->assignments);
        self::assertSame([], $plan->unassignedJobs);
    }

    public function testAProjectHoldingTheContainerOfTheOutputBeatsAProjectWithoutIt(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer(containerItemIds: [self::CONTAINER_ITEM])],
            [$this->lineOfOlder(5), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 5, outputLocationId: self::CONTAINER_ITEM)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Place])], $plan->assignments);
        self::assertSame([], $plan->unassignedJobs);
    }

    public function testPlaceAndStructureOnOneProjectBeatPlaceAloneOnTheOther(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(containerItemIds: [self::CONTAINER_ITEM]), $this->newer(containerItemIds: [self::CONTAINER_ITEM])],
            [$this->lineOfOlder(10), $this->lineOfNewer(10, plannedStructureId: self::PLANNED_STRUCTURE)],
            [$this->job(self::JOB, 5, outputLocationId: self::CONTAINER_ITEM, stationId: self::PLANNED_STRUCTURE)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_NEWER, [MatchSignal::Place, MatchSignal::Structure])], $plan->assignments);
        self::assertSame([], $plan->unassignedJobs);
    }

    public function testPlaceAndStructureOnBothProjectsLeaveTheJobUnassigned(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(containerItemIds: [self::CONTAINER_ITEM]), $this->newer(containerItemIds: [self::CONTAINER_ITEM])],
            [$this->lineOfOlder(10, plannedStructureId: self::PLANNED_STRUCTURE), $this->lineOfNewer(10, plannedStructureId: self::PLANNED_STRUCTURE)],
            [$this->job(self::JOB, 5, outputLocationId: self::CONTAINER_ITEM, stationId: self::PLANNED_STRUCTURE)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 6, [MatchSignal::Place, MatchSignal::Structure]),
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 6, [MatchSignal::Place, MatchSignal::Structure]),
        ])], $plan->unassignedJobs);
    }

    public function testIntentOnBothProjectsLeavesTheJobUnassignedAndTheExactFitLineFirst(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(producerInstallerIds: [self::INSTALLER]), $this->newer(producerInstallerIds: [self::INSTALLER])],
            [$this->lineOfOlder(10), $this->lineOfNewer(5)],
            [$this->job(self::JOB, 5)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 9, [MatchSignal::Intent, MatchSignal::ExactFit]),
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 8, [MatchSignal::Intent]),
        ])], $plan->unassignedJobs);
    }

    public function testTwoLinesOfTheSameProjectAreNotAmbiguousAndTheJobGoesToTheBestOne(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->secondLineOfOlder(4), $this->lineOfNewer(2)],
            [$this->job(self::JOB, 4)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::SECOND_LINE_OF_OLDER, [MatchSignal::ExactFit])], $plan->assignments);
        self::assertSame([], $plan->unassignedJobs);
    }

    public function testAnAmbiguousJobTakesNoRemainderSoAnotherJobFillsTheLine(): void
    {
        $plan = JobMatchPlanner::plan(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(5), $this->lineOfNewer(5)],
            [$this->job(self::JOB, 5), $this->job(self::OTHER_JOB, 5)],
            [
                new EligiblePair(self::JOB, self::LINE_OF_OLDER),
                new EligiblePair(self::JOB, self::LINE_OF_NEWER),
                new EligiblePair(self::OTHER_JOB, self::LINE_OF_OLDER),
            ],
        );

        self::assertEquals([new JobAssignment(self::OTHER_JOB, self::LINE_OF_OLDER, [MatchSignal::ExactFit])], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 1, [MatchSignal::ExactFit]),
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 1, [MatchSignal::ExactFit]),
        ])], $plan->unassignedJobs);
    }

    public function testAnAmbiguousJobBecomesLinkedWhenAnEarlierLinkOfThePassFillsTheLineOfTheOtherProject(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer(producerInstallerIds: [self::OTHER_INSTALLER])],
            [$this->lineOfOlder(5), $this->lineOfNewer(5)],
            [$this->job(self::JOB, 5), $this->job(self::OTHER_JOB, 5, installerId: self::OTHER_INSTALLER)],
        );

        self::assertEquals([
            new JobAssignment(self::OTHER_JOB, self::LINE_OF_NEWER, [MatchSignal::Intent, MatchSignal::ExactFit]),
            new JobAssignment(self::JOB, self::LINE_OF_OLDER, [MatchSignal::ExactFit]),
        ], $plan->assignments);
        self::assertSame([], $plan->unassignedJobs);
    }

    public function testCandidatesAreSortedBestScoreFirstWhateverTheProjectAge(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(containerItemIds: [self::CONTAINER_ITEM]), $this->newer(containerItemIds: [self::CONTAINER_ITEM])],
            [$this->lineOfOlder(10), $this->lineOfNewer(5)],
            [$this->job(self::JOB, 5, outputLocationId: self::CONTAINER_ITEM)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 5, [MatchSignal::Place, MatchSignal::ExactFit]),
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 4, [MatchSignal::Place]),
        ])], $plan->unassignedJobs);
    }

    public function testAJobTooLargeForTheLinesOfTwoProjectsGetsOneOversizedCandidatePerLine(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->lineOfNewer(6)],
            [$this->job(self::JOB, 12)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0, [], true),
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 0, [], true),
        ])], $plan->unassignedJobs);
    }

    public function testAJobTooLargeForTheOnlyLineOfASingleProjectIsNeverLinkedWhateverItsSignals(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(producerInstallerIds: [self::INSTALLER])],
            [$this->lineOfOlder(10)],
            [$this->job(self::JOB, 12)],
        );

        self::assertSame([], $plan->assignments);
        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 8, [MatchSignal::Intent], true),
        ])], $plan->unassignedJobs);
    }

    public function testOversizedCandidatesAreSortedWithTheStaticSignalsOfEachLine(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer(containerItemIds: [self::CONTAINER_ITEM])],
            [$this->lineOfOlder(10), $this->lineOfNewer(6)],
            [$this->job(self::JOB, 12, outputLocationId: self::CONTAINER_ITEM)],
        );

        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 4, [MatchSignal::Place], true),
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0, [], true),
        ])], $plan->unassignedJobs);
    }

    public function testAJobTooLargeForOneLineButFittingAnotherIsLinkedWithoutAnyOversizedCandidate(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(20), $this->lineOfNewer(6)],
            [$this->job(self::JOB, 12)],
        );

        self::assertEquals([new JobAssignment(self::JOB, self::LINE_OF_OLDER, [])], $plan->assignments);
        self::assertSame([], $plan->unassignedJobs);
    }

    public function testAnAmbiguousJobListsOnlyTheFittingPairsNotTheOversizedOnes(): void
    {
        $plan = $this->planAllPairs(
            [$this->older(), $this->newer()],
            [$this->lineOfOlder(10), $this->secondLineOfOlder(6), $this->lineOfNewer(10)],
            [$this->job(self::JOB, 8)],
        );

        self::assertEquals([new JobToAssign(self::JOB, [
            $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0),
            $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 0),
        ])], $plan->unassignedJobs);
    }

    public function testAJobWithoutAnyEligiblePairProducesNothing(): void
    {
        $plan = JobMatchPlanner::plan([$this->older()], [$this->lineOfOlder(10)], [$this->job(self::JOB, 5)], []);

        self::assertSame([], $plan->assignments);
        self::assertSame([], $plan->unassignedJobs);
    }

    public function testAJobTooLargeForALineWithoutRemainderProducesNothing(): void
    {
        $plan = $this->planAllPairs([$this->older()], [$this->lineOfOlder(0)], [$this->job(self::JOB, 5)]);

        self::assertSame([], $plan->assignments);
        self::assertSame([], $plan->unassignedJobs);
    }

    public function testTheUnassignedJobsDoNotDependOnTheOrderOfTheInputs(): void
    {
        $projects = [
            $this->older(),
            $this->newer(producerInstallerIds: [self::OTHER_INSTALLER]),
        ];
        $lines = [$this->lineOfOlder(10), $this->secondLineOfOlder(3), $this->lineOfNewer(10)];
        $jobs = [
            $this->job(self::JOB, 5),
            $this->job(self::OTHER_JOB, 5, installerId: self::OTHER_INSTALLER),
            $this->job(self::THIRD_JOB, 12),
        ];
        $expected = $this->planAllPairs($projects, $lines, $jobs);

        self::assertEquals([new JobAssignment(self::OTHER_JOB, self::LINE_OF_NEWER, [MatchSignal::Intent])], $expected->assignments);
        self::assertEquals([
            self::JOB => [
                $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 1, [MatchSignal::ExactFit]),
                $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0),
            ],
            self::THIRD_JOB => [
                $this->candidate(self::OLDER_PROJECT, self::LINE_OF_OLDER, 0, [], true),
                $this->candidate(self::OLDER_PROJECT, self::SECOND_LINE_OF_OLDER, 0, [], true),
                $this->candidate(self::NEWER_PROJECT, self::LINE_OF_NEWER, 0, [], true),
            ],
        ], $this->candidatesByJobId($expected));
        self::assertEquals($expected, $this->planAllPairs(array_reverse($projects), array_reverse($lines), array_reverse($jobs)));
        self::assertEquals($expected, $this->planAllPairs([$projects[1], $projects[0]], [$lines[1], $lines[2], $lines[0]], [$jobs[2], $jobs[0], $jobs[1]]));
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

    private function candidate(string $projectId, string $lineId, int $score, array $signals = [], bool $oversized = false): AssignmentCandidate
    {
        return new AssignmentCandidate($projectId, $lineId, $score, $signals, $oversized);
    }

    private function candidatesByJobId(JobMatchPlan $plan): array
    {
        $byJobId = [];
        foreach ($plan->unassignedJobs as $unassignedJob) {
            $byJobId[$unassignedJob->jobId] = $unassignedJob->candidates;
        }
        ksort($byJobId);

        return $byJobId;
    }

    private function planAllPairs(array $projects, array $lines, array $jobs): JobMatchPlan
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
