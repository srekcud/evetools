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
    private const int ACTIVITY_INVENTION = 8;
    private const int ACTIVITY_REACTION = 9;
    private const int ACTIVITY_REVERSE_ENGINEERING = 11;

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

    public function testKeepsManufacturingReactionAndReverseEngineeringActivities(): void
    {
        $this->createJob(startDate: '2026-03-01', activityId: self::ACTIVITY_MANUFACTURING);
        $this->createJob(startDate: '2026-03-02', activityId: self::ACTIVITY_REACTION);
        $this->createJob(startDate: '2026-03-03', activityId: self::ACTIVITY_REVERSE_ENGINEERING);
        $this->flushAndClear();

        $activities = array_map(static fn (CachedIndustryJob $job) => $job->getActivityId(), $this->find());

        self::assertSame([self::ACTIVITY_MANUFACTURING, self::ACTIVITY_REACTION, self::ACTIVITY_REVERSE_ENGINEERING], $activities);
    }

    public function testExcludesInventionJobs(): void
    {
        // CARACTÉRISATION : comportement actuel, l'invention (activité 8) n'est pas matchée, cf. issue #60
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

    public function testReturnsCancelledJobs(): void
    {
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #4
        $this->createJob(status: 'cancelled');
        $this->flushAndClear();

        $jobs = $this->find();

        self::assertCount(1, $jobs);
        self::assertSame('cancelled', $jobs[0]->getStatus());
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
