<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Service\Industry\Project\JobInstallerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobInstallerName::class)]
#[Group('issue-339')]
final class JobInstallerNameTest extends TestCase
{
    public function testOwnerSeesTheCharacterThatInstalledTheJob(): void
    {
        [$owner, $project] = $this->ownedProject();

        self::assertSame('O-Alt', JobInstallerName::seenBy('O-Alt', $project, $owner));
    }

    public function testAnotherPlayerSeesTheMainCharacterOfTheOwnerInsteadOfTheAlt(): void
    {
        [, $project] = $this->ownedProject();

        self::assertSame('O-Main', JobInstallerName::seenBy('O-Alt', $project, new User()));
    }

    public function testAJobWithoutInstallerNameStaysWithoutInstallerNameForAnotherPlayer(): void
    {
        [, $project] = $this->ownedProject();

        self::assertNull(JobInstallerName::seenBy(null, $project, new User()));
    }

    public function testAnotherPlayerSeesAnUnknownPilotWhenTheOwnerHasNoMainCharacter(): void
    {
        $project = (new IndustryProject())->setOwner(new User());

        self::assertSame('Unknown', JobInstallerName::seenBy('O-Alt', $project, new User()));
    }

    public function testAParticipantSeesTheCharacterOfHisOwnJob(): void
    {
        [, $project, $participant] = $this->projectWithAParticipant();

        self::assertSame('P-Alt', JobInstallerName::seenBy('P-Alt', $project, $participant));
    }

    public function testTheOwnerSeesTheMainCharacterOfAParticipantInsteadOfHisAlt(): void
    {
        [$owner, $project] = $this->projectWithAParticipant();

        self::assertSame('P-Main', JobInstallerName::seenBy('P-Alt', $project, $owner));
    }

    public function testAThirdPlayerSeesTheMainCharacterOfTheParticipantWhoInstalledTheJob(): void
    {
        [, $project] = $this->projectWithAParticipant();

        self::assertSame('P-Main', JobInstallerName::seenBy('P-Alt', $project, new User()));
    }

    public function testAParticipantSeesTheMainCharacterOfTheOwnerForAJobOfTheOwner(): void
    {
        [, $project, $participant] = $this->projectWithAParticipant();

        self::assertSame('O-Main', JobInstallerName::seenBy('O-Alt', $project, $participant));
    }

    public function testAnInstallerThatNoParticipantOwnsFallsBackToTheMainCharacterOfTheOwner(): void
    {
        [, $project, $participant] = $this->projectWithAParticipant();

        self::assertSame('O-Main', JobInstallerName::seenBy('Departed Pilot', $project, $participant));
    }

    /**
     * @return array{User, IndustryProject, User}
     */
    private function projectWithAParticipant(): array
    {
        [$owner, $project] = $this->ownedProject();
        $project->enrolOwner();

        $main = (new Character())->setEveCharacterId(2120000011)->setName('P-Main');
        $alt = (new Character())->setEveCharacterId(2120000012)->setName('P-Alt');
        $participant = (new User())->addCharacter($main)->addCharacter($alt)->setMainCharacter($main);
        $project->addMember(
            (new IndustryProjectParticipant())
                ->setUser($participant)
                ->setRole(ParticipantRole::Member)
                ->setStatus(ParticipantStatus::Accepted),
        );

        return [$owner, $project, $participant];
    }

    /**
     * @return array{User, IndustryProject}
     */
    private function ownedProject(): array
    {
        $main = (new Character())->setEveCharacterId(2120000001)->setName('O-Main');
        $alt = (new Character())->setEveCharacterId(2120000002)->setName('O-Alt');
        $owner = (new User())->addCharacter($main)->addCharacter($alt)->setMainCharacter($main);

        return [$owner, (new IndustryProject())->setOwner($owner)];
    }
}
