<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectActivity;
use App\Enum\ProjectActivityKind;
use App\Repository\IndustryProjectActivityRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-841')]
final class IndustryProjectActivityRepositoryTest extends IntegrationTestCase
{
    private IndustryProjectActivityRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectActivityRepository::class);
    }

    public function testRecordedJobIdsAreTheJobIdsOfEveryActivityOfTheRequestedKind(): void
    {
        $project = $this->createProject();
        $this->record($project, ProjectActivityKind::JobsDetected, [880_001, 880_002]);
        $this->record($project, ProjectActivityKind::JobsDetected, [880_003]);
        $this->flushAndClear();

        $jobIds = $this->repository->recordedJobIdsOf($this->reloadProject($project), ProjectActivityKind::JobsDetected);

        self::assertEqualsCanonicalizing([880_001, 880_002, 880_003], $jobIds);
    }

    public function testRecordedJobIdsIgnoreOtherKindsAndOtherProjects(): void
    {
        $project = $this->createProject();
        $otherProject = $this->createProject();
        $this->record($project, ProjectActivityKind::JobsDetected, [880_001]);
        $this->record($project, ProjectActivityKind::JobsFinished, [880_002]);
        $this->record($otherProject, ProjectActivityKind::JobsDetected, [880_003]);
        $this->flushAndClear();

        $jobIds = $this->repository->recordedJobIdsOf($this->reloadProject($project), ProjectActivityKind::JobsDetected);

        self::assertSame([880_001], $jobIds);
    }

    public function testAProjectWithoutRecordedActivityHasNoRecordedJobIds(): void
    {
        $project = $this->createProject();
        $this->flushAndClear();

        self::assertSame([], $this->repository->recordedJobIdsOf($this->reloadProject($project), ProjectActivityKind::JobsDetected));
    }

    private function reloadProject(IndustryProject $project): IndustryProject
    {
        return $this->em->getRepository(IndustryProject::class)->find($project->getId());
    }

    private function createProject(): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->createUser())->setName('Rifter batch');
        $this->em->persist($project);

        return $project;
    }

    /**
     * @param list<int> $jobIds
     */
    private function record(IndustryProject $project, ProjectActivityKind $kind, array $jobIds): void
    {
        $this->em->persist(
            (new IndustryProjectActivity())
                ->setProject($project)
                ->setKind($kind)
                ->setOccurredAt(new \DateTimeImmutable('2026-11-13 10:00:00'))
                ->setData(['jobCount' => \count($jobIds), 'jobIds' => $jobIds]),
        );
    }
}
