<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\GroupIndustry\GroupIndustryMemberResource;
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
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupMemberCollectionProvider;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Issue #39: listing the members of a group project must not lazy-load, member by member, the
 * user and its main character. The number of queries must not depend on the number of members,
 * and the listed values must stay exactly the same.
 */
final class GroupMemberCollectionProviderTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private string $viewerId;
    private string $projectId;

    protected function setUp(): void
    {
        parent::setUp();

        $aria = $this->createUserWithMainCharacter('Aria Solberg');
        $brann = $this->createUserWithMainCharacter('Brann Holt');
        $cyra = $this->createUserWithMainCharacter('Cyra Lune');
        $dornWithoutMainCharacter = $this->createUser();
        $removedEryn = $this->createUserWithMainCharacter('Eryn Vale');
        $viewer = $this->createUserWithMainCharacter('Kaelen Voss');

        $project = $this->createProject($aria, 'Sabre doctrine');
        $this->addMember($project, $aria, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $brannMember = $this->addMember($project, $brann, GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $this->addMember($project, $cyra, GroupMemberRole::Member, GroupMemberStatus::Pending);
        $this->addMember($project, $dornWithoutMainCharacter, GroupMemberRole::Member, GroupMemberStatus::Accepted);
        $this->addMember($project, $removedEryn, GroupMemberRole::Member, GroupMemberStatus::Removed);
        $this->addMember($project, $viewer, GroupMemberRole::Member, GroupMemberStatus::Accepted);

        $this->addContribution($project, $brannMember, estimatedValue: 1_600_000.0, status: ContributionStatus::Approved);
        $this->addContribution($project, $brannMember, estimatedValue: 2_500_000.0, status: ContributionStatus::Approved);
        $this->addContribution($project, $brannMember, estimatedValue: 9_000_000.0, status: ContributionStatus::Pending);

        $this->em->flush();
        $this->viewerId = (string) $viewer->getId();
        $this->projectId = (string) $project->getId();
        $this->em->clear();
    }

    public function testQueryCountDoesNotGrowWithTheNumberOfMembers(): void
    {
        [$twoMembersViewerId, $twoMembersProjectId] = $this->createProjectWithMembers('Lyra Sato', membersCount: 2);
        [$tenMembersViewerId, $tenMembersProjectId] = $this->createProjectWithMembers('Orin Vahl', membersCount: 10);

        $twoMembersQueries = $this->queriesListingMembers($twoMembersViewerId, $twoMembersProjectId);
        $tenMembersQueries = $this->queriesListingMembers($tenMembersViewerId, $tenMembersProjectId);

        self::assertCount(
            \count($twoMembersQueries),
            $tenMembersQueries,
            sprintf(
                "2 members: %d queries, 10 members: %d queries:\n%s",
                \count($twoMembersQueries),
                \count($tenMembersQueries),
                implode("\n", $tenMembersQueries),
            ),
        );
    }

    public function testTenMembersAreListedWithTheirMainCharacterAndApprovedContribution(): void
    {
        [$viewerId, $projectId] = $this->createProjectWithMembers('Orin Vahl', membersCount: 10);

        $members = $this->membersByCharacterName($this->listMembersOf($projectId, $this->user($viewerId)));

        self::assertCount(10, $members);
        self::assertSame(0, $members['Owner of Orin Vahl']->contributionCount);
        self::assertSame(1, $members['Member 10 of Orin Vahl']->contributionCount);
        self::assertSame(50_000.0, $members['Member 10 of Orin Vahl']->totalContributionValue);
    }

    public function testListingShowsAcceptedAndPendingMembersButNotRemovedOnes(): void
    {
        $members = $this->membersByCharacterName($this->listMembersOf($this->projectId, $this->user($this->viewerId)));

        self::assertEqualsCanonicalizing(
            ['Aria Solberg', 'Brann Holt', 'Cyra Lune', 'Unknown', 'Kaelen Voss'],
            array_keys($members),
        );
    }

    public function testMemberShowsMainCharacterCorporationRoleAndApprovedContributionsOnly(): void
    {
        $brann = $this->membersByCharacterName($this->listMembersOf($this->projectId, $this->user($this->viewerId)))['Brann Holt'];

        self::assertSame('admin', $brann->role);
        self::assertSame('accepted', $brann->status);
        self::assertSame(98_000_001, $brann->corporationId);
        self::assertSame('Test Corp', $brann->corporationName);
        self::assertGreaterThan(0, $brann->characterId);
        // 1 600 000 + 2 500 000, the pending contribution is not counted
        self::assertSame(4_100_000.0, $brann->totalContributionValue);
        self::assertSame(2, $brann->contributionCount);
    }

    public function testPendingMemberAndMemberWithoutMainCharacter(): void
    {
        $members = $this->membersByCharacterName($this->listMembersOf($this->projectId, $this->user($this->viewerId)));

        self::assertSame('pending', $members['Cyra Lune']->status);
        self::assertSame('owner', $members['Aria Solberg']->role);
        self::assertSame(0.0, $members['Aria Solberg']->totalContributionValue);
        self::assertSame(0, $members['Aria Solberg']->contributionCount);
        self::assertSame(0, $members['Unknown']->characterId);
        self::assertNull($members['Unknown']->corporationId);
        self::assertNull($members['Unknown']->corporationName);
        self::assertSame('accepted', $members['Unknown']->status);
    }

    private function user(string $userId): User
    {
        $user = $this->em->find(User::class, $userId);
        \assert($user instanceof User);

        return $user;
    }

    /** @return list<string> every SQL query issued while listing the members of the project */
    private function queriesListingMembers(string $viewerId, string $projectId): array
    {
        $this->em->clear();
        $viewer = $this->user($viewerId);

        return $this->sqlExecutedDuring(fn () => $this->listMembersOf($projectId, $viewer));
    }

    /** @return GroupIndustryMemberResource[] */
    private function listMembersOf(string $projectId, User $user): array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $provider = new GroupMemberCollectionProvider(
            $security,
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupIndustryProjectMemberRepository::class),
            self::getContainer()->get(GroupIndustryContributionRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
            self::getContainer()->get(GroupIndustryResourceMapper::class),
        );

        return $provider->provide(new GetCollection(), ['projectId' => $projectId]);
    }

    /**
     * @param GroupIndustryMemberResource[] $members
     *
     * @return array<string, GroupIndustryMemberResource>
     */
    private function membersByCharacterName(array $members): array
    {
        $byName = [];
        foreach ($members as $member) {
            $byName[$member->characterName] = $member;
        }

        return $byName;
    }

    /**
     * The owner, the viewer and other accepted members, each with a main character; every member
     * other than the owner and the viewer has one approved contribution.
     *
     * @return array{string, string} viewer id and project id
     */
    private function createProjectWithMembers(string $prefix, int $membersCount): array
    {
        $owner = $this->createUserWithMainCharacter(sprintf('Owner of %s', $prefix));
        $viewer = $this->createUserWithMainCharacter(sprintf('Viewer of %s', $prefix));
        $project = $this->createProject($owner, sprintf('%s project', $prefix));
        $this->addMember($project, $owner, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->addMember($project, $viewer, GroupMemberRole::Member, GroupMemberStatus::Accepted);

        for ($index = 3; $index <= $membersCount; ++$index) {
            $member = $this->addMember(
                $project,
                $this->createUserWithMainCharacter(sprintf('Member %d of %s', $index, $prefix)),
                GroupMemberRole::Member,
                GroupMemberStatus::Accepted,
            );
            $this->addContribution($project, $member, estimatedValue: $index * 5_000.0, status: ContributionStatus::Approved);
        }

        $this->em->flush();

        return [(string) $viewer->getId(), (string) $project->getId()];
    }

    private function createUserWithMainCharacter(string $characterName): User
    {
        $user = $this->createUser();
        $user->setMainCharacter($this->createCharacter($user, $characterName));

        return $user;
    }

    private function createProject(User $owner, string $name): GroupIndustryProject
    {
        $project = (new GroupIndustryProject())->setOwner($owner)->setName($name);
        $this->em->persist($project);

        return $project;
    }

    private function addMember(GroupIndustryProject $project, User $user, GroupMemberRole $role, GroupMemberStatus $status): GroupIndustryProjectMember
    {
        $member = (new GroupIndustryProjectMember())
            ->setUser($user)
            ->setRole($role)
            ->setStatus($status);
        $project->addMember($member);

        return $member;
    }

    private function addContribution(GroupIndustryProject $project, GroupIndustryProjectMember $member, float $estimatedValue, ContributionStatus $status): void
    {
        $project->addContribution(
            (new GroupIndustryContribution())
                ->setMember($member)
                ->setType(ContributionType::LineRental)
                ->setQuantity(1)
                ->setEstimatedValue($estimatedValue)
                ->setStatus($status),
        );
    }
}
