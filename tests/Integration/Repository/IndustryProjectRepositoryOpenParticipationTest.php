<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-318')]
final class IndustryProjectRepositoryOpenParticipationTest extends IntegrationTestCase
{
    private IndustryProjectRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(IndustryProjectRepository::class);
    }

    public function testAnAcceptedParticipantFindsTheOpenProjectsHeTakesPartInOwnedOrNot(): void
    {
        $participant = $this->createUser();
        $owned = $this->createProject('Owned batch', $participant, ProjectStatus::InProgress);
        $this->enrol($owned, $participant, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $joined = $this->createProject('Joined batch', $this->createUser(), ProjectStatus::Selling);
        $this->enrol($joined, $participant, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->em->flush();

        $names = array_map(static fn (IndustryProject $project): string => $project->getName(), $this->repository->findOpenOfAcceptedParticipant($participant));
        sort($names);

        self::assertSame(['Joined batch', 'Owned batch'], $names);
    }

    public function testPendingOrRemovedParticipationAndCompletedProjectsAreNotFound(): void
    {
        $participant = $this->createUser();
        $pending = $this->createProject('Pending batch', $this->createUser(), ProjectStatus::InProgress);
        $this->enrol($pending, $participant, ParticipantRole::Member, ParticipantStatus::Pending);
        $removed = $this->createProject('Removed batch', $this->createUser(), ProjectStatus::InProgress);
        $this->enrol($removed, $participant, ParticipantRole::Member, ParticipantStatus::Removed);
        $completed = $this->createProject('Completed batch', $this->createUser(), ProjectStatus::Completed);
        $this->enrol($completed, $participant, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->em->flush();

        self::assertSame([], $this->repository->findOpenOfAcceptedParticipant($participant));
    }

    private function createProject(string $name, User $owner, ProjectStatus $status): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($owner)->setName($name)->setStatus($status);
        $this->em->persist($project);

        return $project;
    }

    private function enrol(IndustryProject $project, User $user, ParticipantRole $role, ParticipantStatus $status): void
    {
        $project->addMember(
            (new IndustryProjectParticipant())->setUser($user)->setRole($role)->setStatus($status),
        );
    }
}
