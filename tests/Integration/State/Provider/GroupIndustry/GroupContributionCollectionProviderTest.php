<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\GroupIndustry\GroupIndustryContributionResource;
use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryContributionRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\State\Provider\GroupIndustry\GroupContributionCollectionProvider;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Issue #38: listing the contributions of a group project must not lazy-load, contribution by
 * contribution, the member, its user and main character, the BOM item and the reviewer.
 * The number of queries must stay bounded whatever the number of contributions, and the
 * listed values must stay exactly the same.
 */
final class GroupContributionCollectionProviderTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    /** project + access check + contributions, whatever the number of contributions */
    private const int MAX_QUERIES = 3;

    /** Group industry tables + members' users and characters (SDE tables are prefixed `sde_` and excluded). */
    private const string GROUP_INDUSTRY_TABLES_PATTERN = '/\b(group_industry_[a-z_]+|users|characters)\b/';

    private const int SABRE_TYPE_ID = 22456;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int MEXALLON_TYPE_ID = 36;

    private string $viewerId;
    private string $projectId;

    /** @var array<string, string> contribution id by fixture label */
    private array $contributionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $aria = $this->createUserWithMainCharacter('Aria Solberg');
        $brann = $this->createUserWithMainCharacter('Brann Holt');
        $cyra = $this->createUserWithMainCharacter('Cyra Lune');
        $dornWithoutMainCharacter = $this->createUser();
        $viewer = $this->createUserWithMainCharacter('Kaelen Voss');

        $project = (new GroupIndustryProject())->setOwner($aria)->setName('Sabre doctrine');
        $this->em->persist($project);
        $ariaMember = $this->addMember($project, $aria, GroupMemberRole::Owner);
        $brannMember = $this->addMember($project, $brann, GroupMemberRole::Admin);
        $cyraMember = $this->addMember($project, $cyra, GroupMemberRole::Member);
        $dornMember = $this->addMember($project, $dornWithoutMainCharacter, GroupMemberRole::Member);
        $viewerMember = $this->addMember($project, $viewer, GroupMemberRole::Member);

        $tritanium = $this->addBomItem($project, self::TRITANIUM_TYPE_ID, 'Tritanium');
        $pyerite = $this->addBomItem($project, self::PYERITE_TYPE_ID, 'Pyerite');
        $mexallon = $this->addBomItem($project, self::MEXALLON_TYPE_ID, 'Mexallon');
        $sabreJob = $this->addBomItem($project, self::SABRE_TYPE_ID, 'Sabre')->setIsJob(true)->setRuns(10);

        $this->addContribution('tritanium', $project, $cyraMember, $tritanium, ContributionType::Material, quantity: 400_000, estimatedValue: 1_600_000.0, status: ContributionStatus::Approved, reviewedBy: $aria);
        $this->addContribution('pyerite', $project, $dornMember, $pyerite, ContributionType::Material, quantity: 250_000, estimatedValue: 2_500_000.0, status: ContributionStatus::Approved, reviewedBy: $brann);
        $this->addContribution('sabreJob', $project, $brannMember, $sabreJob, ContributionType::JobInstall, quantity: 10, estimatedValue: 12_000_000.0, status: ContributionStatus::Pending, reviewedBy: null)
            ->setIsAutoDetected(true);
        $this->addContribution('lineRental', $project, $cyraMember, null, ContributionType::LineRental, quantity: 5, estimatedValue: 3_000_000.0, status: ContributionStatus::Rejected, reviewedBy: $aria)
            ->setNote('Slot already paid');
        $this->addContribution('bpc', $project, $viewerMember, null, ContributionType::Bpc, quantity: 2, estimatedValue: 8_000_000.0, status: ContributionStatus::Pending, reviewedBy: null);
        $this->addContribution('mexallon', $project, $ariaMember, $mexallon, ContributionType::Material, quantity: 50_000, estimatedValue: 2_500_000.0, status: ContributionStatus::Approved, reviewedBy: $brann)
            ->setIsVerified(true);

        $otherProject = (new GroupIndustryProject())->setOwner($brann)->setName('Other project');
        $this->em->persist($otherProject);
        $otherMember = $this->addMember($otherProject, $brann, GroupMemberRole::Owner);
        $this->addContribution('otherProject', $otherProject, $otherMember, null, ContributionType::LineRental, quantity: 1, estimatedValue: 1_000_000.0, status: ContributionStatus::Pending, reviewedBy: null);

        $this->em->flush();
        $this->viewerId = (string) $viewer->getId();
        $this->projectId = (string) $project->getId();
        $this->em->clear();
    }

    public function testListingContributionsIssuesABoundedNumberOfQueries(): void
    {
        $viewer = $this->viewer();

        $queries = $this->queriesMatching(
            $this->sqlExecutedDuring(fn () => $this->listContributionsAs($viewer)),
            self::GROUP_INDUSTRY_TABLES_PATTERN,
        );

        self::assertLessThanOrEqual(
            self::MAX_QUERIES,
            \count($queries),
            sprintf(
                "6 contributions from 5 members: expected at most %d queries, got %d:\n%s",
                self::MAX_QUERIES,
                \count($queries),
                implode("\n", $queries),
            ),
        );
    }

    public function testListingReturnsOnlyTheProjectContributions(): void
    {
        $contributions = $this->listedContributionsByLabel();

        self::assertEqualsCanonicalizing(
            ['tritanium', 'pyerite', 'sabreJob', 'lineRental', 'bpc', 'mexallon'],
            array_keys($contributions),
        );
    }

    public function testMaterialContributionShowsMemberBomItemAndReviewer(): void
    {
        $tritanium = $this->listedContributionsByLabel()['tritanium'];

        self::assertSame('Cyra Lune', $tritanium->memberCharacterName);
        self::assertSame('Tritanium', $tritanium->bomItemTypeName);
        self::assertSame('material', $tritanium->type);
        self::assertSame(400_000, $tritanium->quantity);
        self::assertSame(1_600_000.0, $tritanium->estimatedValue);
        self::assertSame('approved', $tritanium->status);
        self::assertSame('Aria Solberg', $tritanium->reviewedByCharacterName);
        self::assertFalse($tritanium->isVerified);
    }

    public function testMemberWithoutMainCharacterIsShownAsUnknown(): void
    {
        $pyerite = $this->listedContributionsByLabel()['pyerite'];

        self::assertSame('Unknown', $pyerite->memberCharacterName);
        self::assertSame('Pyerite', $pyerite->bomItemTypeName);
        self::assertSame(250_000, $pyerite->quantity);
        self::assertSame('Brann Holt', $pyerite->reviewedByCharacterName);
    }

    public function testAutoDetectedJobInstallIsPendingWithoutReviewer(): void
    {
        $sabreJob = $this->listedContributionsByLabel()['sabreJob'];

        self::assertSame('Brann Holt', $sabreJob->memberCharacterName);
        self::assertSame('Sabre', $sabreJob->bomItemTypeName);
        self::assertSame('job_install', $sabreJob->type);
        self::assertSame(10, $sabreJob->quantity);
        self::assertSame(12_000_000.0, $sabreJob->estimatedValue);
        self::assertSame('pending', $sabreJob->status);
        self::assertTrue($sabreJob->isAutoDetected);
        self::assertNull($sabreJob->reviewedByCharacterName);
    }

    public function testRejectedLineRentalHasNoBomItemAndKeepsNote(): void
    {
        $lineRental = $this->listedContributionsByLabel()['lineRental'];

        self::assertSame('Cyra Lune', $lineRental->memberCharacterName);
        self::assertNull($lineRental->bomItemId);
        self::assertNull($lineRental->bomItemTypeName);
        self::assertSame('line_rental', $lineRental->type);
        self::assertSame(5, $lineRental->quantity);
        self::assertSame('rejected', $lineRental->status);
        self::assertSame('Aria Solberg', $lineRental->reviewedByCharacterName);
        self::assertSame('Slot already paid', $lineRental->note);
    }

    public function testViewerAndOwnerContributionsShowTheirMainCharacter(): void
    {
        $contributions = $this->listedContributionsByLabel();

        self::assertSame('Kaelen Voss', $contributions['bpc']->memberCharacterName);
        self::assertSame('bpc', $contributions['bpc']->type);
        self::assertSame(2, $contributions['bpc']->quantity);
        self::assertSame('Aria Solberg', $contributions['mexallon']->memberCharacterName);
        self::assertSame('Mexallon', $contributions['mexallon']->bomItemTypeName);
        self::assertSame('Brann Holt', $contributions['mexallon']->reviewedByCharacterName);
        self::assertTrue($contributions['mexallon']->isVerified);
    }

    private function viewer(): User
    {
        $viewer = $this->em->find(User::class, $this->viewerId);
        \assert($viewer instanceof User);

        return $viewer;
    }

    /** @return GroupIndustryContributionResource[] */
    private function listContributionsAs(User $user): array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $provider = new GroupContributionCollectionProvider(
            $security,
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupIndustryContributionRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
            self::getContainer()->get(GroupIndustryResourceMapper::class),
        );

        return $provider->provide(new GetCollection(), ['projectId' => $this->projectId]);
    }

    /** @return array<string, GroupIndustryContributionResource> */
    private function listedContributionsByLabel(): array
    {
        $labelsById = array_flip($this->contributionIds);

        $byLabel = [];
        foreach ($this->listContributionsAs($this->viewer()) as $contribution) {
            $byLabel[$labelsById[$contribution->id]] = $contribution;
        }

        return $byLabel;
    }

    private function createUserWithMainCharacter(string $characterName): User
    {
        $user = $this->createUser();
        $user->setMainCharacter($this->createCharacter($user, $characterName));

        return $user;
    }

    private function addMember(GroupIndustryProject $project, User $user, GroupMemberRole $role): GroupIndustryProjectMember
    {
        $member = (new GroupIndustryProjectMember())
            ->setUser($user)
            ->setRole($role)
            ->setStatus(GroupMemberStatus::Accepted);
        $project->addMember($member);

        return $member;
    }

    private function addBomItem(GroupIndustryProject $project, int $typeId, string $typeName): GroupIndustryBomItem
    {
        $bomItem = (new GroupIndustryBomItem())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity(1_000_000);
        $project->addBomItem($bomItem);

        return $bomItem;
    }

    private function addContribution(
        string $label,
        GroupIndustryProject $project,
        GroupIndustryProjectMember $member,
        ?GroupIndustryBomItem $bomItem,
        ContributionType $type,
        int $quantity,
        float $estimatedValue,
        ContributionStatus $status,
        ?User $reviewedBy,
    ): GroupIndustryContribution {
        $contribution = (new GroupIndustryContribution())
            ->setMember($member)
            ->setBomItem($bomItem)
            ->setType($type)
            ->setQuantity($quantity)
            ->setEstimatedValue($estimatedValue)
            ->setStatus($status)
            ->setReviewedBy($reviewedBy)
            ->setReviewedAt($reviewedBy !== null ? new \DateTimeImmutable('2026-09-01 12:00:00') : null);
        $project->addContribution($contribution);
        $this->em->flush();
        $this->contributionIds[$label] = (string) $contribution->getId();

        return $contribution;
    }
}
