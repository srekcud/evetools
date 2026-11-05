<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
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
            ->setRole(GroupMemberRole::Admin)
            ->setStatus(GroupMemberStatus::Accepted);
        $project->addMember($participant);

        $project->transferOwnershipTo($participant);

        self::assertSame($newOwner, $project->getOwner());
        self::assertSame(GroupMemberRole::Owner, $participant->getRole());
    }

    public function testNewProjectIsPublishedWithAUuidV7AndItsOwnInvitationCodeOfTenHexadecimalCharacters(): void
    {
        $project = new IndustryProject();
        $otherProject = new IndustryProject();

        self::assertInstanceOf(UuidV7::class, $project->getId());
        self::assertSame(GroupProjectStatus::Published, $project->getStatus());
        self::assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $project->getShortLinkCode());
        self::assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $otherProject->getShortLinkCode());
        self::assertNotSame($otherProject->getShortLinkCode(), $project->getShortLinkCode());
    }

    public function testProjectIsPlannedByStepsUntilItGetsAnItem(): void
    {
        $project = new IndustryProject();
        self::assertTrue($project->isPlannedBySteps());

        $project->addItem(
            (new IndustryProjectProduct())
                ->setTypeId(22456)
                ->setTypeName('Sabre')
                ->setMeLevel(10)
                ->setTeLevel(20)
                ->setRuns(5),
        );

        self::assertFalse($project->isPlannedBySteps());
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
}
