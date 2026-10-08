<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\GroupIndustry\GroupIndustryProjectResource;
use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\State\Provider\GroupIndustry\AvailableGroupProjectsProvider;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Issue #39: listing the group projects open to the user's corporation must not lazy-load,
 * project by project, the owner and its main character, the user's membership, the items,
 * every member (only to count them) and the BOM items. The number of queries must not depend
 * on the number of projects, members and BOM items, and the listed values must stay the same.
 */
final class AvailableGroupProjectsProviderTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private const int VIEWER_CORPORATION_ID = 98_000_001;
    private const int OTHER_CORPORATION_ID = 98_000_002;

    private const int SABRE_TYPE_ID = 22456;
    private const int JACKDAW_TYPE_ID = 34828;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;

    private string $viewerId;
    private string $sabreProjectId;
    private string $jackdawProjectId;

    protected function setUp(): void
    {
        parent::setUp();

        $viewer = $this->createUserWithMainCharacter('Kaelen Voss', self::VIEWER_CORPORATION_ID);
        $aria = $this->createUserWithMainCharacter('Aria Solberg', self::VIEWER_CORPORATION_ID);
        $brann = $this->createUserWithMainCharacter('Brann Holt', self::VIEWER_CORPORATION_ID);
        $cyra = $this->createUserWithMainCharacter('Cyra Lune', self::VIEWER_CORPORATION_ID);
        $nyx = $this->createUserWithMainCharacter('Nyx Arden', self::OTHER_CORPORATION_ID);

        $sabre = $this->createProject($aria, 'Sabre doctrine', GroupProjectStatus::Published);
        $this->addMember($sabre, $aria, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->addMember($sabre, $brann, GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $this->addMember($sabre, $cyra, GroupMemberRole::Member, GroupMemberStatus::Pending);
        $this->addItem($sabre, self::SABRE_TYPE_ID, 'Sabre', runs: 10);
        $this->addBomItem($sabre, self::TRITANIUM_TYPE_ID, 'Tritanium', requiredQuantity: 1_000_000, fulfilledQuantity: 400_000, estimatedPrice: 4.0);
        $this->addBomItem($sabre, self::PYERITE_TYPE_ID, 'Pyerite', requiredQuantity: 200_000, fulfilledQuantity: 250_000, estimatedPrice: 10.0);

        $jackdaw = $this->createProject($brann, 'Jackdaw batch', GroupProjectStatus::InProgress);
        $this->addMember($jackdaw, $brann, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->addItem($jackdaw, self::JACKDAW_TYPE_ID, 'Jackdaw', runs: 20);

        $draft = $this->createProject($aria, 'Draft project', GroupProjectStatus::Draft);
        $this->addMember($draft, $aria, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $selling = $this->createProject($aria, 'Selling project', GroupProjectStatus::Selling);
        $this->addMember($selling, $aria, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $alreadyJoined = $this->createProject($cyra, 'Already joined', GroupProjectStatus::Published);
        $this->addMember($alreadyJoined, $cyra, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->addMember($alreadyJoined, $viewer, GroupMemberRole::Member, GroupMemberStatus::Pending);
        $ownProject = $this->createProject($viewer, 'Own project', GroupProjectStatus::Published);
        $this->addMember($ownProject, $viewer, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $otherCorporation = $this->createProject($nyx, 'Other corporation', GroupProjectStatus::Published);
        $this->addMember($otherCorporation, $nyx, GroupMemberRole::Owner, GroupMemberStatus::Accepted);

        $this->em->flush();
        $this->viewerId = (string) $viewer->getId();
        $this->sabreProjectId = (string) $sabre->getId();
        $this->jackdawProjectId = (string) $jackdaw->getId();
        $this->em->clear();
    }

    public function testQueryCountDoesNotGrowWithProjectsMembersAndBomItems(): void
    {
        $viewerWithTwoProjectsId = $this->createCorporationWithOpenProjects(98_100_002, projectsCount: 2, membersPerProject: 2, bomItemsPerProject: 2);
        $viewerWithTenProjectsId = $this->createCorporationWithOpenProjects(98_100_010, projectsCount: 10, membersPerProject: 10, bomItemsPerProject: 10);

        $twoProjectsQueries = $this->queriesListingAvailableProjectsAs($viewerWithTwoProjectsId);
        $tenProjectsQueries = $this->queriesListingAvailableProjectsAs($viewerWithTenProjectsId);

        self::assertCount(
            \count($twoProjectsQueries),
            $tenProjectsQueries,
            sprintf(
                "2 projects: %d queries, 10 projects: %d queries:\n%s",
                \count($twoProjectsQueries),
                \count($tenProjectsQueries),
                implode("\n", $tenProjectsQueries),
            ),
        );
    }

    public function testTenOpenProjectsAreListedWithTheirTenMembersAndTenBomItems(): void
    {
        $viewerId = $this->createCorporationWithOpenProjects(98_100_010, projectsCount: 10, membersPerProject: 10, bomItemsPerProject: 10);

        $projects = $this->listAvailableProjectsAs($this->user($viewerId));

        self::assertCount(10, $projects);
        foreach ($projects as $project) {
            self::assertSame(10, $project->membersCount);
            self::assertCount(10, $project->items);
            // 10 BOM items x 1 000 units x 5.0 ISK
            self::assertSame(50_000.0, $project->totalBomValue);
            // 10 x 250 fulfilled / 10 x 1 000 required
            self::assertSame(25.0, $project->fulfillmentPercent);
            self::assertSame(98_100_010, $project->ownerCorporationId);
            self::assertStringStartsWith('Owner 98100010 ', $project->ownerCharacterName);
        }
    }

    public function testListsOnlyOpenProjectsOfTheCorporationTheUserHasNotJoined(): void
    {
        $projects = $this->availableProjectsById();

        self::assertEqualsCanonicalizing([$this->sabreProjectId, $this->jackdawProjectId], array_keys($projects));
    }

    public function testPublishedProjectCountsAcceptedMembersAndCapsFulfilledQuantity(): void
    {
        $sabre = $this->availableProjectsById()[$this->sabreProjectId];

        self::assertSame('Sabre doctrine', $sabre->name);
        self::assertSame('published', $sabre->status);
        self::assertSame('Aria Solberg', $sabre->ownerCharacterName);
        self::assertSame(self::VIEWER_CORPORATION_ID, $sabre->ownerCorporationId);
        self::assertNull($sabre->myRole);
        self::assertNull($sabre->myStatus);
        // owner + admin accepted, the pending member is not counted
        self::assertSame(2, $sabre->membersCount);
        self::assertSame([
            ['typeId' => self::SABRE_TYPE_ID, 'typeName' => 'Sabre', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10],
        ], $sabre->items);
        // Tritanium 1 000 000 x 4.0 + Pyerite 200 000 x 10.0
        self::assertSame(6_000_000.0, $sabre->totalBomValue);
        // (400 000 + min(250 000, 200 000)) / 1 200 000
        self::assertSame(50.0, $sabre->fulfillmentPercent);
    }

    public function testInProgressProjectWithoutBom(): void
    {
        $jackdaw = $this->availableProjectsById()[$this->jackdawProjectId];

        self::assertSame('in_progress', $jackdaw->status);
        self::assertSame('Brann Holt', $jackdaw->ownerCharacterName);
        self::assertSame(1, $jackdaw->membersCount);
        self::assertSame([
            ['typeId' => self::JACKDAW_TYPE_ID, 'typeName' => 'Jackdaw', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 20],
        ], $jackdaw->items);
        self::assertSame(0.0, $jackdaw->totalBomValue);
        self::assertSame(0.0, $jackdaw->fulfillmentPercent);
    }

    private function user(string $userId): User
    {
        $user = $this->em->find(User::class, $userId);
        \assert($user instanceof User);

        return $user;
    }

    /** @return array<string, GroupIndustryProjectResource> */
    private function availableProjectsById(): array
    {
        $byId = [];
        foreach ($this->listAvailableProjectsAs($this->user($this->viewerId)) as $project) {
            $byId[$project->id] = $project;
        }

        return $byId;
    }

    /** @return list<string> every SQL query issued while listing the projects available to the user */
    private function queriesListingAvailableProjectsAs(string $userId): array
    {
        $this->em->clear();
        $user = $this->user($userId);

        return $this->sqlExecutedDuring(fn () => $this->listAvailableProjectsAs($user));
    }

    /** @return GroupIndustryProjectResource[] */
    private function listAvailableProjectsAs(User $user): array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $provider = new AvailableGroupProjectsProvider(
            $security,
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupIndustryProjectMemberRepository::class),
            self::getContainer()->get(GroupIndustryResourceMapper::class),
        );

        return $provider->provide(new GetCollection());
    }

    /**
     * A viewer and published projects of its corporation, each with its own owner and other accepted
     * members (all with a main character), one item per BOM item, and BOM items 25 % fulfilled.
     *
     * @return string the viewer id
     */
    private function createCorporationWithOpenProjects(int $corporationId, int $projectsCount, int $membersPerProject, int $bomItemsPerProject): string
    {
        $viewer = $this->createUserWithMainCharacter(sprintf('Viewer %d', $corporationId), $corporationId);

        for ($projectIndex = 1; $projectIndex <= $projectsCount; ++$projectIndex) {
            $owner = $this->createUserWithMainCharacter(sprintf('Owner %d %d', $corporationId, $projectIndex), $corporationId);
            $project = $this->createProject($owner, sprintf('Project %d %d', $corporationId, $projectIndex), GroupProjectStatus::Published);
            $this->addMember($project, $owner, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
            for ($memberIndex = 2; $memberIndex <= $membersPerProject; ++$memberIndex) {
                $this->addMember(
                    $project,
                    $this->createUserWithMainCharacter(sprintf('Member %d of %d %d', $memberIndex, $corporationId, $projectIndex), $corporationId),
                    GroupMemberRole::Member,
                    GroupMemberStatus::Accepted,
                );
            }
            for ($bomIndex = 0; $bomIndex < $bomItemsPerProject; ++$bomIndex) {
                $this->addItem($project, self::SABRE_TYPE_ID + $bomIndex, sprintf('Product %d', $bomIndex), runs: 1);
                $this->addBomItem($project, self::TRITANIUM_TYPE_ID + $bomIndex, sprintf('Material %d', $bomIndex), requiredQuantity: 1_000, fulfilledQuantity: 250, estimatedPrice: 5.0);
            }
        }

        $this->em->flush();

        return (string) $viewer->getId();
    }

    private function createUserWithMainCharacter(string $characterName, int $corporationId): User
    {
        $user = $this->createUser();
        $user->setMainCharacter($this->createCharacter($user, $characterName)->setCorporationId($corporationId));

        return $user;
    }

    private function createProject(User $owner, string $name, GroupProjectStatus $status): GroupIndustryProject
    {
        $project = (new GroupIndustryProject())
            ->setOwner($owner)
            ->setName($name)
            ->setStatus($status);
        $this->em->persist($project);

        return $project;
    }

    private function addMember(GroupIndustryProject $project, User $user, GroupMemberRole $role, GroupMemberStatus $status): void
    {
        $project->addMember(
            (new GroupIndustryProjectMember())
                ->setUser($user)
                ->setRole($role)
                ->setStatus($status),
        );
    }

    private function addItem(GroupIndustryProject $project, int $typeId, string $typeName, int $runs): void
    {
        $project->addItem(
            (new GroupIndustryProjectItem())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setMeLevel(10)
                ->setTeLevel(20)
                ->setRuns($runs)
                ->setSortOrder($project->getItems()->count()),
        );
    }

    private function addBomItem(GroupIndustryProject $project, int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity, float $estimatedPrice): void
    {
        $project->addBomItem(
            (new GroupIndustryBomItem())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setRequiredQuantity($requiredQuantity)
                ->setFulfilledQuantity($fulfilledQuantity)
                ->setEstimatedPrice($estimatedPrice),
        );
    }
}
