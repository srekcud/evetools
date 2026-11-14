<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-953')]
final class IndustryProjectRepositoryOpenOfCorporationTest extends IntegrationTestCase
{
    private const int CORPORATION_ID = 98_000_777;
    private const int OTHER_CORPORATION_ID = 98_000_888;

    private IndustryProjectRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectRepository::class);
    }

    public function testFindsTheOpenProjectsOfOwnersWhoseMainCharacterIsInTheCorporation(): void
    {
        $owner = $this->createOwnerInCorporation(self::CORPORATION_ID);
        $this->createProject('Draft batch', $owner, ProjectStatus::Draft);
        $this->createProject('Selling batch', $owner, ProjectStatus::Selling);
        $this->createProject('Completed batch', $owner, ProjectStatus::Completed);
        $this->createProject('Foreign corporation batch', $this->createOwnerInCorporation(self::OTHER_CORPORATION_ID), ProjectStatus::InProgress);
        $this->em->flush();

        $names = array_map(static fn (IndustryProject $project): string => $project->getName(), $this->repository->findOpenOwnedByCorporation(self::CORPORATION_ID));
        sort($names);

        self::assertSame(['Draft batch', 'Selling batch'], $names);
    }

    private function createOwnerInCorporation(int $corporationId): User
    {
        $owner = $this->createUser();
        $character = $this->createCharacter($owner)->setCorporationId($corporationId);
        $owner->setMainCharacter($character);

        return $owner;
    }

    private function createProject(string $name, User $owner, ProjectStatus $status): void
    {
        $this->em->persist((new IndustryProject())->setOwner($owner)->setName($name)->setStatus($status));
    }
}
