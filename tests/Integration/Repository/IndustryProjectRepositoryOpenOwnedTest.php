<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-750')]
final class IndustryProjectRepositoryOpenOwnedTest extends IntegrationTestCase
{
    private IndustryProjectRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectRepository::class);
    }

    public function testOwnerFindsHisProjectsOfEveryOpenStatusAndNeitherCompletedNorForeignOnes(): void
    {
        $owner = $this->createUser();
        $this->createProject('Draft batch', $owner, ProjectStatus::Draft);
        $this->createProject('Running batch', $owner, ProjectStatus::InProgress);
        $this->createProject('Selling batch', $owner, ProjectStatus::Selling);
        $this->createProject('Completed batch', $owner, ProjectStatus::Completed);
        $this->createProject('Foreign batch', $this->createUser(), ProjectStatus::InProgress);
        $this->em->flush();

        $names = array_map(static fn (IndustryProject $project): string => $project->getName(), $this->repository->findOpenOwnedBy($owner));
        sort($names);

        self::assertSame(['Draft batch', 'Running batch', 'Selling batch'], $names);
    }

    private function createProject(string $name, User $owner, ProjectStatus $status): void
    {
        $this->em->persist((new IndustryProject())->setOwner($owner)->setName($name)->setStatus($status));
    }
}
