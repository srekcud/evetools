<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\UnassignedJob;
use App\Entity\User;
use App\Repository\UnassignedJobRepository;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-1247')]
final class UnassignedJobRepositoryTest extends IntegrationTestCase
{
    private const int FIRST_ESI_JOB_ID = 724_700_001;
    private const int SECOND_ESI_JOB_ID = 724_700_002;
    private const int THIRD_ESI_JOB_ID = 724_700_003;
    private const int FOURTH_ESI_JOB_ID = 724_800_001;
    private const int FIFTH_ESI_JOB_ID = 724_800_002;
    private const int SIXTH_ESI_JOB_ID = 724_800_003;
    private const string OLDER_PROJECT_ID = '01900000-0000-7000-8000-0000000000a1';
    private const string NEWER_PROJECT_ID = '01900000-0000-7000-8000-0000000000a2';
    private const string LINE_OF_OLDER_ID = '01900000-0000-7000-8000-0000000000b1';
    private const string LINE_OF_NEWER_ID = '01900000-0000-7000-8000-0000000000b2';

    private UnassignedJobRepository $repository;
    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(UnassignedJobRepository::class);
        $this->character = $this->createCharacter();
    }

    public function testFindByEsiJobIdsReturnsTheRowsOfTheRequestedJobsIndexedByEsiJobId(): void
    {
        $candidates = [
            ['projectId' => self::OLDER_PROJECT_ID, 'lineId' => self::LINE_OF_OLDER_ID, 'score' => 5, 'signals' => ['place', 'exact_fit'], 'oversized' => false],
            ['projectId' => self::NEWER_PROJECT_ID, 'lineId' => self::LINE_OF_NEWER_ID, 'score' => 0, 'signals' => [], 'oversized' => true],
        ];
        $this->givenUnassignedJob(self::FIRST_ESI_JOB_ID, $candidates);
        $this->givenUnassignedJob(self::SECOND_ESI_JOB_ID, []);
        $this->givenUnassignedJob(self::THIRD_ESI_JOB_ID, []);
        $this->flushAndClear();

        $found = $this->repository->findByEsiJobIds([self::FIRST_ESI_JOB_ID, self::SECOND_ESI_JOB_ID, 724_799_999]);

        self::assertSame([self::FIRST_ESI_JOB_ID, self::SECOND_ESI_JOB_ID], array_keys($found));
        self::assertSame($candidates, $found[self::FIRST_ESI_JOB_ID]->getCandidates());
        self::assertSame(self::FIRST_ESI_JOB_ID, $found[self::FIRST_ESI_JOB_ID]->getJob()->getJobId());
    }

    public function testFindByEsiJobIdsWithoutAnyIdReturnsNothing(): void
    {
        $this->givenUnassignedJob(self::FIRST_ESI_JOB_ID, []);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findByEsiJobIds([]));
    }

    public function testFindWithoutOutputReturnsOnlyTheRowsOfCancelledAndRevertedJobs(): void
    {
        $this->givenUnassignedJob(self::FIRST_ESI_JOB_ID, [])->getJob()->setStatus(CachedIndustryJob::STATUS_CANCELLED);
        $this->givenUnassignedJob(self::SECOND_ESI_JOB_ID, [])->getJob()->setStatus(CachedIndustryJob::STATUS_REVERTED);
        $this->givenUnassignedJob(self::THIRD_ESI_JOB_ID, [])->getJob()->setStatus(CachedIndustryJob::STATUS_DELIVERED);
        $this->flushAndClear();

        $esiJobIds = array_map(static fn (UnassignedJob $row): int => $row->getJob()->getJobId(), $this->repository->findWithoutOutput());
        sort($esiJobIds);

        self::assertSame([self::FIRST_ESI_JOB_ID, self::SECOND_ESI_JOB_ID], $esiJobIds);
    }

    public function testTheRowIsDeletedWithItsCachedJob(): void
    {
        $unassignedJob = $this->givenUnassignedJob(self::FIRST_ESI_JOB_ID, []);
        $this->flushAndClear();

        $this->em->createQuery('DELETE FROM '.CachedIndustryJob::class.' j WHERE j.jobId = :jobId')
            ->setParameter('jobId', self::FIRST_ESI_JOB_ID)
            ->execute();

        self::assertSame([], $this->repository->findByEsiJobIds([self::FIRST_ESI_JOB_ID]));
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT id FROM industry_unassigned_jobs WHERE id = ?', [$unassignedJob->getId()->toRfc4122()]));
    }

    public function testAJobCannotHoldTwoRows(): void
    {
        $job = $this->givenCachedJob(self::FIRST_ESI_JOB_ID);
        $this->em->persist((new UnassignedJob())->setJob($job));
        $this->em->flush();
        $this->em->persist((new UnassignedJob())->setJob($job));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->em->flush();
    }

    #[Group('issue-1248')]
    public function testFindOfUserReturnsTheRowsOfAllHisCharactersWithoutTheDismissedOnesNorTheRowsOfAnotherUser(): void
    {
        $user = $this->createUser();
        $main = $this->createCharacter($user, 'Aria Solberg');
        $alt = $this->createCharacter($user, 'Aria Alt');
        $other = $this->createCharacter(null, 'Corin Vale');
        $this->givenUnassignedJob(self::FIRST_ESI_JOB_ID, [], $main);
        $this->givenUnassignedJob(self::SECOND_ESI_JOB_ID, [], $alt);
        $this->givenUnassignedJob(self::THIRD_ESI_JOB_ID, [], $main)->setDismissedAt(new \DateTimeImmutable('2026-11-11 09:00:00'));
        $this->givenUnassignedJob(self::FOURTH_ESI_JOB_ID, [], $other);
        $this->flushAndClear();

        $found = $this->repository->findOfUser($this->em->find(User::class, $user->getId()));

        $esiJobIds = array_map(static fn (UnassignedJob $row): int => $row->getJob()->getJobId(), $found);
        sort($esiJobIds);
        self::assertSame([self::FIRST_ESI_JOB_ID, self::SECOND_ESI_JOB_ID], $esiJobIds);
    }

    #[Group('issue-1248')]
    public function testFindOneOfUserReturnsTheRowOfHisJobEvenDismissedAndNothingForAnotherUser(): void
    {
        $user = $this->createUser();
        $main = $this->createCharacter($user, 'Aria Solberg');
        $other = $this->createCharacter(null, 'Corin Vale');
        $this->givenUnassignedJob(self::FIRST_ESI_JOB_ID, [], $main);
        $this->givenUnassignedJob(self::SECOND_ESI_JOB_ID, [], $main)->setDismissedAt(new \DateTimeImmutable('2026-11-11 09:00:00'));
        $this->givenUnassignedJob(self::THIRD_ESI_JOB_ID, [], $other);
        $this->flushAndClear();
        $owner = $this->em->find(User::class, $user->getId());

        $open = $this->repository->findOneOfUser($owner, self::FIRST_ESI_JOB_ID);
        $dismissed = $this->repository->findOneOfUser($owner, self::SECOND_ESI_JOB_ID);

        self::assertSame(self::FIRST_ESI_JOB_ID, $open?->getJob()->getJobId());
        self::assertNull($open->getDismissedAt());
        self::assertSame(self::SECOND_ESI_JOB_ID, $dismissed?->getJob()->getJobId());
        self::assertSame((new \DateTimeImmutable('2026-11-11 09:00:00'))->getTimestamp(), $dismissed->getDismissedAt()?->getTimestamp());
        self::assertNull($this->repository->findOneOfUser($owner, self::THIRD_ESI_JOB_ID));
        self::assertNull($this->repository->findOneOfUser($owner, 724_899_999));
    }

    #[Group('issue-1248')]
    public function testFindDismissedEsiJobIdsReturnsOnlyTheDismissedJobs(): void
    {
        $this->givenUnassignedJob(self::FIRST_ESI_JOB_ID, []);
        $this->givenUnassignedJob(self::SECOND_ESI_JOB_ID, [])->setDismissedAt(new \DateTimeImmutable('2026-11-11 09:00:00'));
        $this->givenUnassignedJob(self::THIRD_ESI_JOB_ID, [])->setDismissedAt(new \DateTimeImmutable('2026-11-12 10:30:00'));
        $this->flushAndClear();

        $esiJobIds = $this->repository->findDismissedEsiJobIds();
        sort($esiJobIds);

        self::assertSame([self::SECOND_ESI_JOB_ID, self::THIRD_ESI_JOB_ID], $esiJobIds);
    }

    private function givenUnassignedJob(int $esiJobId, array $candidates, ?Character $character = null): UnassignedJob
    {
        $unassignedJob = (new UnassignedJob())->setJob($this->givenCachedJob($esiJobId, $character))->setCandidates($candidates);
        $this->em->persist($unassignedJob);

        return $unassignedJob;
    }

    private function givenCachedJob(int $esiJobId, ?Character $character = null): CachedIndustryJob
    {
        $start = new \DateTimeImmutable('2026-11-02 08:00:00');
        $job = (new CachedIndustryJob())
            ->setCharacter($character ?? $this->character)
            ->setJobId($esiJobId)
            ->setActivityId(1)
            ->setBlueprintTypeId(12_006)
            ->setProductTypeId(12_005)
            ->setRuns(5)
            ->setCost(1_000.0)
            ->setStatus('active')
            ->setStartDate($start)
            ->setEndDate($start->modify('+1 day'));
        $this->em->persist($job);

        return $job;
    }
}
