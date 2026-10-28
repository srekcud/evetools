<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Provider\GroupIndustry;

use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\GroupIndustry\GroupIndustrySaleResource;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\GroupIndustrySale;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryProjectRepository;
use App\Repository\GroupIndustrySaleRepository;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use App\State\Provider\GroupIndustry\GroupSaleCollectionProvider;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\SecurityBundle\Security;

#[Group('issue-166')]
final class GroupSaleCollectionProviderTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private const int MAX_QUERIES = 4;
    private const int SABRE_TYPE_ID = 22456;
    private const int CAPACITOR_BOOSTER_TYPE_ID = 3554;
    private const int NANITE_PASTE_TYPE_ID = 28668;

    public function testSalesListIssuesTheSameQueriesForTwoOrFourRecordingAdmins(): void
    {
        [$twoAdminsViewerId, $twoAdminsProjectId] = $this->createProjectWithSalesRecordedByAdmins('Lyra Sato', adminsCount: 2, salesCount: 4);
        [$fourAdminsViewerId, $fourAdminsProjectId] = $this->createProjectWithSalesRecordedByAdmins('Orin Vahl', adminsCount: 4, salesCount: 8);

        $twoAdminsQueries = $this->queriesListingTheSales($twoAdminsViewerId, $twoAdminsProjectId);
        $fourAdminsQueries = $this->queriesListingTheSales($fourAdminsViewerId, $fourAdminsProjectId);

        self::assertCount(
            \count($twoAdminsQueries),
            $fourAdminsQueries,
            sprintf(
                "4 sales by 2 admins: %d queries; 8 sales by 4 admins: %d queries:\n%s",
                \count($twoAdminsQueries),
                \count($fourAdminsQueries),
                implode("\n", $fourAdminsQueries),
            ),
        );
    }

    public function testSalesListIssuesABoundedNumberOfQueries(): void
    {
        [$viewerId, $projectId] = $this->createProjectWithSalesRecordedByAdmins('Kaelen Voss', adminsCount: 4, salesCount: 8);

        $queries = $this->queriesListingTheSales($viewerId, $projectId);

        self::assertLessThanOrEqual(
            self::MAX_QUERIES,
            \count($queries),
            sprintf("expected at most %d queries, got %d:\n%s", self::MAX_QUERIES, \count($queries), implode("\n", $queries)),
        );
    }

    public function testSalesListIssuesTheSameQueriesForTenOrTwentySalesOfTheOwner(): void
    {
        [$tenSalesViewerId, $tenSalesProjectId] = $this->createProjectWithSalesRecordedByAdmins('Vexa Mordu', adminsCount: 0, salesCount: 10);
        [$twentySalesViewerId, $twentySalesProjectId] = $this->createProjectWithSalesRecordedByAdmins('Dorn Achar', adminsCount: 0, salesCount: 20);

        $tenSalesQueries = $this->queriesListingTheSales($tenSalesViewerId, $tenSalesProjectId);
        $twentySalesQueries = $this->queriesListingTheSales($twentySalesViewerId, $twentySalesProjectId);

        self::assertCount(\count($tenSalesQueries), $twentySalesQueries, implode("\n", $twentySalesQueries));
    }

    public function testSalesAreListedMostRecentFirstWithTheNameOfTheirRecorder(): void
    {
        $owner = $this->createUserWithMainCharacter('Aria Solberg');
        $project = (new GroupIndustryProject())->setOwner($owner)->setName('Sabre doctrine');
        $this->em->persist($project);
        $this->addMember($project, $owner, GroupMemberRole::Owner);
        $adminWithoutMainCharacter = $this->createUser();
        $this->addMember($project, $adminWithoutMainCharacter, GroupMemberRole::Admin);
        $this->addSale($project, self::NANITE_PASTE_TYPE_ID, 'Nanite Repair Paste', 260, 23_450.0, '2026-10-19T16:12:00+00:00', null);
        $this->addSale($project, self::SABRE_TYPE_ID, 'Sabre', 3, 61_250_000.0, '2026-10-24T21:05:00+00:00', $owner);
        $this->addSale($project, self::CAPACITOR_BOOSTER_TYPE_ID, 'Medium Capacitor Booster II', 14, 1_837_500.0, '2026-10-22T08:40:00+00:00', $adminWithoutMainCharacter);
        $this->em->flush();
        $ownerId = (string) $owner->getId();
        $projectId = (string) $project->getId();
        $this->em->clear();

        $sales = $this->salesOf($projectId, $this->user($ownerId));

        self::assertSame(
            [
                [self::SABRE_TYPE_ID, 'Sabre', 3, 61_250_000.0, 183_750_000.0, '2026-10-24T21:05:00+00:00', 'Aria Solberg'],
                [self::CAPACITOR_BOOSTER_TYPE_ID, 'Medium Capacitor Booster II', 14, 1_837_500.0, 25_725_000.0, '2026-10-22T08:40:00+00:00', 'Unknown'],
                [self::NANITE_PASTE_TYPE_ID, 'Nanite Repair Paste', 260, 23_450.0, 6_097_000.0, '2026-10-19T16:12:00+00:00', null],
            ],
            array_map(
                static fn (GroupIndustrySaleResource $sale): array => [
                    $sale->typeId,
                    $sale->typeName,
                    $sale->quantity,
                    $sale->unitPrice,
                    $sale->totalPrice,
                    $sale->soldAt,
                    $sale->recordedByCharacterName,
                ],
                $sales,
            ),
        );
    }

    private function queriesListingTheSales(string $viewerId, string $projectId): array
    {
        $this->em->clear();
        $viewer = $this->user($viewerId);

        return $this->sqlExecutedDuring(fn () => $this->salesOf($projectId, $viewer));
    }

    private function createProjectWithSalesRecordedByAdmins(string $prefix, int $adminsCount, int $salesCount): array
    {
        $owner = $this->createUserWithMainCharacter(sprintf('Owner of %s', $prefix));
        $viewer = $this->createUserWithMainCharacter(sprintf('Viewer of %s', $prefix));
        $project = (new GroupIndustryProject())->setOwner($owner)->setName(sprintf('%s project', $prefix));
        $this->em->persist($project);
        $this->addMember($project, $owner, GroupMemberRole::Owner);
        $this->addMember($project, $viewer, GroupMemberRole::Member);

        $recorders = [];
        for ($adminIndex = 1; $adminIndex <= $adminsCount; ++$adminIndex) {
            $admin = $this->createUserWithMainCharacter(sprintf('Admin %d of %s', $adminIndex, $prefix));
            $this->addMember($project, $admin, GroupMemberRole::Admin);
            $recorders[] = $admin;
        }
        if ($recorders === []) {
            $recorders[] = $owner;
        }

        for ($saleIndex = 1; $saleIndex <= $salesCount; ++$saleIndex) {
            $this->addSale(
                $project,
                self::SABRE_TYPE_ID,
                'Sabre',
                $saleIndex,
                61_250_000.0 + $saleIndex * 15_000.0,
                sprintf('2026-10-%02dT18:30:00+00:00', $saleIndex),
                $recorders[$saleIndex % \count($recorders)],
            );
        }

        $this->em->flush();

        return [(string) $viewer->getId(), (string) $project->getId()];
    }

    private function salesOf(string $projectId, User $user): array
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $provider = new GroupSaleCollectionProvider(
            $security,
            self::getContainer()->get(GroupIndustryProjectRepository::class),
            self::getContainer()->get(GroupProjectAccessChecker::class),
            self::getContainer()->get(GroupIndustrySaleRepository::class),
        );

        return $provider->provide(new GetCollection(), ['projectId' => $projectId]);
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

    private function addSale(GroupIndustryProject $project, int $typeId, string $typeName, int $quantity, float $unitPrice, string $soldAt, ?User $recordedBy): void
    {
        $sale = (new GroupIndustrySale())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setVenue('Jita IV - Moon 4')
            ->setSoldAt(new \DateTimeImmutable($soldAt));
        if ($recordedBy !== null) {
            $sale->setRecordedBy($recordedBy);
        }
        $project->addSale($sale);
    }
}
