<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-539')]
final class IndustryProjectJobMatchRepositoryTest extends IntegrationTestCase
{
    private const int RIFTER_JOB_ID = 880_001;
    private const int MERLIN_JOB_ID = 880_002;
    private const int KESTREL_JOB_ID = 880_003;

    private IndustryProjectJobMatchRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectJobMatchRepository::class);
    }

    public function testTheOwnerOfAProjectWithoutParticipantRowSeesTheProjectOfAMatchedJobKeyedByEsiJobId(): void
    {
        $owner = $this->createUser();
        $rifterBatch = $this->createProject('Rifter batch', $owner);
        $this->matchJob($rifterBatch, self::RIFTER_JOB_ID, '2026-11-01 10:00:00');
        $this->flushAndClear();

        $visible = $this->repository->findVisibleProjectsByEsiJobIds($this->reloadUser($owner), [self::RIFTER_JOB_ID, self::MERLIN_JOB_ID]);

        self::assertSame([self::RIFTER_JOB_ID], array_keys($visible));
        self::assertSame($rifterBatch->getId()->toRfc4122(), $visible[self::RIFTER_JOB_ID]->getId()->toRfc4122());
        self::assertSame('Rifter batch', $visible[self::RIFTER_JOB_ID]->getName());
    }

    public function testAnAcceptedParticipantSeesTheProjectOfAMatchedJobOfSomeoneElse(): void
    {
        $participant = $this->createUser();
        $merlinBatch = $this->createProject('Merlin batch', $this->createUser());
        $this->enrol($merlinBatch, $participant, ParticipantStatus::Accepted);
        $this->matchJob($merlinBatch, self::MERLIN_JOB_ID, '2026-11-01 10:00:00');
        $this->flushAndClear();

        $visible = $this->repository->findVisibleProjectsByEsiJobIds($this->reloadUser($participant), [self::MERLIN_JOB_ID]);

        self::assertSame([self::MERLIN_JOB_ID], array_keys($visible));
        self::assertSame($merlinBatch->getId()->toRfc4122(), $visible[self::MERLIN_JOB_ID]->getId()->toRfc4122());
    }

    public function testPendingAndRemovedParticipantsAndAThirdPartySeeNoProject(): void
    {
        $pending = $this->createUser();
        $removed = $this->createUser();
        $thirdParty = $this->createUser();
        $kestrelBatch = $this->createProject('Kestrel batch', $this->createUser());
        $this->enrol($kestrelBatch, $pending, ParticipantStatus::Pending);
        $this->enrol($kestrelBatch, $removed, ParticipantStatus::Removed);
        $this->matchJob($kestrelBatch, self::KESTREL_JOB_ID, '2026-11-01 10:00:00');
        $this->flushAndClear();

        self::assertSame([], $this->repository->findVisibleProjectsByEsiJobIds($this->reloadUser($pending), [self::KESTREL_JOB_ID]));
        self::assertSame([], $this->repository->findVisibleProjectsByEsiJobIds($this->reloadUser($removed), [self::KESTREL_JOB_ID]));
        self::assertSame([], $this->repository->findVisibleProjectsByEsiJobIds($this->reloadUser($thirdParty), [self::KESTREL_JOB_ID]));
    }

    public function testAJobMatchedToTwoVisibleProjectsResolvesToTheProjectOfTheOldestMatch(): void
    {
        $owner = $this->createUser();
        $recentBatch = $this->createProject('Recent batch', $owner);
        $oldestBatch = $this->createProject('Oldest batch', $owner);
        $this->matchJob($recentBatch, self::RIFTER_JOB_ID, '2026-11-05 10:00:00');
        $this->matchJob($oldestBatch, self::RIFTER_JOB_ID, '2026-11-02 10:00:00');
        $this->flushAndClear();

        $visible = $this->repository->findVisibleProjectsByEsiJobIds($this->reloadUser($owner), [self::RIFTER_JOB_ID]);

        self::assertSame([self::RIFTER_JOB_ID], array_keys($visible));
        self::assertSame($oldestBatch->getId()->toRfc4122(), $visible[self::RIFTER_JOB_ID]->getId()->toRfc4122());
    }

    public function testAMatchOnAProjectNotVisibleIsSkippedInFavourOfTheOldestVisibleOne(): void
    {
        $owner = $this->createUser();
        $foreignOldestBatch = $this->createProject('Foreign batch', $this->createUser());
        $ownBatch = $this->createProject('Own batch', $owner);
        $this->matchJob($foreignOldestBatch, self::RIFTER_JOB_ID, '2026-11-01 10:00:00');
        $this->matchJob($ownBatch, self::RIFTER_JOB_ID, '2026-11-03 10:00:00');
        $this->flushAndClear();

        $visible = $this->repository->findVisibleProjectsByEsiJobIds($this->reloadUser($owner), [self::RIFTER_JOB_ID]);

        self::assertSame([self::RIFTER_JOB_ID], array_keys($visible));
        self::assertSame($ownBatch->getId()->toRfc4122(), $visible[self::RIFTER_JOB_ID]->getId()->toRfc4122());
    }

    public function testAnEmptyListOfEsiJobIdsFindsNoProject(): void
    {
        $owner = $this->createUser();
        $this->matchJob($this->createProject('Rifter batch', $owner), self::RIFTER_JOB_ID, '2026-11-01 10:00:00');
        $this->flushAndClear();

        self::assertSame([], $this->repository->findVisibleProjectsByEsiJobIds($this->reloadUser($owner), []));
    }

    private function reloadUser(User $user): User
    {
        return $this->em->getRepository(User::class)->find($user->getId());
    }

    private function createProject(string $name, User $owner): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($owner)->setName($name);
        $this->em->persist($project);

        return $project;
    }

    private function enrol(IndustryProject $project, User $user, ParticipantStatus $status): void
    {
        $project->addMember(
            (new IndustryProjectParticipant())->setUser($user)->setRole(ParticipantRole::Member)->setStatus($status),
        );
    }

    private function matchJob(IndustryProject $project, int $esiJobId, string $matchedAt): void
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId(587)
            ->setTypeName('Rifter')
            ->setRequiredQuantity(3)
            ->setIsJob(true);
        $project->addBomItem($line);
        $line->addJobMatch(
            (new IndustryProjectJobMatch())
                ->setEsiJobId($esiJobId)
                ->setRuns(3)
                ->setMatchedAt(new \DateTimeImmutable($matchedAt)),
        );
    }
}
