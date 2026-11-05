<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(IndustryProject::class)]
#[Group('issue-362')]
final class IndustryProjectTest extends TestCase
{
    public function testOwnershipTransferredToAnAcceptedParticipantMakesHimTheOwnerAndGivesHimTheOwnerRole(): void
    {
        $formerOwner = new User();
        $newOwner = new User();
        $project = (new IndustryProject())->setOwner($formerOwner);
        $participant = (new IndustryProjectParticipant())
            ->setUser($newOwner)
            ->setRole(ParticipantRole::Admin)
            ->setStatus(ParticipantStatus::Accepted);
        $project->addMember($participant);

        $project->transferOwnershipTo($participant);

        self::assertSame($newOwner, $project->getOwner());
        self::assertSame(ParticipantRole::Owner, $participant->getRole());
    }

    public function testNewProjectIsADraftWithAUuidV7AndItsOwnInvitationCodeOfTenHexadecimalCharacters(): void
    {
        $project = new IndustryProject();
        $otherProject = new IndustryProject();

        self::assertInstanceOf(UuidV7::class, $project->getId());
        self::assertSame(ProjectStatus::Draft, $project->getStatus());
        self::assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $project->getShortLinkCode());
        self::assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $otherProject->getShortLinkCode());
        self::assertNotSame($otherProject->getShortLinkCode(), $project->getShortLinkCode());
    }

    public function testNewProjectIsPrivateByDefaultAndItsPrivacyCanBeLifted(): void
    {
        $project = new IndustryProject();
        self::assertTrue($project->isPrivate());

        $project->setPrivate(false);
        self::assertFalse($project->isPrivate());

        $project->setPrivate(true);
        self::assertTrue($project->isPrivate());
    }

    public function testProjectHasNoStepsAndDoesNotTakeItsProductFromItsFirstItem(): void
    {
        $project = new IndustryProject();

        self::assertFalse(method_exists($project, 'getSteps'));
        self::assertFalse(method_exists($project, 'takeProductFromFirstItem'));
    }

    public function testProjectHasNoRoutePredicateToTellASoloProjectFromAGroupOne(): void
    {
        $project = new IndustryProject();

        self::assertFalse(method_exists($project, 'isPlannedBySteps'));
        self::assertFalse(method_exists($project, 'isPlannedByStepsAndOwnedBy'));
    }
}
