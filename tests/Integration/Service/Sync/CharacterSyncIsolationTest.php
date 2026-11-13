<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Sync;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Repository\CachedIndustryJobRepository;
use App\Service\Sync\CharacterSyncIsolation;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CharacterSyncIsolation::class)]
#[Group('issue-798')]
final class CharacterSyncIsolationTest extends IntegrationTestCase
{
    private const int FIRST_JOB_ID = 798_000_001;
    private const int DUPLICATED_JOB_ID = 798_000_002;
    private const int NEXT_CHARACTER_JOB_ID = 798_000_003;
    private const int BLUEPRINT_TYPE_ID = 1_000_001;

    private CharacterSyncIsolation $isolation;
    private Uuid $failingCharacterId;
    private Uuid $nextCharacterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolation = new CharacterSyncIsolation($this->em->getConnection(), self::getContainer()->get('doctrine'));
        $this->failingCharacterId = $this->createCharacter(null, 'Failing Pilot')->getId();
        $this->nextCharacterId = $this->createCharacter(null, 'Next Pilot')->getId();
        $this->flushAndClear();
    }

    public function testAUniqueViolationRollsBackTheWholeSyncOfThatCharacterAndLeavesTheEntityManagerUsable(): void
    {
        $failure = $this->failureOf(function (): void {
            $failing = $this->em->find(Character::class, $this->failingCharacterId);
            $this->em->persist($this->job($failing, self::FIRST_JOB_ID));
            $this->em->flush();
            $this->em->persist($this->job($failing, self::DUPLICATED_JOB_ID));
            $this->em->persist($this->job($failing, self::DUPLICATED_JOB_ID));
            $this->em->flush();
        });

        self::assertInstanceOf(UniqueConstraintViolationException::class, $failure);
        self::assertTrue($this->em->isOpen());
        self::assertSame([false, false], [$this->jobExists(self::FIRST_JOB_ID), $this->jobExists(self::DUPLICATED_JOB_ID)]);
    }

    public function testTheNextCharacterIsSyncedAndCommittedAfterTheFailureOfThePreviousOne(): void
    {
        $this->failureOf(function (): void {
            $failing = $this->em->find(Character::class, $this->failingCharacterId);
            $this->em->persist($this->job($failing, self::DUPLICATED_JOB_ID));
            $this->em->persist($this->job($failing, self::DUPLICATED_JOB_ID));
            $this->em->flush();
        });

        $this->isolation->run(function (): void {
            $next = $this->em->find(Character::class, $this->nextCharacterId);
            $this->em->persist($this->job($next, self::NEXT_CHARACTER_JOB_ID));
            $this->em->flush();
        });

        self::assertSame([false, true], [$this->jobExists(self::DUPLICATED_JOB_ID), $this->jobExists(self::NEXT_CHARACTER_JOB_ID)]);
    }

    public function testASuccessfulSyncIsCommitted(): void
    {
        $this->isolation->run(function (): void {
            $next = $this->em->find(Character::class, $this->nextCharacterId);
            $this->em->persist($this->job($next, self::NEXT_CHARACTER_JOB_ID));
            $this->em->flush();
        });

        self::assertTrue($this->jobExists(self::NEXT_CHARACTER_JOB_ID));
    }

    private function failureOf(\Closure $work): \Throwable
    {
        try {
            $this->isolation->run($work);
        } catch (\Throwable $throwable) {
            return $throwable;
        }

        self::fail('The isolation did not rethrow the failure of the sync');
    }

    private function job(Character $character, int $jobId): CachedIndustryJob
    {
        $start = new \DateTimeImmutable('2026-11-02 08:00:00');

        return (new CachedIndustryJob())
            ->setCharacter($character)
            ->setJobId($jobId)
            ->setActivityId(1)
            ->setBlueprintTypeId(self::BLUEPRINT_TYPE_ID)
            ->setProductTypeId(self::BLUEPRINT_TYPE_ID + 1)
            ->setRuns(1)
            ->setCost(1_000.0)
            ->setStatus('active')
            ->setStartDate($start)
            ->setEndDate($start->modify('+1 day'));
    }

    private function jobExists(int $jobId): bool
    {
        return self::getContainer()->get(CachedIndustryJobRepository::class)->findOneBy(['jobId' => $jobId]) !== null;
    }
}
