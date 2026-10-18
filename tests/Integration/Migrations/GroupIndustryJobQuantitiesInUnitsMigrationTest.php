<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261019000000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

#[Group('issue-107')]
final class GroupIndustryJobQuantitiesInUnitsMigrationTest extends IntegrationTestCase
{
    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ACTIVITY_REACTION = 11;

    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int HAIL_L = 12779;
    private const int CAPITAL_PROPULSION_ENGINE = 21009;
    private const int NITROGEN_ISOTOPES = 17888;
    private const int HELIUM_FUEL_BLOCK = 4247;
    private const int OXYGEN_FUEL_BLOCK = 4312;

    private const int NITROGEN_FUEL_BLOCK_BLUEPRINT = 999_995_001;
    private const int HYDROGEN_FUEL_BLOCK_BLUEPRINT = 999_995_002;
    private const int FERNITE_CARBIDE_REACTION_FORMULA = 999_995_003;
    private const int FERNITE_ALLOY_BLUEPRINT = 999_995_004;
    private const int HAIL_L_BLUEPRINT = 999_995_005;
    private const int HAIL_L_OTHER_BLUEPRINT = 999_995_006;
    private const int HELIUM_FUEL_BLOCK_BLUEPRINT = 999_995_007;
    private const int HELIUM_FUEL_BLOCK_OTHER_BLUEPRINT = 999_995_008;

    private Connection $connection;
    private GroupIndustryProject $project;
    private GroupIndustryProjectMember $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->em->getConnection();
        $this->seedUnitsPerRun();

        $owner = $this->createUser();
        $this->project = (new GroupIndustryProject())->setOwner($owner);
        $this->member = (new GroupIndustryProjectMember())
            ->setUser($owner)
            ->setRole(GroupMemberRole::Owner)
            ->setStatus(GroupMemberStatus::Accepted);
        $this->project->addMember($this->member);
        $this->em->persist($this->project);
    }

    public function testManufacturingJobLineAndItsContributionsAreConvertedToUnits(): void
    {
        $finalJob = $this->jobLine(self::NITROGEN_FUEL_BLOCK, 'Nitrogen Fuel Block', 'manufacturing', 'final', runs: 3, fulfilledQuantity: 2);
        $approved = $this->contribution($finalJob, ContributionType::JobInstall, 2, ContributionStatus::Approved);
        $pending = $this->contribution($finalJob, ContributionType::JobInstall, 1, ContributionStatus::Pending);
        $rejected = $this->contribution($finalJob, ContributionType::JobInstall, 4, ContributionStatus::Rejected);
        $this->flushAndClear();

        $this->migrate();

        self::assertSame([3, 120, 80], $this->bomItemRow($finalJob));
        self::assertSame([80, 40, 160], [$this->contributionQuantity($approved), $this->contributionQuantity($pending), $this->contributionQuantity($rejected)]);
    }

    public function testOnlyProductionJobLinesAndTheirContributionsAreConvertedInAMixedProject(): void
    {
        $finalJob = $this->jobLine(self::NITROGEN_FUEL_BLOCK, 'Nitrogen Fuel Block', 'manufacturing', 'final', runs: 3, fulfilledQuantity: 2);
        $reactionJob = $this->jobLine(self::FERNITE_CARBIDE, 'Fernite Carbide', 'reaction', 'component', runs: 5, fulfilledQuantity: 3);
        $isotopes = $this->materialLine(self::NITROGEN_ISOTOPES, 'Nitrogen Isotopes', requiredQuantity: 405, fulfilledQuantity: 300);
        $copyJob = $this->jobLine(self::OXYGEN_FUEL_BLOCK, 'Oxygen Fuel Block', 'copy', 'blueprint', runs: 7, fulfilledQuantity: 6);
        $finalApproved = $this->contribution($finalJob, ContributionType::JobInstall, 2, ContributionStatus::Approved);
        $finalPending = $this->contribution($finalJob, ContributionType::JobInstall, 1, ContributionStatus::Pending);
        $reactionApproved = $this->contribution($reactionJob, ContributionType::JobInstall, 3, ContributionStatus::Approved);
        $isotopesContribution = $this->contribution($isotopes, ContributionType::Material, 300, ContributionStatus::Approved);
        $bpc = $this->contribution($copyJob, ContributionType::Bpc, 6, ContributionStatus::Approved);
        $this->flushAndClear();

        $this->migrate();

        self::assertSame([3, 120, 80], $this->bomItemRow($finalJob));
        self::assertSame([5, 50_000, 30_000], $this->bomItemRow($reactionJob));
        self::assertSame([null, 405, 300], $this->bomItemRow($isotopes));
        self::assertSame([7, 7, 6], $this->bomItemRow($copyJob));
        self::assertSame([80, 40], [$this->contributionQuantity($finalApproved), $this->contributionQuantity($finalPending)]);
        self::assertSame(30_000, $this->contributionQuantity($reactionApproved));
        self::assertSame(300, $this->contributionQuantity($isotopesContribution));
        self::assertSame(6, $this->contributionQuantity($bpc));
    }

    public function testJobLineWithSeveralBlueprintsOfTheSameUnitsPerRunIsConverted(): void
    {
        $heliumJob = $this->jobLine(self::HELIUM_FUEL_BLOCK, 'Helium Fuel Block', 'manufacturing', 'final', runs: 6, fulfilledQuantity: 5);
        $approved = $this->contribution($heliumJob, ContributionType::JobInstall, 5, ContributionStatus::Approved);
        $this->flushAndClear();

        $this->migrate();

        self::assertSame([6, 240, 200], $this->bomItemRow($heliumJob));
        self::assertSame(200, $this->contributionQuantity($approved));
    }

    public function testReactionJobLineAndItsContributionIsConvertedToUnits(): void
    {
        $reactionJob = $this->jobLine(self::FERNITE_CARBIDE, 'Fernite Carbide', 'reaction', 'component', runs: 2, fulfilledQuantity: 1);
        $approved = $this->contribution($reactionJob, ContributionType::JobInstall, 1, ContributionStatus::Approved);
        $this->flushAndClear();

        $this->migrate();

        self::assertSame([2, 20_000, 10_000], $this->bomItemRow($reactionJob));
        self::assertSame(10_000, $this->contributionQuantity($approved));
    }

    public function testCopyJobLineKeepsItsQuantitiesInRuns(): void
    {
        $copyJob = $this->jobLine(self::NITROGEN_FUEL_BLOCK, 'Nitrogen Fuel Block', 'copy', 'blueprint', runs: 3, fulfilledQuantity: 3);
        $bpc = $this->contribution($copyJob, ContributionType::Bpc, 3, ContributionStatus::Approved);
        $this->flushAndClear();

        $this->migrate();

        self::assertSame([3, 3, 3], $this->bomItemRow($copyJob));
        self::assertSame(3, $this->contributionQuantity($bpc));
    }

    public function testMaterialLinesKeepTheirQuantitiesEvenWhenTheMaterialIsManufacturable(): void
    {
        $isotopes = $this->materialLine(self::NITROGEN_ISOTOPES, 'Nitrogen Isotopes', requiredQuantity: 405, fulfilledQuantity: 300);
        $fuelBlocks = $this->materialLine(self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', requiredQuantity: 15, fulfilledQuantity: 5);
        $isotopesContribution = $this->contribution($isotopes, ContributionType::Material, 300, ContributionStatus::Approved);
        $fuelBlocksContribution = $this->contribution($fuelBlocks, ContributionType::Material, 5, ContributionStatus::Approved);
        $this->flushAndClear();

        $this->migrate();

        self::assertSame([null, 405, 300], $this->bomItemRow($isotopes));
        self::assertSame([null, 15, 5], $this->bomItemRow($fuelBlocks));
        self::assertSame([300, 5], [$this->contributionQuantity($isotopesContribution), $this->contributionQuantity($fuelBlocksContribution)]);
    }

    public function testJobLineWithoutUnitsPerRunInTheSdeIsLeftUnchanged(): void
    {
        $engineJob = $this->jobLine(self::CAPITAL_PROPULSION_ENGINE, 'Capital Propulsion Engine', 'manufacturing', 'component', runs: 20, fulfilledQuantity: 7);
        $approved = $this->contribution($engineJob, ContributionType::JobInstall, 7, ContributionStatus::Approved);
        $this->flushAndClear();

        $this->migrate();

        self::assertSame([20, 20, 7], $this->bomItemRow($engineJob));
        self::assertSame(7, $this->contributionQuantity($approved));
    }

    public function testReactionJobLineWhoseProductIsOnlyManufacturedInTheSdeIsLeftUnchanged(): void
    {
        $reactionJob = $this->jobLine(self::FERNITE_ALLOY, 'Fernite Alloy', 'reaction', 'component', runs: 5, fulfilledQuantity: 4);
        $approved = $this->contribution($reactionJob, ContributionType::JobInstall, 4, ContributionStatus::Approved);
        $this->flushAndClear();

        $this->migrate();

        self::assertSame([5, 5, 4], $this->bomItemRow($reactionJob));
        self::assertSame(4, $this->contributionQuantity($approved));
    }

    public function testJobLineWithSeveralDifferentUnitsPerRunInTheSdeIsLeftUnchanged(): void
    {
        $hailJob = $this->jobLine(self::HAIL_L, 'Hail L', 'manufacturing', 'final', runs: 4, fulfilledQuantity: 2);
        $approved = $this->contribution($hailJob, ContributionType::JobInstall, 2, ContributionStatus::Approved);
        $this->flushAndClear();

        $this->migrate();

        self::assertSame([4, 4, 2], $this->bomItemRow($hailJob));
        self::assertSame(2, $this->contributionQuantity($approved));
    }

    private function migrate(): void
    {
        require_once self::$kernel->getProjectDir().'/migrations/Version20261019000000.php';

        $migration = new Version20261019000000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function jobLine(int $typeId, string $typeName, string $activityType, string $jobGroup, int $runs, int $fulfilledQuantity): GroupIndustryBomItem
    {
        $bomItem = (new GroupIndustryBomItem())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setIsJob(true)
            ->setActivityType($activityType)
            ->setJobGroup($jobGroup)
            ->setRuns($runs)
            ->setRequiredQuantity($runs)
            ->setFulfilledQuantity($fulfilledQuantity);
        $this->project->addBomItem($bomItem);

        return $bomItem;
    }

    private function materialLine(int $typeId, string $typeName, int $requiredQuantity, int $fulfilledQuantity): GroupIndustryBomItem
    {
        $bomItem = (new GroupIndustryBomItem())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setIsJob(false)
            ->setRequiredQuantity($requiredQuantity)
            ->setFulfilledQuantity($fulfilledQuantity);
        $this->project->addBomItem($bomItem);

        return $bomItem;
    }

    private function contribution(GroupIndustryBomItem $bomItem, ContributionType $type, int $quantity, ContributionStatus $status): GroupIndustryContribution
    {
        $contribution = (new GroupIndustryContribution())
            ->setMember($this->member)
            ->setBomItem($bomItem)
            ->setType($type)
            ->setQuantity($quantity)
            ->setEstimatedValue(0.0)
            ->setStatus($status);
        $this->project->addContribution($contribution);

        return $contribution;
    }

    /** @return list<int|null> runs, required quantity, fulfilled quantity */
    private function bomItemRow(GroupIndustryBomItem $bomItem): array
    {
        return array_map(
            static fn (mixed $value): ?int => $value === null ? null : (int) $value,
            $this->connection->fetchNumeric(
                'SELECT runs, required_quantity, fulfilled_quantity FROM group_industry_bom_items WHERE id = ?',
                [(string) $bomItem->getId()],
            ),
        );
    }

    private function contributionQuantity(GroupIndustryContribution $contribution): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT quantity FROM group_industry_contributions WHERE id = ?',
            [(string) $contribution->getId()],
        );
    }

    private function seedUnitsPerRun(): void
    {
        foreach ([
            [self::NITROGEN_FUEL_BLOCK_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::NITROGEN_FUEL_BLOCK, 40],
            [self::HYDROGEN_FUEL_BLOCK_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::HYDROGEN_FUEL_BLOCK, 40],
            [self::FERNITE_CARBIDE_REACTION_FORMULA, self::ACTIVITY_REACTION, self::FERNITE_CARBIDE, 10_000],
            [self::FERNITE_ALLOY_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::FERNITE_ALLOY, 200],
            [self::HAIL_L_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::HAIL_L, 5_000],
            [self::HAIL_L_OTHER_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::HAIL_L, 2_500],
            [self::HELIUM_FUEL_BLOCK_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::HELIUM_FUEL_BLOCK, 40],
            [self::HELIUM_FUEL_BLOCK_OTHER_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::HELIUM_FUEL_BLOCK, 40],
        ] as [$blueprintTypeId, $activityId, $productTypeId, $unitsPerRun]) {
            $this->connection->insert('sde_industry_activity_products', [
                'type_id' => $blueprintTypeId,
                'activity_id' => $activityId,
                'product_type_id' => $productTypeId,
                'quantity' => $unitsPerRun,
            ]);
        }
    }
}
