<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Delete;
use App\Entity\Character;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Repository\GroupIndustryContributionRepository;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
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
    private GroupIndustryProjectRepository&Stub $projectRepository;
    private GroupIndustryProjectMemberRepository&Stub $memberRepository;
    private GroupIndustryContributionRepository&MockObject $contributionRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private KickGroupMemberProcessor $processor;

    private User&Stub $owner;
    private Uuid $projectId;
    private GroupIndustryProject&Stub $project;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $this->memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);
        $this->contributionRepository = $this->createMock(GroupIndustryContributionRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);

        $this->processor = new KickGroupMemberProcessor(
            $this->security,
            $this->projectRepository,
            $this->memberRepository,
            $this->entityManager,
            new GroupProjectAccessChecker($this->memberRepository),
            new MercurePublisherService($this->hub, new NullLogger()),
            $this->contributionRepository,
        );

        $this->owner = $this->createStub(User::class);
        $this->projectId = Uuid::v4();
        $this->project = $this->createStub(GroupIndustryProject::class);
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

        $otherProject = $this->createStub(GroupIndustryProject::class);
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

    // --- Kick without contribution (current behavior, guard) ---

    public function testOwnerKicksMemberWithoutContribution(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);
        $this->projectRepository->method('find')->willReturn($this->project);

        $memberId = Uuid::v4();
        $member = $this->kickableMember($memberId, 'Kicked Pilot');
        $this->memberRepository->method('find')->willReturn($member);
        $this->contributionRepository->method('countByMember')->willReturn(0);

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
        $this->entityManager->expects($this->once())->method('remove')->with($member);
        $this->entityManager->expects($this->once())->method('flush');

        $this->kick($memberId);
    }

    public function testAdminKicksMemberWithoutContribution(): void
    {
        $adminUser = $this->createStub(User::class);
        $this->security->method('getUser')->willReturn($adminUser);
        $this->projectRepository->method('find')->willReturn($this->project);
        $this->memberRepository->method('findOneBy')->willReturn($this->createStub(GroupIndustryProjectMember::class));

        $memberId = Uuid::v4();
        $member = $this->kickableMember($memberId, 'Kicked Pilot');
        $this->memberRepository->method('find')->willReturn($member);
        $this->contributionRepository->method('countByMember')->willReturn(0);

        $this->hub->expects($this->once())->method('publish');
        $this->entityManager->expects($this->once())->method('remove')->with($member);
        $this->entityManager->expects($this->once())->method('flush');

        $this->kick($memberId);
    }

    // --- Issue #10: a member with contributions cannot be kicked ---

    public function testCannotKickMemberWithOneContribution(): void
    {
        $this->security->method('getUser')->willReturn($this->owner);
        $this->projectRepository->method('find')->willReturn($this->project);

        $memberId = Uuid::v4();
        $member = $this->kickableMember($memberId, 'Contributor Pilot');
        $this->memberRepository->method('find')->willReturn($member);

        $this->contributionRepository
            ->expects($this->once())
            ->method('countByMember')
            ->with($member)
            ->willReturn(1);

        $this->hub->expects($this->never())->method('publish');
        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Cannot kick: member has contributions in this project');

        $this->kick($memberId);
    }

    public function testAdminCannotKickMemberWithContributions(): void
    {
        $adminUser = $this->createStub(User::class);
        $this->security->method('getUser')->willReturn($adminUser);
        $this->projectRepository->method('find')->willReturn($this->project);
        $this->memberRepository->method('findOneBy')->willReturn($this->createStub(GroupIndustryProjectMember::class));

        $memberId = Uuid::v4();
        $member = $this->kickableMember($memberId, 'Contributor Pilot');
        $this->memberRepository->method('find')->willReturn($member);

        $this->contributionRepository
            ->expects($this->once())
            ->method('countByMember')
            ->with($member)
            ->willReturn(3);

        $this->hub->expects($this->never())->method('publish');
        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Cannot kick: member has contributions in this project');

        $this->kick($memberId);
    }

    private function kickableMember(Uuid $memberId, string $characterName): GroupIndustryProjectMember&Stub
    {
        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn($characterName);

        $memberUser = $this->createStub(User::class);
        $memberUser->method('getMainCharacter')->willReturn($character);

        $member = $this->createStub(GroupIndustryProjectMember::class);
        $member->method('getId')->willReturn($memberId);
        $member->method('getProject')->willReturn($this->project);
        $member->method('getRole')->willReturn(GroupMemberRole::Member);
        $member->method('getUser')->willReturn($memberUser);

        return $member;
    }

    private function kick(Uuid $memberId): void
    {
        $this->processor->process(null, new Delete(), [
            'projectId' => $this->projectId->toRfc4122(),
            'id' => $memberId->toRfc4122(),
        ]);
    }
}
