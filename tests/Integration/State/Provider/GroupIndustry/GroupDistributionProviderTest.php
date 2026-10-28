<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\GroupIndustry\GroupIndustryDistributionResource;
use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\GroupIndustry\GroupIndustryDistributionService;
use App\State\Provider\GroupIndustry\GroupDistributionProvider;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security;

#[Group('issue-166')]
final class GroupDistributionProviderTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private const int MAX_QUERIES = 5;
    private const int CONTRIBUTIONS_PER_MEMBER = 2;
    private const int SABRE_TYPE_ID = 22456;
    private const int TRITANIUM_TYPE_ID = 34;

    public function testDistributionIssuesTheSameQueriesForFiveOrTenContributingMembers(): void
    {
        [$fiveMembersViewerId, $fiveMembersProjectId] = $this->createProjectWithApprovedContributions('Lyra Sato', membersCount: 5, salesCount: 4);
        [$tenMembersViewerId, $tenMembersProjectId] = $this->createProjectWithApprovedContributions('Orin Vahl', membersCount: 10, salesCount: 8);

        $fiveMembersQueries = $this->queriesReadingTheDistribution($fiveMembersViewerId, $fiveMembersProjectId);
        $tenMembersQueries = $this->queriesReadingTheDistribution($tenMembersViewerId, $tenMembersProjectId);

        self::assertCount(
            \count($fiveMembersQueries),
            $tenMembersQueries,
            sprintf(
                "5 members, 10 contributions, 4 sales: %d queries; 10 members, 20 contributions, 8 sales: %d queries:\n%s",
                \count($fiveMembersQueries),
                \count($tenMembersQueries),
                implode("\n", $tenMembersQueries),
            ),
        );
    }

    public function testDistributionIssuesABoundedNumberOfQueries(): void
    {
        [$viewerId, $projectId] = $this->createProjectWithApprovedContributions('Kaelen Voss', membersCount: 5, salesCount: 4);

        $queries = $this->queriesReadingTheDistribution($viewerId, $projectId);

        self::assertLessThanOrEqual(
            self::MAX_QUERIES,
            \count($queries),
            sprintf("expected at most %d queries, got %d:\n%s", self::MAX_QUERIES, \count($queries), implode("\n", $queries)),
        );
    }

    public function testMemberWithoutMainCharacterIsPaidUnderAnUnknownName(): void
    {
        $owner = $this->createUserWithMainCharacter('Aria Solberg');
        $project = (new GroupIndustryProject())->setOwner($owner)->setName('Sabre doctrine')->setBrokerFeePercent(0.0)->setSalesTaxPercent(0.0);
        $this->em->persist($project);
        $ownerMember = $this->addMember($project, $owner, GroupMemberRole::Owner);
        $memberWithoutMainCharacter = $this->addMember($project, $this->createUser(), GroupMemberRole::Member);
        $tritanium = $this->addBomItem($project, self::TRITANIUM_TYPE_ID, 'Tritanium');
        $this->addApprovedContribution($project, $ownerMember, $tritanium, 3_000_000.0);
        $this->addApprovedContribution($project, $memberWithoutMainCharacter, $tritanium, 1_000_000.0);
        $this->addSale($project, $owner, quantity: 2, unitPrice: 2_200_000.0);
        $this->em->flush();
        $ownerId = (string) $owner->getId();
        $projectId = (string) $project->getId();
        $ownerMemberId = (string) $ownerMember->getId();
        $memberWithoutMainCharacterId = (string) $memberWithoutMainCharacter->getId();
        $this->em->clear();

        $distribution = $this->distributionOf($projectId, $this->user($ownerId));

        $payoutsByMemberId = [];
        foreach ($distribution->members as $member) {
            $payoutsByMemberId[$member['memberId']] = [$member['characterName'], $member['totalCostsEngaged'], $member['payoutTotal']];
        }
        ksort($payoutsByMemberId);
        $expected = [
            $ownerMemberId => ['Aria Solberg', 3_000_000.0, 3_300_000.0],
            $memberWithoutMainCharacterId => ['Unknown', 1_000_000.0, 1_100_000.0],
        ];
        ksort($expected);
        self::assertSame($expected, $payoutsByMemberId);
        self::assertSame(4_400_000.0, $distribution->totalRevenue);
    }

    private function queriesReadingTheDistribution(string $viewerId, string $projectId): array
    {
        $this->em->clear();
        $viewer = $this->user($viewerId);

        return $this->sqlExecutedDuring(fn () => $this->distributionOf($projectId, $viewer));
    }

    private function createProjectWithApprovedContributions(string $prefix, int $membersCount, int $salesCount): array
    {
        $owner = $this->createUserWithMainCharacter(sprintf('Owner of %s', $prefix));
        $viewer = $this->createUserWithMainCharacter(sprintf('Viewer of %s', $prefix));
        $project = (new GroupIndustryProject())->setOwner($owner)->setName(sprintf('%s project', $prefix));
        $this->em->persist($project);
        $this->addMember($project, $owner, GroupMemberRole::Owner);
        $this->addMember($project, $viewer, GroupMemberRole::Member);

        for ($memberIndex = 1; $memberIndex <= $membersCount; ++$memberIndex) {
            $member = $this->addMember($project, $this->createUserWithMainCharacter(sprintf('Member %d of %s', $memberIndex, $prefix)), GroupMemberRole::Member);
            $bomItem = $this->addBomItem($project, self::TRITANIUM_TYPE_ID + $memberIndex, sprintf('Material %d', $memberIndex));
            for ($contributionIndex = 1; $contributionIndex <= self::CONTRIBUTIONS_PER_MEMBER; ++$contributionIndex) {
                $this->addApprovedContribution($project, $member, $bomItem, $memberIndex * 125_000.0 + $contributionIndex * 7_500.0);
            }
        }
        for ($saleIndex = 1; $saleIndex <= $salesCount; ++$saleIndex) {
            $this->addSale($project, $owner, quantity: $saleIndex, unitPrice: 61_250_000.0 + $saleIndex * 15_000.0);
        }

        $this->em->flush();

        return [(string) $viewer->getId(), (string) $project->getId()];
    }

    private function distributionOf(string $projectId, User $user): GroupIndustryDistributionResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $provider = new GroupDistributionProvider(
            $security,
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
            self::getContainer()->get(GroupIndustryDistributionService::class),
        );

        return $provider->provide(new Get(), ['projectId' => $projectId]);
    }

    private function user(string $userId): User
    {
        $user = $this->em->find(User::class, $userId);
        \assert($user instanceof User);

        return $user;
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

    private function addApprovedContribution(GroupIndustryProject $project, GroupIndustryProjectMember $member, GroupIndustryBomItem $bomItem, float $estimatedValue): void
    {
        $contribution = (new GroupIndustryContribution())
            ->setMember($member)
            ->setBomItem($bomItem)
            ->setType(ContributionType::Material)
            ->setQuantity(1_000)
            ->setEstimatedValue($estimatedValue)
            ->setStatus(ContributionStatus::Approved);
        $project->addContribution($contribution);
    }

    private function addSale(GroupIndustryProject $project, User $recordedBy, int $quantity, float $unitPrice): void
    {
        $sale = (new GroupIndustrySale())
            ->setTypeId(self::SABRE_TYPE_ID)
            ->setTypeName('Sabre')
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setVenue('Jita IV - Moon 4')
            ->setSoldAt(new \DateTimeImmutable('2026-10-20T18:30:00+00:00'))
            ->setRecordedBy($recordedBy);
        $project->addSale($sale);
    }
}
