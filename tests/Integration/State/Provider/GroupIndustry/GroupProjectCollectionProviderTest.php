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
use App\Repository\GroupIndustryProjectMemberRepository;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupProjectCollectionProvider;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Issue #38: listing a user's group projects must not lazy-load, project by project, the owner
 * and its main character, the items, every member (only to count them) and the BOM items.
 * The number of queries must stay bounded whatever the number of projects and members, and the
 * listed values must stay exactly the same.
 */
final class GroupProjectCollectionProviderTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    /** whole collection, whatever the number of projects and members */
    private const int MAX_QUERIES = 4;

    /** Group industry tables + owners and their characters (SDE tables are prefixed `sde_` and excluded). */
    private const string GROUP_INDUSTRY_TABLES_PATTERN = '/\b(group_industry_[a-z_]+|users|characters)\b/';

    private const int SABRE_TYPE_ID = 22456;
    private const int PURIFIER_TYPE_ID = 12038;
    private const int JACKDAW_TYPE_ID = 34828;
    private const int ISHTAR_TYPE_ID = 12005;
    private const int HURRICANE_TYPE_ID = 24702;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int MEXALLON_TYPE_ID = 36;
    private const int ISOGEN_TYPE_ID = 37;

    private string $pilotId;
    private string $sabreProjectId;
    private string $jackdawProjectId;
    private string $ishtarProjectId;
    private string $pendingHurricaneProjectId;
    private string $otherPilotProjectId;

    protected function setUp(): void
    {
        parent::setUp();
        $pilot = $this->createUserWithMainCharacter('Kaelen Voss');
        $this->sabreProjectId = $this->createSabreProject($pilot);
        $this->jackdawProjectId = $this->createJackdawProject($pilot);
        $this->ishtarProjectId = $this->createIshtarProjectOwnedBy($pilot);
        $this->pendingHurricaneProjectId = $this->createHurricaneProjectWherePending($pilot);
        $this->otherPilotProjectId = $this->createIshtarProjectOwnedBy($this->createUserWithMainCharacter('Nyx Arden'));
        $this->em->flush();
        $this->pilotId = (string) $pilot->getId();
        $this->em->clear();
    }

    public function testListingGroupProjectsIssuesABoundedNumberOfQueries(): void
    {
        $pilot = $this->em->find(User::class, $this->pilotId);
        \assert($pilot instanceof User);

        $queries = $this->queriesMatching(
            $this->sqlExecutedDuring(fn () => $this->listProjectsAs($pilot)),
            self::GROUP_INDUSTRY_TABLES_PATTERN,
        );

        self::assertLessThanOrEqual(
            self::MAX_QUERIES,
            \count($queries),
            sprintf(
                "3 projects x 4 members: expected at most %d queries, got %d:\n%s",
                self::MAX_QUERIES,
                \count($queries),
                implode("\n", $queries),
            ),
        );
    }

    public function testListingReturnsOnlyProjectsWhereTheUserIsAnAcceptedMember(): void
    {
        $projects = $this->listedProjectsById();

        self::assertEqualsCanonicalizing(
            [$this->sabreProjectId, $this->jackdawProjectId, $this->ishtarProjectId],
            array_keys($projects),
        );
        self::assertArrayNotHasKey($this->pendingHurricaneProjectId, $projects);
        self::assertArrayNotHasKey($this->otherPilotProjectId, $projects);
    }

    public function testSabreProjectCountsAcceptedMembersAndCapsFulfilledQuantityPerBomItem(): void
    {
        $sabre = $this->listedProjectsById()[$this->sabreProjectId];

        self::assertSame('Sabre doctrine', $sabre->name);
        self::assertSame('Aria Solberg', $sabre->ownerCharacterName);
        self::assertSame(98_000_001, $sabre->ownerCorporationId);
        self::assertSame('member', $sabre->myRole);
        // owner + pilot + admin accepted, the pending member is not counted
        self::assertSame(3, $sabre->membersCount);
        self::assertSame([
            ['typeId' => self::PURIFIER_TYPE_ID, 'typeName' => 'Purifier', 'meLevel' => 8, 'teLevel' => 16, 'runs' => 5],
            ['typeId' => self::SABRE_TYPE_ID, 'typeName' => 'Sabre', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10],
        ], $this->sortedByTypeId($sabre->items));
        // Tritanium 1 000 000 x 4.0 + Pyerite 200 000 x 10.0, the job line is ignored
        self::assertSame(6_000_000.0, $sabre->totalBomValue);
        // (400 000 + min(250 000, 200 000)) / 1 200 000
        self::assertSame(50.0, $sabre->fulfillmentPercent);
    }

    public function testJackdawProjectFallsBackToUnknownOwnerAndIgnoresMissingPrices(): void
    {
        $jackdaw = $this->listedProjectsById()[$this->jackdawProjectId];

        self::assertSame('Unknown', $jackdaw->ownerCharacterName);
        self::assertNull($jackdaw->ownerCorporationId);
        self::assertSame('admin', $jackdaw->myRole);
        self::assertSame(4, $jackdaw->membersCount);
        self::assertSame([
            ['typeId' => self::JACKDAW_TYPE_ID, 'typeName' => 'Jackdaw', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 20],
        ], $jackdaw->items);
        // Mexallon 300 000 x 50.0, Isogen has no estimated price
        self::assertSame(15_000_000.0, $jackdaw->totalBomValue);
        // 100 000 / (300 000 + 100 000)
        self::assertSame(25.0, $jackdaw->fulfillmentPercent);
    }

    public function testIshtarProjectOwnedByTheUserWithoutBom(): void
    {
        $ishtar = $this->listedProjectsById()[$this->ishtarProjectId];

        self::assertSame('Kaelen Voss', $ishtar->ownerCharacterName);
        self::assertSame('owner', $ishtar->myRole);
        // owner + 1 accepted, 2 pending
        self::assertSame(2, $ishtar->membersCount);
        self::assertSame([
            ['typeId' => self::ISHTAR_TYPE_ID, 'typeName' => 'Ishtar', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 3],
        ], $ishtar->items);
        self::assertSame(0.0, $ishtar->totalBomValue);
        self::assertSame(0.0, $ishtar->fulfillmentPercent);
    }

    /** @return GroupIndustryProjectResource[] */
    private function listProjectsAs(User $user): array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $provider = new GroupProjectCollectionProvider(
            $security,
            self::getContainer()->get(GroupIndustryProjectMemberRepository::class),
            self::getContainer()->get(GroupIndustryResourceMapper::class),
        );

        return $provider->provide(new GetCollection());
    }

    /** @return array<string, GroupIndustryProjectResource> */
    private function listedProjectsById(): array
    {
        $pilot = $this->em->find(User::class, $this->pilotId);
        \assert($pilot instanceof User);

        $byId = [];
        foreach ($this->listProjectsAs($pilot) as $project) {
            $byId[$project->id] = $project;
        }

        return $byId;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function sortedByTypeId(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => $a['typeId'] <=> $b['typeId']);

        return $items;
    }

    private function createSabreProject(User $pilot): string
    {
        $owner = $this->createUserWithMainCharacter('Aria Solberg');
        $project = $this->createProject($owner, 'Sabre doctrine');
        $this->addMember($project, $owner, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->addMember($project, $pilot, GroupMemberRole::Member, GroupMemberStatus::Accepted);
        $this->addMember($project, $this->createUserWithMainCharacter('Brann Holt'), GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $this->addMember($project, $this->createUser(), GroupMemberRole::Member, GroupMemberStatus::Pending);

        $this->addItem($project, self::SABRE_TYPE_ID, 'Sabre', meLevel: 10, teLevel: 20, runs: 10);
        $this->addItem($project, self::PURIFIER_TYPE_ID, 'Purifier', meLevel: 8, teLevel: 16, runs: 5);

        $this->addBomItem($project, self::TRITANIUM_TYPE_ID, 'Tritanium', requiredQuantity: 1_000_000, fulfilledQuantity: 400_000, estimatedPrice: 4.0);
        $this->addBomItem($project, self::PYERITE_TYPE_ID, 'Pyerite', requiredQuantity: 200_000, fulfilledQuantity: 250_000, estimatedPrice: 10.0);
        $this->addBomItem($project, self::SABRE_TYPE_ID, 'Sabre', requiredQuantity: 10, fulfilledQuantity: 0, estimatedPrice: 50_000_000.0)
            ->setIsJob(true)
            ->setJobGroup('final')
            ->setActivityType('manufacturing')
            ->setRuns(10);

        return $this->idOf($project);
    }

    private function createJackdawProject(User $pilot): string
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner, 'Jackdaw batch');
        $this->addMember($project, $owner, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->addMember($project, $pilot, GroupMemberRole::Admin, GroupMemberStatus::Accepted);
        $this->addMember($project, $this->createUserWithMainCharacter('Cyra Lune'), GroupMemberRole::Member, GroupMemberStatus::Accepted);
        $this->addMember($project, $this->createUserWithMainCharacter('Dorn Kessel'), GroupMemberRole::Member, GroupMemberStatus::Accepted);

        $this->addItem($project, self::JACKDAW_TYPE_ID, 'Jackdaw', meLevel: 10, teLevel: 20, runs: 20);

        $this->addBomItem($project, self::MEXALLON_TYPE_ID, 'Mexallon', requiredQuantity: 300_000, fulfilledQuantity: 100_000, estimatedPrice: 50.0);
        $this->addBomItem($project, self::ISOGEN_TYPE_ID, 'Isogen', requiredQuantity: 100_000, fulfilledQuantity: 0, estimatedPrice: null);

        return $this->idOf($project);
    }

    private function createIshtarProjectOwnedBy(User $owner): string
    {
        $project = $this->createProject($owner, 'Ishtar fleet');
        $this->addMember($project, $owner, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->addMember($project, $this->createUserWithMainCharacter('Eryn Vale'), GroupMemberRole::Member, GroupMemberStatus::Accepted);
        $this->addMember($project, $this->createUser(), GroupMemberRole::Member, GroupMemberStatus::Pending);
        $this->addMember($project, $this->createUser(), GroupMemberRole::Member, GroupMemberStatus::Pending);

        $this->addItem($project, self::ISHTAR_TYPE_ID, 'Ishtar', meLevel: 10, teLevel: 20, runs: 3);

        return $this->idOf($project);
    }

    private function createHurricaneProjectWherePending(User $pilot): string
    {
        $owner = $this->createUserWithMainCharacter('Fenn Draker');
        $project = $this->createProject($owner, 'Hurricane line');
        $this->addMember($project, $owner, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        $this->addMember($project, $pilot, GroupMemberRole::Member, GroupMemberStatus::Pending);

        $this->addItem($project, self::HURRICANE_TYPE_ID, 'Hurricane', meLevel: 10, teLevel: 20, runs: 1);

        return $this->idOf($project);
    }

    private function createUserWithMainCharacter(string $characterName): User
    {
        $user = $this->createUser();
        $user->setMainCharacter($this->createCharacter($user, $characterName));

        return $user;
    }

    private function createProject(User $owner, string $name): GroupIndustryProject
    {
        $project = (new GroupIndustryProject())
            ->setOwner($owner)
            ->setName($name);
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

    private function addItem(GroupIndustryProject $project, int $typeId, string $typeName, int $meLevel, int $teLevel, int $runs): void
    {
        $project->addItem(
            (new GroupIndustryProjectItem())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setMeLevel($meLevel)
                ->setTeLevel($teLevel)
                ->setRuns($runs)
                ->setSortOrder($project->getItems()->count()),
        );
    }

    private function addBomItem(GroupIndustryProject $project, int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity, ?float $estimatedPrice): GroupIndustryBomItem
    {
        $bomItem = (new GroupIndustryBomItem())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setFulfilledQuantity($fulfilledQuantity)
            ->setEstimatedPrice($estimatedPrice);
        $project->addBomItem($bomItem);

        return $bomItem;
    }

    private function idOf(GroupIndustryProject $project): string
    {
        $this->em->flush();
        $id = $project->getId();
        \assert($id !== null);

        return $id->toRfc4122();
    }
}
