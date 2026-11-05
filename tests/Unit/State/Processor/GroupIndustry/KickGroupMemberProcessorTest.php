<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Delete;
use App\Entity\Character;
use App\Entity\GroupIndustryContribution;
use App\Entity\IndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\KickGroupMemberProcessor;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(KickGroupMemberProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class KickGroupMemberProcessorTest extends TestCase
{
    private Security&Stub $security;
    private IndustryProjectRepository&Stub $projectRepository;
    private GroupIndustryProjectMemberRepository&Stub $memberRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private KickGroupMemberProcessor $processor;

    private User&Stub $owner;
    private Uuid $projectId;
    private IndustryProject&Stub $project;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectRepository = $this->createStub(IndustryProjectRepository::class);
        $this->memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);

        $this->processor = new KickGroupMemberProcessor(
            $this->security,
            $this->projectRepository,
            $this->memberRepository,
            $this->entityManager,
            new GroupProjectAccessChecker($this->memberRepository),
            new MercurePublisherService($this->hub, new NullLogger()),
        );

        $this->owner = $this->createStub(User::class);
        $this->projectId = Uuid::v4();
        $this->project = $this->createStub(IndustryProject::class);
        $this->project->method('getId')->willReturn($this->projectId);
        $this->project->method('getOwner')->willReturn($this->owner);
    }

    // --- Authorization rules (current behavior, guards) ---

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->kick(Uuid::v4());
    }

    public function testThrowsNotFoundWhenMemberIdIsMissing(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Member not found');

        $this->processor->process(null, new Delete(), ['projectId' => $this->projectId->toRfc4122()]);
    }

    public function testThrowsNotFoundWhenProjectDoesNotExist(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);
        $this->projectRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $this->kick(Uuid::v4());
    }

    public function testPlainMemberCannotKick(): void
    {
        $plainMemberUser = $this->createStub(User::class);
        $this->security->method('getUser')->willReturn($plainMemberUser);
        $this->projectRepository->method('find')->willReturn($this->project);
        // No accepted Admin membership found for the acting user
        $this->memberRepository->method('findOneBy')->willReturn(null);

        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only project owner or admin can perform this action');

        $this->kick(Uuid::v4());
    }

    public function testThrowsNotFoundWhenMemberDoesNotExist(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);
        $this->projectRepository->method('find')->willReturn($this->project);
        $this->memberRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Member not found');

        $this->kick(Uuid::v4());
    }

    public function testThrowsNotFoundWhenMemberBelongsToAnotherProject(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);
        $this->projectRepository->method('find')->willReturn($this->project);

        $otherProject = $this->createStub(IndustryProject::class);
        $member = $this->createStub(GroupIndustryProjectMember::class);
        $member->method('getProject')->willReturn($otherProject);
        $member->method('getRole')->willReturn(GroupMemberRole::Member);
        $this->memberRepository->method('find')->willReturn($member);

        $this->entityManager->expects($this->never())->method('remove');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Member not found');

        $this->kick(Uuid::v4());
    }

    public function testCannotKickTheOwner(): void
    {
        $adminUser = $this->createStub(User::class);
        $this->security->method('getUser')->willReturn($adminUser);
        $this->projectRepository->method('find')->willReturn($this->project);
        $this->memberRepository->method('findOneBy')->willReturn($this->createStub(GroupIndustryProjectMember::class));

        $ownerMember = $this->createStub(GroupIndustryProjectMember::class);
        $ownerMember->method('getProject')->willReturn($this->project);
        $ownerMember->method('getRole')->willReturn(GroupMemberRole::Owner);
        $ownerMember->method('getUser')->willReturn($this->owner);
        $this->memberRepository->method('find')->willReturn($ownerMember);

        $this->entityManager->expects($this->never())->method('remove');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Cannot kick the project owner');

        $this->kick(Uuid::v4());
    }

    public function testAdminCannotKickHimself(): void
    {
        $adminUser = $this->createStub(User::class);
        $this->security->method('getUser')->willReturn($adminUser);
        $this->projectRepository->method('find')->willReturn($this->project);

        $adminMember = $this->createStub(GroupIndustryProjectMember::class);
        $adminMember->method('getProject')->willReturn($this->project);
        $adminMember->method('getRole')->willReturn(GroupMemberRole::Admin);
        $adminMember->method('getUser')->willReturn($adminUser);
        $this->memberRepository->method('findOneBy')->willReturn($adminMember);
        $this->memberRepository->method('find')->willReturn($adminMember);

        $this->entityManager->expects($this->never())->method('remove');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Cannot kick yourself, use leave instead');

        $this->kick(Uuid::v4());
    }

    // --- Issue #10: kicking is a soft removal, contributions are kept ---
    // Decision: a kick always soft-removes (status "removed", row kept), whether or not
    // the member has contributions. Contributions keep their status and stay in the payout.

    public function testOwnerSoftRemovesMemberWithoutContribution(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);
        $this->projectRepository->method('find')->willReturn($this->project);

        $memberId = Uuid::v4();
        $member = $this->acceptedMember($memberId, 'Kicked Pilot');
        $this->memberRepository->method('find')->willReturn($member);

        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) use ($memberId): bool {
                $payload = json_decode($update->getData(), true);

                return $update->getTopics() === ['/group-project/' . $this->projectId->toRfc4122() . '/events']
                    && $payload['action'] === 'member_left'
                    && $payload['data']['memberId'] === $memberId->toRfc4122()
                    && $payload['data']['characterName'] === 'Kicked Pilot';
            }));
        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->once())->method('flush');

        $this->kick($memberId);

        self::assertSame('removed', $member->getStatus()->value);
    }

    public function testAdminSoftRemovesMemberWithoutContribution(): void
    {
        $this->actAsAdmin();

        $memberId = Uuid::v4();
        $member = $this->acceptedMember($memberId, 'Kicked Pilot');
        $this->memberRepository->method('find')->willReturn($member);

        $this->hub->expects($this->once())->method('publish');
        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->once())->method('flush');

        $this->kick($memberId);

        self::assertSame('removed', $member->getStatus()->value);
    }

    public function testOwnerSoftRemovesMemberWithOneContribution(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);
        $this->projectRepository->method('find')->willReturn($this->project);

        $memberId = Uuid::v4();
        $member = $this->acceptedMember($memberId, 'Contributor Pilot');
        $this->memberRepository->method('find')->willReturn($member);
        $contribution = $this->contributionOf($member, ContributionType::Material, 1200, 4_500_000.0, ContributionStatus::Approved);

        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) use ($memberId): bool {
                $payload = json_decode($update->getData(), true);

                return $payload['action'] === 'member_left'
                    && $payload['data']['memberId'] === $memberId->toRfc4122()
                    && $payload['data']['characterName'] === 'Contributor Pilot';
            }));
        // Never remove(): the member row (and its contributions via CASCADE) must survive
        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->once())->method('flush');

        $this->kick($memberId);

        self::assertSame('removed', $member->getStatus()->value);
        self::assertSame('member', $member->getRole()->value);
        self::assertSame($member, $contribution->getMember());
        self::assertSame(['approved', 'material', 1200, 4_500_000.0], [
            $contribution->getStatus()->value,
            $contribution->getType()->value,
            $contribution->getQuantity(),
            $contribution->getEstimatedValue(),
        ]);
    }

    public function testAdminSoftRemovesMemberWithContributions(): void
    {
        $this->actAsAdmin();

        $memberId = Uuid::v4();
        $member = $this->acceptedMember($memberId, 'Contributor Pilot');
        $this->memberRepository->method('find')->willReturn($member);
        $contributions = [
            $this->contributionOf($member, ContributionType::Material, 350, 1_750_000.0, ContributionStatus::Approved),
            $this->contributionOf($member, ContributionType::JobInstall, 1, 820_000.0, ContributionStatus::Pending),
            $this->contributionOf($member, ContributionType::Bpc, 10, 2_300_000.0, ContributionStatus::Rejected),
        ];

        $this->hub->expects($this->once())->method('publish');
        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->once())->method('flush');

        $this->kick($memberId);

        self::assertSame('removed', $member->getStatus()->value);
        self::assertSame([
            [$member, 'approved', 'material', 350, 1_750_000.0],
            [$member, 'pending', 'job_install', 1, 820_000.0],
            [$member, 'rejected', 'bpc', 10, 2_300_000.0],
        ], array_map(static fn (GroupIndustryContribution $contribution): array => [
            $contribution->getMember(),
            $contribution->getStatus()->value,
            $contribution->getType()->value,
            $contribution->getQuantity(),
            $contribution->getEstimatedValue(),
        ], $contributions));
    }

    public function testOwnerSoftRemovesPendingMember(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);
        $this->projectRepository->method('find')->willReturn($this->project);

        $memberId = Uuid::v4();
        $member = $this->acceptedMember($memberId, 'Pending Pilot');
        $member->setStatus(GroupMemberStatus::Pending);
        $this->memberRepository->method('find')->willReturn($member);

        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->once())->method('flush');

        $this->kick($memberId);

        self::assertSame('removed', $member->getStatus()->value);
    }

    public function testAdminCannotKickAnotherAdmin(): void
    {
        $this->actAsAdmin();

        $adminId = Uuid::v4();
        $otherAdmin = $this->acceptedMember($adminId, 'Other Admin Pilot', GroupMemberRole::Admin);
        $this->memberRepository->method('find')->willReturn($otherAdmin);

        $this->hub->expects($this->never())->method('publish');
        $this->entityManager->expects($this->never())->method('flush');

        try {
            $this->kick($adminId);
            self::fail('An admin kicked another admin');
        } catch (AccessDeniedHttpException $exception) {
            self::assertSame('Only the project owner can manage an admin', $exception->getMessage());
        }

        self::assertSame(['accepted', 'admin'], [$otherAdmin->getStatus()->value, $otherAdmin->getRole()->value]);
    }

    public function testAdminCannotKickARemovedAdmin(): void
    {
        $this->actAsAdmin();

        $adminId = Uuid::v4();
        $removedAdmin = $this->acceptedMember($adminId, 'Removed Admin Pilot', GroupMemberRole::Admin);
        $removedAdmin->setStatus(GroupMemberStatus::Removed);
        $this->memberRepository->method('find')->willReturn($removedAdmin);

        $this->hub->expects($this->never())->method('publish');
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Only the project owner can manage an admin');

        $this->kick($adminId);
    }

    public function testOwnerSoftRemovesAnAdmin(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);
        $this->projectRepository->method('find')->willReturn($this->project);

        $adminId = Uuid::v4();
        $admin = $this->acceptedMember($adminId, 'Kicked Admin Pilot', GroupMemberRole::Admin);
        $this->memberRepository->method('find')->willReturn($admin);

        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) use ($adminId): bool {
                $payload = json_decode($update->getData(), true);

                return $payload['action'] === 'member_left'
                    && $payload['data']['memberId'] === $adminId->toRfc4122()
                    && $payload['data']['characterName'] === 'Kicked Admin Pilot';
            }));
        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->once())->method('flush');

        $this->kick($adminId);

        self::assertSame('removed', $admin->getStatus()->value);
    }

    private function actAsAdmin(): void
    {
        $adminUser = $this->createStub(User::class);
        $this->security->method('getUser')->willReturn($adminUser);
        $this->projectRepository->method('find')->willReturn($this->project);
        // Accepted Admin membership found for the acting user
        $this->memberRepository->method('findOneBy')->willReturn($this->createStub(GroupIndustryProjectMember::class));
    }

    private function acceptedMember(Uuid $memberId, string $characterName, GroupMemberRole $role = GroupMemberRole::Member): GroupIndustryProjectMember
    {
        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn($characterName);

        $memberUser = $this->createStub(User::class);
        $memberUser->method('getMainCharacter')->willReturn($character);

        $member = new GroupIndustryProjectMember();
        $member->setProject($this->project);
        $member->setUser($memberUser);
        $member->setRole($role);
        $member->setStatus(GroupMemberStatus::Accepted);
        (new \ReflectionProperty(GroupIndustryProjectMember::class, 'id'))->setValue($member, $memberId);

        return $member;
    }

    private function contributionOf(
        GroupIndustryProjectMember $member,
        ContributionType $type,
        int $quantity,
        float $estimatedValue,
        ContributionStatus $status,
    ): GroupIndustryContribution {
        return (new GroupIndustryContribution())
            ->setProject($this->project)
            ->setMember($member)
            ->setType($type)
            ->setQuantity($quantity)
            ->setEstimatedValue($estimatedValue)
            ->setStatus($status);
    }

    private function kick(Uuid $memberId): void
    {
        $this->processor->process(null, new Delete(), [
            'projectId' => $this->projectId->toRfc4122(),
            'id' => $memberId->toRfc4122(),
        ]);
    }
}
