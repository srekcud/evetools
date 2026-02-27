<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryContributionRepository;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\LeaveGroupProjectProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LeaveGroupProjectProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class LeaveGroupProjectProcessorTest extends TestCase
{
    private Security&Stub $security;
    private GroupIndustryProjectRepository&Stub $projectRepository;
    private GroupIndustryProjectMemberRepository&Stub $memberRepository;
    private GroupIndustryContributionRepository&MockObject $contributionRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private MercurePublisherService $mercurePublisher;
    private LeaveGroupProjectProcessor $processor;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $this->memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);
        $this->contributionRepository = $this->createMock(GroupIndustryContributionRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->mercurePublisher = new MercurePublisherService($this->hub, new NullLogger());

        $this->processor = new LeaveGroupProjectProcessor(
            $this->security,
            $this->projectRepository,
            $this->memberRepository,
            $this->contributionRepository,
            $this->entityManager,
            $this->mercurePublisher,
        );
    }

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->processor->process(null, new Post(), ['projectId' => Uuid::v4()->toRfc4122()]);
    }

    public function testThrowsNotFoundWhenProjectDoesNotExist(): void
    {
        $user = $this->createStub(User::class);
        $this->security->method('getUser')->willReturn($user);
        $this->projectRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $this->processor->process(null, new Post(), ['projectId' => Uuid::v4()->toRfc4122()]);
    }

    public function testThrowsNotFoundWhenUserIsNotAMember(): void
    {
        $user = $this->createStub(User::class);
        $this->security->method('getUser')->willReturn($user);

        $project = $this->createStub(GroupIndustryProject::class);
        $project->method('getId')->willReturn(Uuid::v4());
        $this->projectRepository->method('find')->willReturn($project);
        $this->memberRepository->method('findOneBy')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('You are not a member of this project');

        $this->processor->process(null, new Post(), ['projectId' => $project->getId()->toRfc4122()]);
    }

    public function testOwnerCannotLeave(): void
    {
        $user = $this->createStub(User::class);
        $this->security->method('getUser')->willReturn($user);

        $project = $this->createStub(GroupIndustryProject::class);
        $project->method('getId')->willReturn(Uuid::v4());
        $this->projectRepository->method('find')->willReturn($project);

        $member = $this->createStub(GroupIndustryProjectMember::class);
        $member->method('getRole')->willReturn(GroupMemberRole::Owner);
        $this->memberRepository->method('findOneBy')->willReturn($member);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Owner cannot leave the project');

        $this->processor->process(null, new Post(), ['projectId' => $project->getId()->toRfc4122()]);
    }

    public function testMemberWithContributionsCannotLeave(): void
    {
        $user = $this->createStub(User::class);
        $this->security->method('getUser')->willReturn($user);

        $project = $this->createStub(GroupIndustryProject::class);
        $project->method('getId')->willReturn(Uuid::v4());
        $this->projectRepository->method('find')->willReturn($project);

        $member = $this->createStub(GroupIndustryProjectMember::class);
        $member->method('getRole')->willReturn(GroupMemberRole::Member);
        $this->memberRepository->method('findOneBy')->willReturn($member);

        $this->contributionRepository
            ->expects($this->once())
            ->method('countByMember')
            ->with($member)
            ->willReturn(3);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Cannot leave: you have contributions in this project');

        $this->processor->process(null, new Post(), ['projectId' => $project->getId()->toRfc4122()]);
    }

    public function testSuccessfulLeave(): void
    {
        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn('Test Pilot');

        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);
        $this->security->method('getUser')->willReturn($user);

        $projectId = Uuid::v4();
        $project = $this->createStub(GroupIndustryProject::class);
        $project->method('getId')->willReturn($projectId);
        $this->projectRepository->method('find')->willReturn($project);

        $memberId = Uuid::v4();
        $member = $this->createStub(GroupIndustryProjectMember::class);
        $member->method('getId')->willReturn($memberId);
        $member->method('getRole')->willReturn(GroupMemberRole::Member);
        $member->method('getUser')->willReturn($user);
        $this->memberRepository->method('findOneBy')->willReturn($member);

        $this->contributionRepository
            ->expects($this->once())
            ->method('countByMember')
            ->with($member)
            ->willReturn(0);

        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update): bool {
                $data = json_decode($update->getData(), true);

                return $data['action'] === 'member_left'
                    && $data['data']['characterName'] === 'Test Pilot';
            }));

        $this->entityManager
            ->expects($this->once())
            ->method('remove')
            ->with($member);

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $this->processor->process(null, new Post(), ['projectId' => $projectId->toRfc4122()]);
    }

    public function testAdminWithNoContributionsCanLeave(): void
    {
        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn('Admin Pilot');

        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);
        $this->security->method('getUser')->willReturn($user);

        $projectId = Uuid::v4();
        $project = $this->createStub(GroupIndustryProject::class);
        $project->method('getId')->willReturn($projectId);
        $this->projectRepository->method('find')->willReturn($project);

        $memberId = Uuid::v4();
        $member = $this->createStub(GroupIndustryProjectMember::class);
        $member->method('getId')->willReturn($memberId);
        $member->method('getRole')->willReturn(GroupMemberRole::Admin);
        $member->method('getUser')->willReturn($user);
        $this->memberRepository->method('findOneBy')->willReturn($member);

        $this->contributionRepository
            ->method('countByMember')
            ->willReturn(0);

        $this->hub
            ->expects($this->once())
            ->method('publish');

        $this->entityManager
            ->expects($this->once())
            ->method('remove')
            ->with($member);

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $this->processor->process(null, new Post(), ['projectId' => $projectId->toRfc4122()]);
    }
}
