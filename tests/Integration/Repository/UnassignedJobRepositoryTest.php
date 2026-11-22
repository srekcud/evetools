<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\UnassignedJob;
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

    private function givenUnassignedJob(int $esiJobId, array $candidates): UnassignedJob
    {
        $unassignedJob = (new UnassignedJob())->setJob($this->givenCachedJob($esiJobId))->setCandidates($candidates);
        $this->em->persist($unassignedJob);

        return $unassignedJob;
    }

    private function givenCachedJob(int $esiJobId): CachedIndustryJob
    {
        $start = new \DateTimeImmutable('2026-11-02 08:00:00');
        $job = (new CachedIndustryJob())
            ->setCharacter($this->character)
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
