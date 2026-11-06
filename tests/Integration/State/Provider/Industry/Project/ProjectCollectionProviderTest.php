<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Provider\Industry\Project;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\Industry\Project\IndustryProjectResource;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectContribution;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectProduct;
use App\Entity\IndustryProjectParticipant;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use App\State\Provider\Industry\Project\ProjectCollectionProvider;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Issue #38: listing a user's group projects must not lazy-load, project by project, the owner
 * and its main character, the items, every member (only to count them) and the BOM items.
 * The number of queries must stay bounded whatever the number of projects and members, and the
 * listed values must stay exactly the same.
 */
final class ProjectCollectionProviderTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    /** whole collection, whatever the number of projects and members */
    private const int MAX_QUERIES = 5;

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

    /** Issue #39: the same queries with 2 projects x 2 members x 2 BOM items as with 10 x 10 x 10. */
    public function testQueryCountDoesNotGrowWithProjectsMembersAndBomItems(): void
    {
        $pilotInTwoProjectsId = $this->createPilotAcceptedInProjects('Lyra Sato', projectsCount: 2, membersPerProject: 2, bomItemsPerProject: 2);
        $pilotInTenProjectsId = $this->createPilotAcceptedInProjects('Orin Vahl', projectsCount: 10, membersPerProject: 10, bomItemsPerProject: 10);

        $twoProjectsQueries = $this->queriesListingProjectsAs($pilotInTwoProjectsId);
        $tenProjectsQueries = $this->queriesListingProjectsAs($pilotInTenProjectsId);

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

    public function testTenProjectsAreListedWithTheirTenMembersAndTenBomItems(): void
    {
        $pilotInTenProjectsId = $this->createPilotAcceptedInProjects('Orin Vahl', projectsCount: 10, membersPerProject: 10, bomItemsPerProject: 10);
        $this->em->clear();
        $pilot = $this->em->find(User::class, $pilotInTenProjectsId);
        \assert($pilot instanceof User);

        $projects = $this->listProjectsAs($pilot);

        self::assertCount(10, $projects);
        foreach ($projects as $project) {
            self::assertSame(10, $project->membersCount);
            self::assertCount(10, $project->items);
            // 10 BOM items x 1 000 units x 5.0 ISK
            self::assertSame(50_000.0, $project->totalBomValue);
            // 10 x 250 fulfilled / 10 x 1 000 required
            self::assertSame(25.0, $project->fulfillmentPercent);
            self::assertSame(345_000.0, $project->approvedContributionValue);
            self::assertSame('member', $project->myRole);
            self::assertStringStartsWith('Owner of Orin Vahl ', $project->ownerCharacterName);
        }
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

    #[Group('issue-156')]
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
        self::assertSame(6_000_000.0, $sabre->totalBomValue);
        self::assertSame(70.0, $sabre->fulfillmentPercent);
        self::assertSame(1_250_000.0, $sabre->approvedContributionValue);
    }

    #[Group('issue-156')]
    public function testJackdawProjectFallsBackToUnknownOwnerAndHasAnUnknownBomValueWhenAMaterialHasNoPrice(): void
    {
        $jackdaw = $this->listedProjectsById()[$this->jackdawProjectId];

        self::assertSame('Unknown', $jackdaw->ownerCharacterName);
        self::assertNull($jackdaw->ownerCorporationId);
        self::assertSame('admin', $jackdaw->myRole);
        self::assertSame(4, $jackdaw->membersCount);
        self::assertSame([
            ['typeId' => self::JACKDAW_TYPE_ID, 'typeName' => 'Jackdaw', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 20],
        ], $jackdaw->items);
        self::assertNull($jackdaw->totalBomValue);
        self::assertSame(16.67, $jackdaw->fulfillmentPercent);
        self::assertSame(0.0, $jackdaw->approvedContributionValue);
    }

    #[Group('issue-363')]
    public function testProjectWithAnApprovedContributionWithoutValueHasAnUnknownApprovedContributionValueInsteadOfAPartialSum(): void
    {
        $sabre = $this->em->find(IndustryProject::class, $this->sabreProjectId);
        \assert($sabre instanceof IndustryProject);
        $owner = $sabre->getMembers()->filter(static fn (IndustryProjectParticipant $member): bool => $member->getRole() === ParticipantRole::Owner)->first();
        \assert($owner instanceof IndustryProjectParticipant);
        $this->addContribution($owner, ContributionType::JobInstall, null, ContributionStatus::Approved);
        $this->em->flush();
        $this->em->clear();

        $projects = $this->listedProjectsById();

        self::assertNull($projects[$this->sabreProjectId]->approvedContributionValue);
        self::assertSame(0.0, $projects[$this->jackdawProjectId]->approvedContributionValue);
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

    /** @return IndustryProjectResource[] */
    private function listProjectsAs(User $user): array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $provider = new ProjectCollectionProvider(
            $security,
            self::getContainer()->get(IndustryProjectParticipantRepository::class),
            self::getContainer()->get(ProjectResourceMapper::class),
            self::getContainer()->get(IndustryProjectContributionRepository::class),
        );

        return $provider->provide(new GetCollection());
    }

    /** @return array<string, IndustryProjectResource> */
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

    /** @return list<string> every SQL query issued while listing the projects of the user */
    private function queriesListingProjectsAs(string $userId): array
    {
        $this->em->clear();
        $user = $this->em->find(User::class, $userId);
        \assert($user instanceof User);

        return $this->sqlExecutedDuring(fn () => $this->listProjectsAs($user));
    }

    /**
     * Each project has its own owner, the pilot and other accepted members (each with a main
     * character), one item per BOM item, and BOM items 25 % fulfilled.
     */
    private function createPilotAcceptedInProjects(string $pilotName, int $projectsCount, int $membersPerProject, int $bomItemsPerProject): string
    {
        $pilot = $this->createUserWithMainCharacter($pilotName);

        for ($projectIndex = 1; $projectIndex <= $projectsCount; ++$projectIndex) {
            $owner = $this->createUserWithMainCharacter(sprintf('Owner of %s %d', $pilotName, $projectIndex));
            $project = $this->createProject($owner, sprintf('%s project %d', $pilotName, $projectIndex));
            $ownerMembership = $this->addMember($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
            $pilotMembership = $this->addMember($project, $pilot, ParticipantRole::Member, ParticipantStatus::Accepted);
            $this->addContribution($ownerMembership, ContributionType::Material, 300_000.0, ContributionStatus::Approved);
            $this->addContribution($pilotMembership, ContributionType::LineRental, 45_000.0, ContributionStatus::Approved);
            $this->addContribution($pilotMembership, ContributionType::Material, 70_000.0, ContributionStatus::Pending);
            for ($memberIndex = 3; $memberIndex <= $membersPerProject; ++$memberIndex) {
                $this->addMember(
                    $project,
                    $this->createUserWithMainCharacter(sprintf('Member %d of %s %d', $memberIndex, $pilotName, $projectIndex)),
                    ParticipantRole::Member,
                    ParticipantStatus::Accepted,
                );
            }
            for ($bomIndex = 0; $bomIndex < $bomItemsPerProject; ++$bomIndex) {
                $this->addItem($project, self::SABRE_TYPE_ID + $bomIndex, sprintf('Product %d', $bomIndex), meLevel: 10, teLevel: 20, runs: 1);
                $this->addBomItem($project, self::TRITANIUM_TYPE_ID + $bomIndex, sprintf('Material %d', $bomIndex), requiredQuantity: 1_000, fulfilledQuantity: 250, estimatedPrice: 5.0);
            }
        }

        $this->em->flush();

        return (string) $pilot->getId();
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
        $ownerMembership = $this->addMember($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addMember($project, $pilot, ParticipantRole::Member, ParticipantStatus::Accepted);
        $adminMembership = $this->addMember($project, $this->createUserWithMainCharacter('Brann Holt'), ParticipantRole::Admin, ParticipantStatus::Accepted);
        $this->addMember($project, $this->createUser(), ParticipantRole::Member, ParticipantStatus::Pending);
        $this->addContribution($ownerMembership, ContributionType::Material, 1_000_000.0, ContributionStatus::Approved);
        $this->addContribution($adminMembership, ContributionType::LineRental, 250_000.0, ContributionStatus::Approved);
        $this->addContribution($adminMembership, ContributionType::Material, 500_000.0, ContributionStatus::Pending);
        $this->addContribution($ownerMembership, ContributionType::JobInstall, 100_000.0, ContributionStatus::Rejected);

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
        $this->addMember($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addMember($project, $pilot, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $cyraMembership = $this->addMember($project, $this->createUserWithMainCharacter('Cyra Lune'), ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->addMember($project, $this->createUserWithMainCharacter('Dorn Kessel'), ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->addContribution($cyraMembership, ContributionType::Material, 500_000.0, ContributionStatus::Pending);

        $this->addItem($project, self::JACKDAW_TYPE_ID, 'Jackdaw', meLevel: 10, teLevel: 20, runs: 20);

        $this->addBomItem($project, self::MEXALLON_TYPE_ID, 'Mexallon', requiredQuantity: 300_000, fulfilledQuantity: 100_000, estimatedPrice: 50.0);
        $this->addBomItem($project, self::ISOGEN_TYPE_ID, 'Isogen', requiredQuantity: 100_000, fulfilledQuantity: 0, estimatedPrice: null);

        return $this->idOf($project);
    }

    private function createIshtarProjectOwnedBy(User $owner): string
    {
        $project = $this->createProject($owner, 'Ishtar fleet');
        $this->addMember($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addMember($project, $this->createUserWithMainCharacter('Eryn Vale'), ParticipantRole::Member, ParticipantStatus::Accepted);
        $this->addMember($project, $this->createUser(), ParticipantRole::Member, ParticipantStatus::Pending);
        $this->addMember($project, $this->createUser(), ParticipantRole::Member, ParticipantStatus::Pending);

        $this->addItem($project, self::ISHTAR_TYPE_ID, 'Ishtar', meLevel: 10, teLevel: 20, runs: 3);

        return $this->idOf($project);
    }

    private function createHurricaneProjectWherePending(User $pilot): string
    {
        $owner = $this->createUserWithMainCharacter('Fenn Draker');
        $project = $this->createProject($owner, 'Hurricane line');
        $this->addMember($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addMember($project, $pilot, ParticipantRole::Member, ParticipantStatus::Pending);

        $this->addItem($project, self::HURRICANE_TYPE_ID, 'Hurricane', meLevel: 10, teLevel: 20, runs: 1);

        return $this->idOf($project);
    }

    private function createUserWithMainCharacter(string $characterName): User
    {
        $user = $this->createUser();
        $user->setMainCharacter($this->createCharacter($user, $characterName));

        return $user;
    }

    private function createProject(User $owner, string $name): IndustryProject
    {
        $project = (new IndustryProject())
            ->setOwner($owner)
            ->setName($name);
        $this->em->persist($project);

        return $project;
    }

    private function addMember(IndustryProject $project, User $user, ParticipantRole $role, ParticipantStatus $status): IndustryProjectParticipant
    {
        $member = (new IndustryProjectParticipant())
            ->setUser($user)
            ->setRole($role)
            ->setStatus($status);
        $project->addMember($member);

        return $member;
    }

    private function addContribution(IndustryProjectParticipant $member, ContributionType $type, ?float $estimatedValue, ContributionStatus $status): void
    {
        $member->getProject()->addContribution(
            (new IndustryProjectContribution())
                ->setMember($member)
                ->setType($type)
                ->setQuantity(1)
                ->setEstimatedValue($estimatedValue)
                ->setStatus($status),
        );
    }

    private function addItem(IndustryProject $project, int $typeId, string $typeName, int $meLevel, int $teLevel, int $runs): void
    {
        $project->addItem(
            (new IndustryProjectProduct())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setMeLevel($meLevel)
                ->setTeLevel($teLevel)
                ->setRuns($runs)
                ->setSortOrder($project->getItems()->count()),
        );
    }

    private function addBomItem(IndustryProject $project, int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity, ?float $estimatedPrice): IndustryProjectBomLine
    {
        $bomItem = (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($requiredQuantity)
            ->setFulfilledQuantity($fulfilledQuantity)
            ->setEstimatedPrice($estimatedPrice);
        $project->addBomItem($bomItem);

        return $bomItem;
    }

    private function idOf(IndustryProject $project): string
    {
        $this->em->flush();
        $id = $project->getId();
        \assert($id !== null);

        return $id->toRfc4122();
    }
}
