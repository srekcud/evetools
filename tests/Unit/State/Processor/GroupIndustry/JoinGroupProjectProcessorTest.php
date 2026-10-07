<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Post;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\Entity\Character;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use App\State\Processor\GroupIndustry\JoinGroupProjectProcessor;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(JoinGroupProjectProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class JoinGroupProjectProcessorTest extends TestCase
{
    private const SHORT_LINK_CODE = 'aB3dE5fG';
    private const OWNER_CORPORATION_ID = 98000001;
    private const OTHER_CORPORATION_ID = 98000002;

    private Security&Stub $security;
    private EntityManagerInterface&MockObject $entityManager;
    private HubInterface&MockObject $hub;
    private GroupIndustryProject $project;

    /** @var GroupIndustryProjectMember[] */
    private array $memberships = [];

    private JoinGroupProjectProcessor $processor;

    protected function setUp(): void
    {
        $owner = $this->createStub(User::class);
        $owner->method('getCorporationId')->willReturn(self::OWNER_CORPORATION_ID);

        $this->project = new GroupIndustryProject();
        $this->project->setOwner($owner);
        $this->project->setShortLinkCode(self::SHORT_LINK_CODE);
        (new \ReflectionProperty(GroupIndustryProject::class, 'id'))->setValue($this->project, Uuid::v4());

        $this->security = $this->createStub(Security::class);

        $projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $projectRepository->method('findByShortLinkCode')->willReturnMap([[self::SHORT_LINK_CODE, $this->project]]);

        // In-memory repository: findOneBy matches every criterion against the stored rows
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

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->hub = $this->createMock(HubInterface::class);

        $this->processor = new JoinGroupProjectProcessor(
            $this->security,
            $projectRepository,
            $memberRepository,
            new GroupIndustryResourceMapper(),
            $this->entityManager,
            new MercurePublisherService($this->hub, new NullLogger()),
        );
    }

    // --- Current behavior (guards) ---

    public function testNewUserFromOwnerCorporationIsAutoAccepted(): void
    {
        $this->actAs('Corp Mate', self::OWNER_CORPORATION_ID);
        $this->assignIdsOnFlush();

        $resource = $this->join();

        self::assertCount(1, $this->project->getMembers());
        $membership = $this->project->getMembers()->first();
        self::assertSame('accepted', $membership->getStatus()->value);
        self::assertSame('member', $membership->getRole()->value);
        self::assertSame('member', $resource->myRole);
    }

    public function testNewUserFromAnotherCorporationIsPending(): void
    {
        $this->actAs('Stranger', self::OTHER_CORPORATION_ID);
        $this->assignIdsOnFlush();

        $this->join();

        self::assertCount(1, $this->project->getMembers());
        self::assertSame('pending', $this->project->getMembers()->first()->getStatus()->value);
    }

    public function testAcceptedMemberCannotJoinAgain(): void
    {
        $user = $this->actAs('Alice', self::OTHER_CORPORATION_ID);
        $this->addMembership($user, GroupMemberRole::Member, GroupMemberStatus::Accepted);

        $this->entityManager->expects($this->never())->method('flush');
        $this->hub->expects($this->never())->method('publish');

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('You are already a member of this project');

        $this->join();
    }

    // --- Issue #10: a removed (kicked) member may ask to join again ---
    // Decision: the existing row is reused (unique constraint on project_id + user_id),
    // it goes back to "pending" even for the owner's corporation (no auto-accept, otherwise
    // a kick would be undone instantly), and the previous role is reset to "member".

    public function testRemovedMemberFromAnotherCorporationRejoinsAsPendingReusingHisMembership(): void
    {
        $user = $this->actAs('Bob', self::OTHER_CORPORATION_ID);
        $membership = $this->addMembership($user, GroupMemberRole::Member, GroupMemberStatus::Removed);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) use ($membership): bool {
                $payload = json_decode($update->getData(), true);

                return $update->getTopics() === ['/group-project/' . $this->project->getId()->toRfc4122() . '/events']
                    && $payload['action'] === 'member_joined'
                    && $payload['data']['memberId'] === $membership->getId()->toRfc4122()
                    && $payload['data']['characterName'] === 'Bob'
                    && $payload['data']['role'] === 'member'
                    && $payload['data']['status'] === 'pending';
            }));

        $this->join();

        self::assertCount(1, $this->project->getMembers());
        self::assertSame($membership, $this->project->getMembers()->first());
        self::assertSame('pending', $membership->getStatus()->value);
    }

    public function testRemovedMemberFromOwnerCorporationRejoinsAsPending(): void
    {
        $user = $this->actAs('Corp Mate', self::OWNER_CORPORATION_ID);
        $membership = $this->addMembership($user, GroupMemberRole::Member, GroupMemberStatus::Removed);

        $this->join();

        self::assertCount(1, $this->project->getMembers());
        self::assertSame('pending', $membership->getStatus()->value);
    }

    public function testRemovedAdminRejoinsAsPlainMember(): void
    {
        $user = $this->actAs('Former Admin', self::OTHER_CORPORATION_ID);
        $membership = $this->addMembership($user, GroupMemberRole::Admin, GroupMemberStatus::Removed);

        $resource = $this->join();

        self::assertSame('member', $membership->getRole()->value);
        self::assertSame('pending', $membership->getStatus()->value);
        self::assertSame('member', $resource->myRole);
    }

    // --- Join outcome: the returned resource tells whether the membership is accepted or pending ---
    // The role alone cannot tell: a pending member also has a role ("member"), so the join
    // view redirected pending users to the detail page, which answers 403 to them.

    public function testSameCorporationJoinReturnsAcceptedStatus(): void
    {
        $this->actAs('Corp Mate', self::OWNER_CORPORATION_ID);
        $this->assignIdsOnFlush();

        $resource = $this->join();

        self::assertTrue(property_exists($resource, 'myStatus'), 'GroupIndustryProjectResource::$myStatus is missing');
        self::assertSame('accepted', $resource->myStatus);
    }

    public function testOtherCorporationJoinReturnsPendingStatus(): void
    {
        $this->actAs('Stranger', self::OTHER_CORPORATION_ID);
        $this->assignIdsOnFlush();

        $resource = $this->join();

        self::assertTrue(property_exists($resource, 'myStatus'), 'GroupIndustryProjectResource::$myStatus is missing');
        self::assertSame('pending', $resource->myStatus);
        self::assertSame('member', $resource->myRole);
    }

    public function testRemovedMemberRejoiningReturnsPendingStatus(): void
    {
        $user = $this->actAs('Corp Mate', self::OWNER_CORPORATION_ID);
        $this->addMembership($user, GroupMemberRole::Member, GroupMemberStatus::Removed);

        $resource = $this->join();

        self::assertTrue(property_exists($resource, 'myStatus'), 'GroupIndustryProjectResource::$myStatus is missing');
        self::assertSame('pending', $resource->myStatus);
        self::assertSame('member', $resource->myRole);
    }

    private function join(): GroupIndustryProjectResource
    {
        return $this->processor->process(null, new Post(), ['shortLinkCode' => self::SHORT_LINK_CODE]);
    }

    private function actAs(string $characterName, int $corporationId): User&Stub
    {
        $character = $this->createStub(Character::class);
        $character->method('getName')->willReturn($characterName);

        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn($character);
        $user->method('getCorporationId')->willReturn($corporationId);
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

    /** Simulates Doctrine generating the UUID of new memberships on flush. */
    private function assignIdsOnFlush(): void
    {
        $this->entityManager->method('flush')->willReturnCallback(function (): void {
            foreach ($this->project->getMembers() as $membership) {
                if ($membership->getId() === null) {
                    (new \ReflectionProperty(GroupIndustryProjectMember::class, 'id'))->setValue($membership, Uuid::v4());
                }
            }
        });
    }

    /**
     * @param array<string, mixed> $criteria
     */
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
