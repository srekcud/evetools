<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
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
use PHPUnit\Framework\Attributes\DataProvider;
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
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private GroupIndustryProject $project;
    private LeaveGroupProjectProcessor $processor;

    private array $memberships = [];

    private array $contributions = [];

    protected function setUp(): void
    {
        $this->project = new GroupIndustryProject();
        $this->project->setOwner($this->createStub(User::class));
        (new \ReflectionProperty(GroupIndustryProject::class, 'id'))->setValue($this->project, Uuid::v4());

        $this->security = $this->createStub(Security::class);
        $this->projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $this->projectRepository->method('find')->willReturnCallback(
            fn (Uuid $id): ?GroupIndustryProject => $id->equals($this->project->getId()) ? $this->project : null,
        );

        $memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);
        $memberRepository->method('findOneBy')->willReturnCallback(
            function (array $criteria): ?GroupIndustryProjectMember {
                foreach ($this->memberships as $membership) {
                    if ($this->membershipMatches($membership, $criteria)) {
                        return $membership;
                    }
                }

                return null;
            },
        );

        $contributionRepository = $this->createStub(GroupIndustryContributionRepository::class);
        $contributionRepository->method('countByMember')->willReturnCallback(
            fn (GroupIndustryProjectMember $member): int => \count(array_filter(
                $this->contributions,
                static fn (GroupIndustryContribution $contribution): bool => $contribution->getMember() === $member,
            )),
        );

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);

        $this->processor = new LeaveGroupProjectProcessor(
            $this->security,
            $this->projectRepository,
            $memberRepository,
            $contributionRepository,
            $this->entityManager,
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    public function testThrowsUnauthorizedWhenNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(UnauthorizedHttpException::class);

        $this->leave();
    }

    public function testThrowsNotFoundWhenProjectDoesNotExist(): void
    {
        $this->actAs('Lost Pilot');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Project not found');

        $this->processor->process(null, new Post(), ['projectId' => Uuid::v4()->toRfc4122()]);
    }

    public function testThrowsNotFoundWhenUserIsNotAMember(): void
    {
        $this->actAs('Outsider');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('You are not a member of this project');

        $this->leave();
    }

    #[DataProvider('membershipStatusesOtherThanAccepted')]
    public function testMemberWhoseMembershipIsNotAcceptedCannotLeave(GroupMemberStatus $status): void
    {
        $user = $this->actAs('Not Accepted Pilot');
        $membership = $this->addMembership($user, GroupMemberRole::Member, $status);

        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        try {
            $this->leave();
            self::fail('A membership that is not accepted must not be able to leave');
        } catch (NotFoundHttpException $e) {
            self::assertSame('You are not a member of this project', $e->getMessage());
        }

        self::assertSame($status, $membership->getStatus());
    }

    public static function membershipStatusesOtherThanAccepted(): iterable
    {
        yield 'pending' => [GroupMemberStatus::Pending];
        yield 'removed' => [GroupMemberStatus::Removed];
    }

    public function testOwnerCannotLeave(): void
    {
        $user = $this->actAs('Owner Pilot');
        $membership = $this->addMembership($user, GroupMemberRole::Owner, GroupMemberStatus::Accepted);

        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        try {
            $this->leave();
            self::fail('The owner must not be able to leave his project');
        } catch (BadRequestHttpException $e) {
            self::assertSame('Owner cannot leave the project', $e->getMessage());
        }

        self::assertSame(GroupMemberStatus::Accepted, $membership->getStatus());
        self::assertSame(GroupMemberRole::Owner, $membership->getRole());
    }

    public function testSuccessfulLeave(): void
    {
        $user = $this->actAs('Test Pilot');
        $membership = $this->addMembership($user, GroupMemberRole::Member, GroupMemberStatus::Accepted);

        $this->expectMemberLeftEvent($membership, 'Test Pilot');
        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->once())->method('flush');

        $this->leave();

        self::assertSame(GroupMemberStatus::Removed, $membership->getStatus());
        self::assertContains($membership, $this->project->getMembers());
    }

    public function testMemberWithContributionsLeavesAndKeepsHisContributions(): void
    {
        $user = $this->actAs('Generous Pilot');
        $membership = $this->addMembership($user, GroupMemberRole::Member, GroupMemberStatus::Accepted);
        $firstApproved = $this->addContribution($membership, 1500, ContributionStatus::Approved);
        $secondApproved = $this->addContribution($membership, 800, ContributionStatus::Approved);
        $pending = $this->addContribution($membership, 320, ContributionStatus::Pending);

        $this->expectMemberLeftEvent($membership, 'Generous Pilot');
        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->once())->method('flush');

        $this->leave();

        self::assertSame(GroupMemberStatus::Removed, $membership->getStatus());
        self::assertSame(ContributionStatus::Approved, $firstApproved->getStatus());
        self::assertSame(ContributionStatus::Approved, $secondApproved->getStatus());
        self::assertSame(ContributionStatus::Pending, $pending->getStatus());
        self::assertSame($membership, $firstApproved->getMember());
        self::assertSame($membership, $secondApproved->getMember());
        self::assertSame($membership, $pending->getMember());
    }

    public function testAdminWithNoContributionsCanLeave(): void
    {
        $user = $this->actAs('Admin Pilot');
        $membership = $this->addMembership($user, GroupMemberRole::Admin, GroupMemberStatus::Accepted);

        $this->expectMemberLeftEvent($membership, 'Admin Pilot');
        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->once())->method('flush');

        $this->leave();

        self::assertSame(GroupMemberStatus::Removed, $membership->getStatus());
        self::assertContains($membership, $this->project->getMembers());
    }

    private function leave(): void
    {
        $this->processor->process(null, new Post(), ['projectId' => $this->project->getId()->toRfc4122()]);
    }

    private function actAs(string $characterName): User&Stub
    {
        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn($characterName);

        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);
        $this->security->method('getUser')->willReturn($user);

        return $user;
    }

    private function addMembership(User $user, GroupMemberRole $role, GroupMemberStatus $status): GroupIndustryProjectMember
    {
        $membership = new GroupIndustryProjectMember();
        $membership->setUser($user);
        $membership->setRole($role);
        $membership->setStatus($status);
        (new \ReflectionProperty(GroupIndustryProjectMember::class, 'id'))->setValue($membership, Uuid::v4());
        $this->project->addMember($membership);
        $this->memberships[] = $membership;

        return $membership;
    }

    private function addContribution(GroupIndustryProjectMember $membership, int $quantity, ContributionStatus $status): GroupIndustryContribution
    {
        $contribution = new GroupIndustryContribution();
        $contribution->setProject($this->project);
        $contribution->setMember($membership);
        $contribution->setType(ContributionType::Material);
        $contribution->setQuantity($quantity);
        $contribution->setStatus($status);
        $this->contributions[] = $contribution;

        return $contribution;
    }

    private function expectMemberLeftEvent(GroupIndustryProjectMember $membership, string $characterName): void
    {
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) use ($membership, $characterName): bool {
                $payload = json_decode($update->getData(), true);

                return $update->getTopics() === ['/group-project/' . $this->project->getId()->toRfc4122() . '/events']
                    && $payload['action'] === 'member_left'
                    && $payload['data'] === [
                        'memberId' => $membership->getId()->toRfc4122(),
                        'characterName' => $characterName,
                    ];
            }));
    }

    private function membershipMatches(GroupIndustryProjectMember $membership, array $criteria): bool
    {
        $values = [
            'project' => $membership->getProject(),
            'user' => $membership->getUser(),
            'role' => $membership->getRole(),
            'status' => $membership->getStatus(),
        ];

        foreach ($criteria as $field => $expected) {
            if (!array_key_exists($field, $values)) {
                self::fail("Unexpected criterion '{$field}'");
            }
            $accepted = is_array($expected) ? $expected : [$expected];
            if (!in_array($values[$field], $accepted, true)) {
                return false;
            }
        }

        return true;
    }
}
