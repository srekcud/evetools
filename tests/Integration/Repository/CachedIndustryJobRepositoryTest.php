<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Repository\CachedIndustryJobRepository;
use App\Tests\Integration\IntegrationTestCase;

final class CachedIndustryJobRepositoryTest extends IntegrationTestCase
{
    private const int BLUEPRINT_TYPE_ID = 1_000_001;
    private const int OTHER_BLUEPRINT_TYPE_ID = 1_000_002;
    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ACTIVITY_RESEARCH_TIME = 3;
    private const int ACTIVITY_RESEARCH_MATERIAL = 4;
    private const int ACTIVITY_COPYING = 5;
    private const int ACTIVITY_REVERSE_ENGINEERING = 7;
    private const int ACTIVITY_INVENTION = 8;
    // ESI /characters/{id}/industry/jobs/ reports reactions as activity 9 (observed on every
    // cached reaction job), while the SDE stores the same blueprints under activity 11.
    private const int ACTIVITY_REACTION_ESI = 9;
    private const int ACTIVITY_REACTION_SDE = 11;

    private CachedIndustryJobRepository $repository;
    private Character $character;
    private int $nextJobId = 500_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(CachedIndustryJobRepository::class);
        $this->character = $this->createCharacter();
    }

    public function testReturnsMatchingJobsOrderedByStartDateAscending(): void
    {
        $this->createJob(startDate: '2026-03-03', runs: 3);
        $this->createJob(startDate: '2026-03-01', runs: 1);
        $this->createJob(startDate: '2026-03-02', runs: 2);
        $this->flushAndClear();

        self::assertSame([1, 2, 3], $this->runsOf($this->find()));
    }

    public function testKeepsOnlyJobsOfTheRequestedBlueprint(): void
    {
        $this->createJob(runs: 1);
        $this->createJob(runs: 2, blueprintTypeId: self::OTHER_BLUEPRINT_TYPE_ID);
        $this->flushAndClear();

        self::assertSame([1], $this->runsOf($this->find()));
    }

    public function testKeepsManufacturingAndReactionActivities(): void
    {
        $this->createJob(startDate: '2026-03-01', activityId: self::ACTIVITY_MANUFACTURING);
        $this->createJob(startDate: '2026-03-02', activityId: self::ACTIVITY_REACTION_ESI);
        $this->createJob(startDate: '2026-03-03', activityId: self::ACTIVITY_REACTION_SDE);
        $this->flushAndClear();

        self::assertSame(
            [self::ACTIVITY_MANUFACTURING, self::ACTIVITY_REACTION_ESI, self::ACTIVITY_REACTION_SDE],
            $this->activitiesOf($this->find()),
        );
    }

    public function testExcludesResearchCopyingInventionAndReverseEngineeringJobsOfTheSameBlueprint(): void
    {
        // Invention and copy jobs are installed on the T1 blueprint the manufacturing step uses:
        // they must not cover manufacturing runs. Projects have no invention step (cf. issue #60).
        $this->createJob(activityId: self::ACTIVITY_RESEARCH_TIME);
        $this->createJob(activityId: self::ACTIVITY_RESEARCH_MATERIAL);
        $this->createJob(activityId: self::ACTIVITY_COPYING);
        $this->createJob(activityId: self::ACTIVITY_REVERSE_ENGINEERING);
        $this->createJob(activityId: self::ACTIVITY_INVENTION);
        $this->flushAndClear();

        self::assertSame([], $this->find());
    }

    public function testKeepsOnlyJobsOfTheGivenCharacters(): void
    {
        $otherCharacter = $this->createCharacter(name: 'Other Pilot');
        $this->createJob(runs: 1);
        $this->createJob(runs: 2, character: $otherCharacter);
        $this->flushAndClear();

        self::assertSame([1], $this->runsOf($this->find()));
        self::assertSame([2], $this->runsOf($this->find(characters: [$otherCharacter])));
    }

    public function testStartedAfterFilterIsInclusive(): void
    {
        $this->createJob(startDate: '2026-03-01 11:59:59', runs: 1);
        $this->createJob(startDate: '2026-03-01 12:00:00', runs: 2);
        $this->createJob(startDate: '2026-03-02 08:00:00', runs: 3);
        $this->flushAndClear();

        $jobs = $this->find(startedAfter: new \DateTimeImmutable('2026-03-01 12:00:00'));

        self::assertSame([2, 3], $this->runsOf($jobs));
    }

    public function testTargetRunsFilterKeepsExactMatchesOnly(): void
    {
        $this->createJob(startDate: '2026-03-01', runs: 9);
        $this->createJob(startDate: '2026-03-02', runs: 10);
        $this->createJob(startDate: '2026-03-03', runs: 11);
        $this->flushAndClear();

        self::assertSame([10], $this->runsOf($this->find(targetRuns: 10)));
    }

    public function testExcludesCancelledJobs(): void
    {
        // A cancelled job never produced anything, cf. issue #4
        $this->createJob(runs: 4, status: 'cancelled');
        $this->createJob(runs: 6, status: 'active');
        $this->flushAndClear();

        self::assertSame([6], $this->runsOf($this->find()));
    }

    public function testKeepsActiveReadyAndDeliveredJobs(): void
    {
        $this->createJob(startDate: '2026-03-01', runs: 1, status: 'active');
        $this->createJob(startDate: '2026-03-02', runs: 2, status: 'ready');
        $this->createJob(startDate: '2026-03-03', runs: 3, status: 'delivered');
        $this->flushAndClear();

        self::assertSame([1, 2, 3], $this->runsOf($this->find()));
    }

    /**
     * @param list<Character>|null $characters
     *
     * @return CachedIndustryJob[]
     */
    private function find(?array $characters = null, ?int $targetRuns = null, ?\DateTimeImmutable $startedAfter = null): array
    {
        // Same argument shape as IndustryJobMatcher: a list of character UUIDs.
        $characterIds = array_map(static fn (Character $c) => $c->getId(), $characters ?? [$this->character]);

        return $this->repository->findManufacturingJobsByBlueprint(self::BLUEPRINT_TYPE_ID, $characterIds, $targetRuns, $startedAfter);
    }

    /**
     * @param CachedIndustryJob[] $jobs
     *
     * @return list<int>
     */
    private function runsOf(array $jobs): array
    {
        return array_values(array_map(static fn (CachedIndustryJob $job) => $job->getRuns(), $jobs));
    }

    /**
     * @param CachedIndustryJob[] $jobs
     *
     * @return list<int>
     */
    private function activitiesOf(array $jobs): array
    {
        return array_values(array_map(static fn (CachedIndustryJob $job) => $job->getActivityId(), $jobs));
    }

    private function createJob(
        string $startDate = '2026-03-01 12:00:00',
        int $runs = 1,
        int $activityId = self::ACTIVITY_MANUFACTURING,
        int $blueprintTypeId = self::BLUEPRINT_TYPE_ID,
        string $status = 'active',
        ?Character $character = null,
    ): void {
        $start = new \DateTimeImmutable($startDate);

        $job = (new CachedIndustryJob())
            ->setCharacter($character ?? $this->character)
            ->setJobId($this->nextJobId++)
            ->setActivityId($activityId)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setProductTypeId($blueprintTypeId + 1)
            ->setRuns($runs)
            ->setCost(1_000.0)
            ->setStatus($status)
            ->setStartDate($start)
            ->setEndDate($start->modify('+1 day'));

        $this->em->persist($job);
    }
}
