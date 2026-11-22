<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\CharacterRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\CharacterActiveProject;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(CharacterActiveProject::class)]
#[Group('issue-1249')]
final class CharacterActiveProjectTest extends IntegrationTestCase
{
    private CharacterActiveProject $characterActiveProject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->characterActiveProject = new CharacterActiveProject(
            $this->em,
            self::getContainer()->get(IndustryProjectRepository::class),
            self::getContainer()->get(CharacterRepository::class),
        );
    }

    public function testACharacterIsDedicatedToAnOpenProjectOfWhichTheCallerIsAnAcceptedParticipant(): void
    {
        $caller = $this->createUser();
        $character = $this->createCharacter($caller, 'Aria Solberg');
        $project = $this->createProject('Orca batch', $this->createUser(), ProjectStatus::InProgress);
        $this->enrol($project, $caller, ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->em->flush();

        $this->characterActiveProject->dedicate($caller, $character, $project);

        $this->em->clear();
        self::assertSame($project->getId()->toRfc4122(), $this->reloaded($character)->getActiveProject()?->getId()->toRfc4122());
    }

    public function testTheOwnerOfAProjectCanDedicateACharacterToIt(): void
    {
        $owner = $this->createUser();
        $character = $this->createCharacter($owner, 'Boss Pilot');
        $project = $this->createProject('Owned batch', $owner, ProjectStatus::Draft);
        $this->enrol($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->em->flush();

        $this->characterActiveProject->dedicate($owner, $character, $project);

        $this->em->clear();
        self::assertSame($project->getId()->toRfc4122(), $this->reloaded($character)->getActiveProject()?->getId()->toRfc4122());
    }

    public function testDedicatingToTheProjectOfAUserTheCallerDoesNotShareIsRefusedAndKeepsTheActiveProject(): void
    {
        $caller = $this->createUser();
        $character = $this->createCharacter($caller, 'Aria Solberg');
        $own = $this->createProject('Own batch', $caller, ProjectStatus::InProgress);
        $this->enrol($own, $caller, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $foreign = $this->createProject('Foreign batch', $this->createUser(), ProjectStatus::InProgress);
        $character->setActiveProject($own);
        $this->em->flush();

        try {
            $this->characterActiveProject->dedicate($caller, $character, $foreign);
            self::fail('A project the caller does not take part in must be refused');
        } catch (NotFoundHttpException) {
        }

        $this->em->clear();
        self::assertSame($own->getId()->toRfc4122(), $this->reloaded($character)->getActiveProject()?->getId()->toRfc4122());
    }

    public function testDedicatingToAProjectWhereTheCallerIsStillPendingIsRefused(): void
    {
        $caller = $this->createUser();
        $character = $this->createCharacter($caller, 'Aria Solberg');
        $project = $this->createProject('Pending batch', $this->createUser(), ProjectStatus::InProgress);
        $this->enrol($project, $caller, ParticipantRole::Member, ParticipantStatus::Pending);
        $this->em->flush();

        $this->expectException(NotFoundHttpException::class);

        $this->characterActiveProject->dedicate($caller, $character, $project);
    }

    public function testDedicatingToACompletedProjectIsRefused(): void
    {
        $caller = $this->createUser();
        $character = $this->createCharacter($caller, 'Aria Solberg');
        $project = $this->createProject('Completed batch', $caller, ProjectStatus::Completed);
        $this->enrol($project, $caller, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->em->flush();

        $this->expectException(NotFoundHttpException::class);

        $this->characterActiveProject->dedicate($caller, $character, $project);
    }

    public function testDedicatingACharacterOfAnotherUserIsRefusedAndLeavesItUntouched(): void
    {
        $caller = $this->createUser();
        $foreignCharacter = $this->createCharacter($this->createUser(), 'Kel Dorn');
        $project = $this->createProject('Own batch', $caller, ProjectStatus::InProgress);
        $this->enrol($project, $caller, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->em->flush();

        try {
            $this->characterActiveProject->dedicate($caller, $foreignCharacter, $project);
            self::fail('A character of another user must be refused');
        } catch (NotFoundHttpException) {
        }

        $this->em->clear();
        self::assertNull($this->reloaded($foreignCharacter)->getActiveProject());
    }

    public function testDedicatingToNullClearsTheActiveProject(): void
    {
        $caller = $this->createUser();
        $character = $this->createCharacter($caller, 'Aria Solberg');
        $project = $this->createProject('Orca batch', $caller, ProjectStatus::InProgress);
        $this->enrol($project, $caller, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $character->setActiveProject($project);
        $this->em->flush();

        $this->characterActiveProject->dedicate($caller, $character, null);

        $this->em->clear();
        self::assertNull($this->reloaded($character)->getActiveProject());
    }

    public function testDedicatingToAnotherProjectReplacesTheActiveProject(): void
    {
        $caller = $this->createUser();
        $character = $this->createCharacter($caller, 'Aria Solberg');
        $first = $this->createProject('First batch', $caller, ProjectStatus::InProgress);
        $this->enrol($first, $caller, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $second = $this->createProject('Second batch', $caller, ProjectStatus::InProgress);
        $this->enrol($second, $caller, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $character->setActiveProject($first);
        $this->em->flush();

        $this->characterActiveProject->dedicate($caller, $character, $second);

        $this->em->clear();
        self::assertSame($second->getId()->toRfc4122(), $this->reloaded($character)->getActiveProject()?->getId()->toRfc4122());
    }

    public function testClearingForAProjectNullsTheActiveProjectOfEveryCharacterDedicatedToItWhateverTheUser(): void
    {
        $firstUser = $this->createUser();
        $secondUser = $this->createUser();
        $completed = $this->createProject('Completed batch', $firstUser, ProjectStatus::Completed);
        $other = $this->createProject('Other batch', $firstUser, ProjectStatus::InProgress);
        $firstDedicated = $this->createCharacter($firstUser, 'Aria Solberg');
        $secondDedicated = $this->createCharacter($secondUser, 'Sana Vel');
        $dedicatedElsewhere = $this->createCharacter($secondUser, 'Kel Dorn');
        $notDedicated = $this->createCharacter($firstUser, 'Mira Tane');
        $firstDedicated->setActiveProject($completed);
        $secondDedicated->setActiveProject($completed);
        $dedicatedElsewhere->setActiveProject($other);
        $this->em->flush();

        $this->characterActiveProject->clearForProject($completed);

        $this->flushAndClear();
        self::assertSame(
            [null, null, $other->getId()->toRfc4122(), null],
            array_map(
                fn (Character $character): ?string => $this->reloaded($character)->getActiveProject()?->getId()->toRfc4122(),
                [$firstDedicated, $secondDedicated, $dedicatedElsewhere, $notDedicated],
            ),
        );
    }

    public function testClearingForAParticipantNullsOnlyTheCharactersOfThatUserDedicatedToThatProject(): void
    {
        $leaving = $this->createUser();
        $staying = $this->createUser();
        $project = $this->createProject('Shared batch', $staying, ProjectStatus::InProgress);
        $other = $this->createProject('Other batch', $staying, ProjectStatus::InProgress);
        $leavingOnProject = $this->createCharacter($leaving, 'Aria Solberg');
        $leavingOnOther = $this->createCharacter($leaving, 'Aria Alt');
        $leavingOnNothing = $this->createCharacter($leaving, 'Aria Third');
        $stayingOnProject = $this->createCharacter($staying, 'Sana Vel');
        $leavingOnProject->setActiveProject($project);
        $leavingOnOther->setActiveProject($other);
        $stayingOnProject->setActiveProject($project);
        $this->em->flush();

        $this->characterActiveProject->clearForParticipant($project, $leaving);

        $this->flushAndClear();
        self::assertSame(
            [null, $other->getId()->toRfc4122(), null, $project->getId()->toRfc4122()],
            array_map(
                fn (Character $character): ?string => $this->reloaded($character)->getActiveProject()?->getId()->toRfc4122(),
                [$leavingOnProject, $leavingOnOther, $leavingOnNothing, $stayingOnProject],
            ),
        );
    }

    public function testDeletingTheActiveProjectLeavesTheCharacterWithoutActiveProject(): void
    {
        $user = $this->createUser();
        $character = $this->createCharacter($user, 'Aria Solberg');
        $project = $this->createProject('Doomed batch', $user, ProjectStatus::InProgress);
        $character->setActiveProject($project);
        $this->em->flush();

        $this->em->remove($project);
        $this->flushAndClear();

        self::assertNull($this->reloaded($character)->getActiveProject());
    }

    private function reloaded(Character $character): Character
    {
        return $this->em->find(Character::class, $character->getId());
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
