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
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectParticipantRepository;
use App\Repository\IndustryProjectRepository;
use App\State\Provider\Industry\Project\AvailableProjectsProvider;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Issue #39: listing the group projects open to the user's corporation must not lazy-load,
 * project by project, the owner and its main character, the user's membership, the items,
 * every member (only to count them) and the BOM items. The number of queries must not depend
 * on the number of projects, members and BOM items, and the listed values must stay the same.
 */
final class AvailableProjectsProviderTest extends IntegrationTestCase
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
    private string $leftProjectId;
    private string $privateProjectId;
    private string $inProgressProjectId;

    protected function setUp(): void
    {
        parent::setUp();

        $viewer = $this->createUserWithMainCharacter('Kaelen Voss', self::VIEWER_CORPORATION_ID);
        $aria = $this->createUserWithMainCharacter('Aria Solberg', self::VIEWER_CORPORATION_ID);
        $brann = $this->createUserWithMainCharacter('Brann Holt', self::VIEWER_CORPORATION_ID);
        $cyra = $this->createUserWithMainCharacter('Cyra Lune', self::VIEWER_CORPORATION_ID);
        $nyx = $this->createUserWithMainCharacter('Nyx Arden', self::OTHER_CORPORATION_ID);

        $sabre = $this->createProject($aria, 'Sabre doctrine', ProjectStatus::Published);
        $ariaInSabre = $this->addMember($sabre, $aria, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $brannInSabre = $this->addMember($sabre, $brann, ParticipantRole::Admin, ParticipantStatus::Accepted);
        $this->addMember($sabre, $cyra, ParticipantRole::Member, ParticipantStatus::Pending);
        $this->addContribution($ariaInSabre, ContributionType::Material, 1_000_000.0, ContributionStatus::Approved);
        $this->addContribution($brannInSabre, ContributionType::LineRental, 250_000.0, ContributionStatus::Approved);
        $this->addContribution($brannInSabre, ContributionType::Material, 500_000.0, ContributionStatus::Pending);
        $this->addContribution($ariaInSabre, ContributionType::JobInstall, 100_000.0, ContributionStatus::Rejected);
        $this->addItem($sabre, self::SABRE_TYPE_ID, 'Sabre', runs: 10);
        $this->addBomItem($sabre, self::TRITANIUM_TYPE_ID, 'Tritanium', requiredQuantity: 1_000_000, fulfilledQuantity: 400_000, estimatedPrice: 4.0);
        $this->addBomItem($sabre, self::PYERITE_TYPE_ID, 'Pyerite', requiredQuantity: 200_000, fulfilledQuantity: 250_000, estimatedPrice: 10.0);

        $jackdaw = $this->createProject($brann, 'Jackdaw batch', ProjectStatus::Published);
        $brannInJackdaw = $this->addMember($jackdaw, $brann, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addContribution($brannInJackdaw, ContributionType::Material, 640_000.0, ContributionStatus::Pending);
        $this->addItem($jackdaw, self::JACKDAW_TYPE_ID, 'Jackdaw', runs: 20);

        $private = $this->createProject($aria, 'Private project', ProjectStatus::Published, private: true);
        $this->addMember($private, $aria, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addItem($private, self::PYERITE_TYPE_ID, 'Private hull', runs: 2);
        $inProgress = $this->createProject($brann, 'Hurricane batch', ProjectStatus::InProgress);
        $this->addMember($inProgress, $brann, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addItem($inProgress, self::TRITANIUM_TYPE_ID, 'Hurricane', runs: 3);
        $draft = $this->createProject($aria, 'Draft project', ProjectStatus::Draft);
        $this->addMember($draft, $aria, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $selling = $this->createProject($aria, 'Selling project', ProjectStatus::Selling);
        $this->addMember($selling, $aria, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $alreadyJoined = $this->createProject($cyra, 'Already joined', ProjectStatus::Published);
        $this->addMember($alreadyJoined, $cyra, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addMember($alreadyJoined, $viewer, ParticipantRole::Member, ParticipantStatus::Pending);
        $leftProject = $this->createProject($brann, 'Left project', ProjectStatus::Published);
        $this->addMember($leftProject, $brann, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $this->addMember($leftProject, $viewer, ParticipantRole::Member, ParticipantStatus::Removed);
        $ownProject = $this->createProject($viewer, 'Own project', ProjectStatus::Published);
        $this->addMember($ownProject, $viewer, ParticipantRole::Owner, ParticipantStatus::Accepted);
        $otherCorporation = $this->createProject($nyx, 'Other corporation', ProjectStatus::Published);
        $this->addMember($otherCorporation, $nyx, ParticipantRole::Owner, ParticipantStatus::Accepted);

        $this->em->flush();
        $this->viewerId = (string) $viewer->getId();
        $this->sabreProjectId = (string) $sabre->getId();
        $this->jackdawProjectId = (string) $jackdaw->getId();
        $this->leftProjectId = (string) $leftProject->getId();
        $this->privateProjectId = (string) $private->getId();
        $this->inProgressProjectId = (string) $inProgress->getId();
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
            self::assertSame(415_000.0, $project->approvedContributionValue);
            self::assertSame(98_100_010, $project->ownerCorporationId);
            self::assertStringStartsWith('Owner 98100010 ', $project->ownerCharacterName);
        }
    }

    #[Group('issue-362')]
    public function testListsOnlyPublishedNotPrivateProjectsOfTheCorporationTheUserHasNotJoined(): void
    {
        $projects = $this->availableProjectsById();

        self::assertEqualsCanonicalizing([$this->sabreProjectId, $this->jackdawProjectId], array_keys($projects));
    }

    #[Group('issue-362')]
    public function testPrivatePublishedProjectIsNotListed(): void
    {
        self::assertArrayNotHasKey($this->privateProjectId, $this->availableProjectsById());
    }

    #[Group('issue-362')]
    public function testInProgressProjectIsNotListed(): void
    {
        self::assertArrayNotHasKey($this->inProgressProjectId, $this->availableProjectsById());
    }

    #[Group('issue-362')]
    public function testProjectTheOwnerOpensToTheDiscoveryAppearsAndOneTheOwnerClosesAgainDisappears(): void
    {
        $private = $this->em->find(IndustryProject::class, $this->privateProjectId);
        \assert($private instanceof IndustryProject);
        $private->setPrivate(false);
        $this->em->flush();
        $this->em->clear();

        self::assertArrayHasKey($this->privateProjectId, $this->availableProjectsById());

        $this->em->find(IndustryProject::class, $this->privateProjectId)->setPrivate(true);
        $this->em->flush();
        $this->em->clear();

        self::assertArrayNotHasKey($this->privateProjectId, $this->availableProjectsById());
    }

    public function testPublishedProjectOfTheCorporationTheUserWasRemovedFromIsNotListed(): void
    {
        $projects = $this->availableProjectsById();

        self::assertArrayNotHasKey($this->leftProjectId, $projects);
    }

    #[Group('issue-156')]
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
        self::assertSame(6_000_000.0, $sabre->totalBomValue);
        self::assertSame(70.0, $sabre->fulfillmentPercent);
        self::assertSame(1_250_000.0, $sabre->approvedContributionValue);
    }

    public function testPublishedProjectWithoutBom(): void
    {
        $jackdaw = $this->availableProjectsById()[$this->jackdawProjectId];

        self::assertSame('published', $jackdaw->status);
        self::assertSame('Brann Holt', $jackdaw->ownerCharacterName);
        self::assertSame(1, $jackdaw->membersCount);
        self::assertSame([
            ['typeId' => self::JACKDAW_TYPE_ID, 'typeName' => 'Jackdaw', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 20],
        ], $jackdaw->items);
        self::assertSame(0.0, $jackdaw->totalBomValue);
        self::assertSame(0.0, $jackdaw->fulfillmentPercent);
        self::assertSame(0.0, $jackdaw->approvedContributionValue);
    }

    private function user(string $userId): User
    {
        $user = $this->em->find(User::class, $userId);
        \assert($user instanceof User);

        return $user;
    }

    /** @return array<string, IndustryProjectResource> */
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

    /** @return IndustryProjectResource[] */
    private function listAvailableProjectsAs(User $user): array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $provider = new AvailableProjectsProvider(
            $security,
            self::getContainer()->get(IndustryProjectRepository::class),
            self::getContainer()->get(IndustryProjectParticipantRepository::class),
            self::getContainer()->get(ProjectResourceMapper::class),
            self::getContainer()->get(IndustryProjectContributionRepository::class),
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
            $project = $this->createProject($owner, sprintf('Project %d %d', $corporationId, $projectIndex), ProjectStatus::Published);
            $ownerMembership = $this->addMember($project, $owner, ParticipantRole::Owner, ParticipantStatus::Accepted);
            $this->addContribution($ownerMembership, ContributionType::Material, 380_000.0, ContributionStatus::Approved);
            for ($memberIndex = 2; $memberIndex <= $membersPerProject; ++$memberIndex) {
                $membership = $this->addMember(
                    $project,
                    $this->createUserWithMainCharacter(sprintf('Member %d of %d %d', $memberIndex, $corporationId, $projectIndex), $corporationId),
                    ParticipantRole::Member,
                    ParticipantStatus::Accepted,
                );
                if ($memberIndex === 2) {
                    $this->addContribution($membership, ContributionType::JobInstall, 35_000.0, ContributionStatus::Approved);
                    $this->addContribution($membership, ContributionType::Material, 90_000.0, ContributionStatus::Rejected);
                }
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

    private function createProject(User $owner, string $name, ProjectStatus $status, bool $private = false): IndustryProject
    {
        $project = (new IndustryProject())
            ->setOwner($owner)
            ->setName($name)
            ->setStatus($status)
            ->setPrivate($private);
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

    private function addContribution(IndustryProjectParticipant $member, ContributionType $type, float $estimatedValue, ContributionStatus $status): void
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

    private function addItem(IndustryProject $project, int $typeId, string $typeName, int $runs): void
    {
        $project->addItem(
            (new IndustryProjectProduct())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setMeLevel(10)
                ->setTeLevel(20)
                ->setRuns($runs)
                ->setSortOrder($project->getItems()->count()),
        );
    }

    private function addBomItem(IndustryProject $project, int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity, float $estimatedPrice): void
    {
        $project->addBomItem(
            (new IndustryProjectBomLine())
                ->setTypeId($typeId)
                ->setTypeName($typeName)
                ->setRequiredQuantity($requiredQuantity)
                ->setFulfilledQuantity($fulfilledQuantity)
                ->setEstimatedPrice($estimatedPrice),
        );
    }
}
