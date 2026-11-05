<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\GroupIndustry\GroupIndustryMemberResource;
use App\Entity\Character;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupMemberCollectionProvider;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GroupMemberCollectionProvider::class)]
class GroupMemberCollectionProviderTest extends TestCase
{
    private const OWNER_MEMBER_ID = '00000000-0000-0000-0000-000000000001';
    private const ALICE_MEMBER_ID = '00000000-0000-0000-0000-000000000002';
    private const CARL_MEMBER_ID = '00000000-0000-0000-0000-000000000003';
    private const REMOVED_BOB_MEMBER_ID = '00000000-0000-0000-0000-000000000004';

    private User&Stub $owner;
    private IndustryProject $project;

    /** @var IndustryProjectParticipant[] */
    private array $memberships = [];

    /** @var IndustryProjectContribution[] */
    private array $approvedContributions = [];

    private GroupMemberCollectionProvider $provider;

    protected function setUp(): void
    {
        $this->owner = $this->createStub(User::class);
        $this->project = (new IndustryProject())->addItem((new IndustryProjectProduct())->setTypeId(587)->setTypeName('Sabre')->setRuns(1));
        $this->project->setOwner($this->owner);
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($this->project, Uuid::v4());

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->owner);

        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);

        // In-memory repository: findBy matches every criterion against the stored rows
        $memberRepository = $this->createStub(IndustryProjectParticipantRepository::class);
        $memberRepository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => array_values(array_filter(
                $this->memberships,
                fn (IndustryProjectParticipant $membership): bool => $this->membershipMatches($membership, $criteria),
            )),
        );

        $contributionRepository = $this->createStub(IndustryProjectContributionRepository::class);
        $contributionRepository->method('findBy')->willReturnCallback(fn (): array => $this->approvedContributions);

        $this->provider = new GroupMemberCollectionProvider(
            $security,
            $projectRepository,
            $memberRepository,
            $contributionRepository,
            new GroupProjectAccessChecker($memberRepository),
            new GroupIndustryResourceMapper(),
        );
    }

    // Issue #10: a removed (kicked) member is no longer listed as a project member
    public function testRemovedMemberIsNotListed(): void
    {
        $this->addMembership(self::OWNER_MEMBER_ID, 'Owner Pilot', GroupMemberRole::Owner, GroupMemberStatus::Accepted, $this->owner);
        $alice = $this->addMembership(self::ALICE_MEMBER_ID, 'Alice', GroupMemberRole::Member, GroupMemberStatus::Accepted);
        $this->addMembership(self::CARL_MEMBER_ID, 'Carl', GroupMemberRole::Member, GroupMemberStatus::Pending);
        $removedBob = $this->addMembership(self::REMOVED_BOB_MEMBER_ID, 'Bob', GroupMemberRole::Member, GroupMemberStatus::Removed);

        $this->approvedContributions = [
            $this->stubContribution($alice, 250_000.0),
            $this->stubContribution($removedBob, 400_000.0),
        ];

        $resources = $this->listMembers();

        self::assertSame(
            [self::OWNER_MEMBER_ID, self::ALICE_MEMBER_ID, self::CARL_MEMBER_ID],
            array_map(static fn (GroupIndustryMemberResource $r): string => $r->id, $resources),
        );
        self::assertSame(['accepted', 'accepted', 'pending'], array_map(static fn (GroupIndustryMemberResource $r): string => $r->status, $resources));

        $aliceResource = $resources[1];
        self::assertSame('Alice', $aliceResource->characterName);
        self::assertSame(250_000.0, $aliceResource->totalContributionValue);
        self::assertSame(1, $aliceResource->contributionCount);
    }

    public function testListsAcceptedAndPendingMembersWithTheirApprovedContributions(): void
    {
        $this->addMembership(self::OWNER_MEMBER_ID, 'Owner Pilot', GroupMemberRole::Owner, GroupMemberStatus::Accepted, $this->owner);
        $alice = $this->addMembership(self::ALICE_MEMBER_ID, 'Alice', GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $this->addMembership(self::CARL_MEMBER_ID, 'Carl', GroupMemberRole::Member, GroupMemberStatus::Pending);

        $this->approvedContributions = [
            $this->stubContribution($alice, 250_000.0),
            $this->stubContribution($alice, 50_000.0),
        ];

        $resources = $this->listMembers();

        self::assertCount(3, $resources);
        self::assertSame(['owner', 'admin', 'member'], array_map(static fn (GroupIndustryMemberResource $r): string => $r->role, $resources));
        self::assertSame(300_000.0, $resources[1]->totalContributionValue);
        self::assertSame(2, $resources[1]->contributionCount);
        self::assertSame(0.0, $resources[2]->totalContributionValue);
        self::assertSame(0, $resources[2]->contributionCount);
    }

    /**
     * @return GroupIndustryMemberResource[]
     */
    private function listMembers(): array
    {
        return $this->provider->provide(new GetCollection(), ['projectId' => $this->project->getId()->toRfc4122()]);
    }

    private function addMembership(
        string $memberId,
        string $characterName,
        GroupMemberRole $role,
        GroupMemberStatus $status,
        ?User $user = null,
    ): IndustryProjectParticipant {
        if ($user === null) {
            $character = $this->createStub(Character::class);
            $character->method('getName')->willReturn($characterName);
            $user = $this->createStub(User::class);
            $user->method('getMainCharacter')->willReturn($character);
        }

        $membership = new IndustryProjectParticipant();
        $membership->setUser($user);
        $membership->setRole($role);
        $membership->setStatus($status);
        (new \ReflectionProperty(IndustryProjectParticipant::class, 'id'))->setValue($membership, Uuid::fromString($memberId));
        $this->project->addMember($membership);

        $this->memberships[] = $membership;

        return $membership;
    }

    private function stubContribution(IndustryProjectParticipant $member, float $estimatedValue): IndustryProjectContribution&Stub
    {
        $contribution = $this->createStub(IndustryProjectContribution::class);
        $contribution->method('getMember')->willReturn($member);
        $contribution->method('getEstimatedValue')->willReturn($estimatedValue);

        return $contribution;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function membershipMatches(IndustryProjectParticipant $membership, array $criteria): bool
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
