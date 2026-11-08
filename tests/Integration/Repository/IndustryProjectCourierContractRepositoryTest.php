<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectCourierContract;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectCourierContractRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-351')]
final class IndustryProjectCourierContractRepositoryTest extends IntegrationTestCase
{
    private const int AMBER_CONTRACT_ID = 351_401;
    private const int AMBER_PILOT_ID = 2_112_351_401;

    private IndustryProjectCourierContractRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectCourierContractRepository::class);
    }

    public function testFindsTheLinkOfTheProjectAndNothingForAProjectWithoutLink(): void
    {
        $owner = $this->createUser();
        $linkedProject = $this->createProject($owner, 'Ishtar batch');
        $unlinkedProject = $this->createProject($owner, 'Hecate batch');
        $this->linkContract($linkedProject);
        $this->flushAndClear();

        $stored = $this->repository->findOneByProject($this->em->getReference(IndustryProject::class, $linkedProject->getId()));
        $none = $this->repository->findOneByProject($this->em->getReference(IndustryProject::class, $unlinkedProject->getId()));

        self::assertNull($none);
        self::assertNotNull($stored);
        self::assertSame(
            [self::AMBER_CONTRACT_ID, self::AMBER_PILOT_ID, '2026-11-05T10:00:00+00:00'],
            [$stored->getContractId(), $stored->getCharacterId(), $stored->getLinkedAt()->format('c')],
        );
    }

    public function testLinkIsDeletedWithItsProject(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner, 'Ishtar batch');
        $this->linkContract($project);
        $this->flushAndClear();

        $this->em->remove($this->em->find(IndustryProject::class, $project->getId()));
        $this->em->flush();
        $this->em->clear();

        self::assertSame([], $this->em->getRepository(IndustryProjectCourierContract::class)->findAll());
    }

    #[Group('issue-422')]
    public function testPendingFailureCheckSkipsNotifiedLinksAndCompletedProjects(): void
    {
        $owner = $this->createUser();
        $watched = $this->createProject($owner, 'Ishtar batch');
        $notified = $this->createProject($owner, 'Hecate batch');
        $completed = $this->createProject($owner, 'Deimos batch')->setStatus(ProjectStatus::Completed);
        $this->linkContract($watched);
        $this->linkContract($notified)->markFailureNotified(new \DateTimeImmutable('2026-11-06T10:00:00+00:00'));
        $this->linkContract($completed);
        $this->flushAndClear();

        $pending = $this->repository->findPendingFailureCheck();

        self::assertSame(
            [$watched->getId()->toRfc4122()],
            array_map(static fn (IndustryProjectCourierContract $link): string => $link->getProject()->getId()->toRfc4122(), $pending),
        );
    }

    private function createProject(User $owner, string $name): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($owner)->setName($name)->setStatus(ProjectStatus::InProgress);
        $this->em->persist($project);

        return $project;
    }

    private function linkContract(IndustryProject $project): IndustryProjectCourierContract
    {
        $link = (new IndustryProjectCourierContract())
            ->setProject($project)
            ->setContractId(self::AMBER_CONTRACT_ID)
            ->setCharacterId(self::AMBER_PILOT_ID)
            ->setLinkedAt(new \DateTimeImmutable('2026-11-05T10:00:00+00:00'));
        $this->em->persist($link);

        return $link;
    }
}
