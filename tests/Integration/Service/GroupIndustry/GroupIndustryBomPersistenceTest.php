<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\GroupIndustry;

use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\UserProductionStructures;
use App\Repository\GroupIndustryContributionRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Service\GroupIndustry\CreateProjectData;
use App\Service\GroupIndustry\GroupIndustryProjectService;
use App\Service\Industry\InventionService;
use App\Service\JitaMarketService;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\RecordsSqlQueries;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

final class GroupIndustryBomPersistenceTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ACTIVITY_COPYING = 5;

    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int NITROGEN_FUEL_BLOCK_BLUEPRINT = 4314;
    private const int NITROGEN_ISOTOPES = 17888;
    private const int ROBOTICS = 9848;

    private const int SYNTHETIC_CATEGORY_ID = 999_993_001;
    private const int SYNTHETIC_GROUP_ID = 999_993_002;

    private const array TYPE_NAMES = [
        self::NITROGEN_FUEL_BLOCK => 'Nitrogen Fuel Block',
        44 => 'Enriched Uranium',
        3683 => 'Oxygen',
        3689 => 'Mechanical Parts',
        9832 => 'Coolant',
        self::ROBOTICS => 'Robotics',
        16272 => 'Heavy Water',
        16273 => 'Liquid Ozone',
        16275 => 'Strontium Clathrates',
        self::NITROGEN_ISOTOPES => 'Nitrogen Isotopes',
    ];

    private Connection $connection;
    private GroupIndustryProjectService $service;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->seedNitrogenFuelBlockBlueprint();
        $this->owner = $this->createUser();
        $this->em->flush();

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getCheapestPercentilePrices')->willReturnCallback(
            static fn (array $typeIds): array => array_map(
                static fn (int $typeId): ?float => [self::NITROGEN_ISOTOPES => 512.5, self::ROBOTICS => 76_000.0][$typeId] ?? null,
                array_combine($typeIds, $typeIds),
            ),
        );

        $this->service = new GroupIndustryProjectService(
            self::getContainer()->get(ProductionPlanning::class),
            self::getContainer()->get(UserProductionStructures::class),
            $jitaMarketService,
            $this->em,
            self::getContainer()->get(InventionService::class),
            self::getContainer()->get(IndustryActivityProductRepository::class),
            self::getContainer()->get(IndustryActivityRepository::class),
            self::getContainer()->get(GroupIndustryContributionRepository::class),
        );
    }

    #[Group('issue-141')]
    public function testCreatedProjectPersistsTheBomOfTwentyFiveRunsOfNitrogenFuelBlock(): void
    {
        $projectId = (string) $this->createNitrogenFuelBlockProject(runs: 25, meLevel: 10, teLevel: 20)->getId();
        $this->em->clear();

        self::assertSame(
            [
                [44, 'Enriched Uranium', 90, 0, null, false, null, null, null, null, null, null],
                [3683, 'Oxygen', 495, 0, null, false, null, null, null, null, null, null],
                [3689, 'Mechanical Parts', 90, 0, null, false, null, null, null, null, null, null],
                [9832, 'Coolant', 203, 0, null, false, null, null, null, null, null, null],
                [9848, 'Robotics', 25, 0, 76_000.0, false, null, null, null, null, null, null],
                [16272, 'Heavy Water', 3825, 0, null, false, null, null, null, null, null, null],
                [16273, 'Liquid Ozone', 7875, 0, null, false, null, null, null, null, null, null],
                [16275, 'Strontium Clathrates', 450, 0, null, false, null, null, null, null, null, null],
                [17888, 'Nitrogen Isotopes', 10125, 0, 512.5, false, null, null, null, null, null, null],
                [4051, 'Nitrogen Fuel Block', 25, 0, null, true, 'blueprint', 'copy', null, 0, 0, 25],
                [4051, 'Nitrogen Fuel Block', 1000, 0, null, true, 'final', 'manufacturing', null, 10, 20, 25],
            ],
            $this->persistedBomRows($projectId),
        );
        self::assertSame(
            [[self::NITROGEN_FUEL_BLOCK, 'Nitrogen Fuel Block', 10, 20, 25, 0]],
            $this->connection->fetchAllNumeric(
                'SELECT type_id, type_name, me_level, te_level, runs, sort_order FROM group_industry_project_items WHERE project_id = ?',
                [$projectId],
            ),
        );
    }

    #[Group('issue-141')]
    public function testProjectCreatedBeforeThePlanKeepsItsRecordedQuantitiesAndContributionsWhenAnotherProjectIsCreated(): void
    {
        $existingProject = $this->projectRecordedWithTheBomOfOneRunForTwentyFiveRuns();
        $this->flushAndClear();
        $existingProjectId = (string) $existingProject->getId();
        $existingBomRows = $this->persistedBomRows($existingProjectId);
        $existingContributionRows = $this->persistedContributionRows($existingProjectId);

        $newProjectId = (string) $this->createNitrogenFuelBlockProject(runs: 25, meLevel: 10, teLevel: 20)->getId();
        $this->em->clear();

        self::assertSame(
            [
                [17888, 'Nitrogen Isotopes', 405, 300, 512.5, false, null, null, null, null, null, null],
                [4051, 'Nitrogen Fuel Block', 1, 0, null, true, 'blueprint', 'copy', null, 0, 0, 1],
                [4051, 'Nitrogen Fuel Block', 40, 0, null, true, 'final', 'manufacturing', null, 10, 20, 1],
            ],
            $existingBomRows,
        );
        self::assertSame($existingBomRows, $this->persistedBomRows($existingProjectId));
        self::assertSame(
            [[ContributionType::JobInstall->value, 1, ContributionStatus::Pending->value], [ContributionType::Material->value, 300, ContributionStatus::Approved->value]],
            array_map(static fn (array $row): array => \array_slice($row, 0, 3), $existingContributionRows),
        );
        self::assertSame($existingContributionRows, $this->persistedContributionRows($existingProjectId));
        self::assertSame(
            [[10125, null], [1000, 25]],
            $this->connection->fetchAllNumeric(
                "SELECT required_quantity, runs FROM group_industry_bom_items WHERE project_id = ? AND (type_id = ? OR activity_type = 'manufacturing') ORDER BY is_job",
                [$newProjectId, self::NITROGEN_ISOTOPES],
            ),
        );
    }

    public function testRebuildingThePersistedBomWithTheSamePlanKeepsItsLinesAndContributions(): void
    {
        $project = $this->createNitrogenFuelBlockProject(runs: 1, meLevel: 0, teLevel: 0);
        $ownerMember = $project->getMembers()->first();
        \assert($ownerMember instanceof GroupIndustryProjectMember);
        $isotopes = $this->bomItemOf($project, self::NITROGEN_ISOTOPES, isJob: false);
        $finalJob = $this->bomItemOf($project, self::NITROGEN_FUEL_BLOCK, isJob: true);
        $this->contribution($project, $ownerMember, $isotopes, ContributionType::Material, 300, ContributionStatus::Approved);
        $isotopes->setFulfilledQuantity(300);
        $this->contribution($project, $ownerMember, $finalJob, ContributionType::JobInstall, 1, ContributionStatus::Pending);
        $this->flushAndClear();
        $projectId = (string) $project->getId();
        $isotopesId = (string) $isotopes->getId();
        $finalJobId = (string) $finalJob->getId();
        $contributionsBefore = $this->persistedContributionRows($projectId);
        $bomRowsBefore = $this->persistedBomRowsWithIds($projectId);

        $this->rebuildPersistedBomOf($projectId);

        self::assertSame(
            [
                [ContributionType::JobInstall->value, 1, ContributionStatus::Pending->value, $finalJobId],
                [ContributionType::Material->value, 300, ContributionStatus::Approved->value, $isotopesId],
            ],
            $contributionsBefore,
        );
        self::assertSame($contributionsBefore, $this->persistedContributionRows($projectId));
        self::assertSame(11, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM group_industry_bom_items WHERE project_id = ?', [$projectId]));
        self::assertSame($bomRowsBefore, $this->persistedBomRowsWithIds($projectId));
        self::assertSame(
            [[$isotopesId, 450, 300]],
            $this->connection->fetchAllNumeric(
                'SELECT id, required_quantity, fulfilled_quantity FROM group_industry_bom_items WHERE project_id = ? AND type_id = ?',
                [$projectId, self::NITROGEN_ISOTOPES],
            ),
        );
        self::assertSame(
            [[$finalJobId, 1, 40, 'final', 0, 0]],
            $this->connection->fetchAllNumeric(
                "SELECT id, runs, required_quantity, job_group, me_level, te_level FROM group_industry_bom_items WHERE project_id = ? AND is_job AND activity_type = 'manufacturing'",
                [$projectId],
            ),
        );
    }

    public function testRebuildingAProjectRecordedWithTheBomOfOneRunUpdatesItsPersistedLinesInPlaceForItsTwentyFiveRuns(): void
    {
        $project = $this->projectRecordedWithTheBomOfOneRunForTwentyFiveRuns();
        $this->flushAndClear();
        $projectId = (string) $project->getId();
        $recordedLineIds = $this->connection->fetchFirstColumn(
            'SELECT id FROM group_industry_bom_items WHERE project_id = ? ORDER BY is_job, activity_type',
            [$projectId],
        );
        $contributionsBefore = $this->persistedContributionRows($projectId);

        $this->rebuildPersistedBomOf($projectId);

        self::assertSame(
            [
                [44, 'Enriched Uranium', 90, 0, null, false, null, null, null, null, null, null],
                [3683, 'Oxygen', 495, 0, null, false, null, null, null, null, null, null],
                [3689, 'Mechanical Parts', 90, 0, null, false, null, null, null, null, null, null],
                [9832, 'Coolant', 203, 0, null, false, null, null, null, null, null, null],
                [9848, 'Robotics', 25, 0, 76_000.0, false, null, null, null, null, null, null],
                [16272, 'Heavy Water', 3825, 0, null, false, null, null, null, null, null, null],
                [16273, 'Liquid Ozone', 7875, 0, null, false, null, null, null, null, null, null],
                [16275, 'Strontium Clathrates', 450, 0, null, false, null, null, null, null, null, null],
                [17888, 'Nitrogen Isotopes', 10125, 300, 512.5, false, null, null, null, null, null, null],
                [4051, 'Nitrogen Fuel Block', 25, 0, null, true, 'blueprint', 'copy', null, 0, 0, 25],
                [4051, 'Nitrogen Fuel Block', 1000, 0, null, true, 'final', 'manufacturing', null, 10, 20, 25],
            ],
            $this->persistedBomRows($projectId),
        );
        self::assertSame(
            [[10125, 300], [25, 25], [1000, 25]],
            array_map(
                fn (string $lineId): array => array_map('intval', $this->connection->fetchNumeric(
                    'SELECT required_quantity, COALESCE(runs, fulfilled_quantity) FROM group_industry_bom_items WHERE id = ?',
                    [$lineId],
                ) ?: []),
                $recordedLineIds,
            ),
        );
        self::assertSame($contributionsBefore, $this->persistedContributionRows($projectId));
    }

    #[Group('issue-257')]
    public function testLineContributedAfterTheProjectWasLoadedIsKeptWithItsContributionWhenDroppedFromThePlan(): void
    {
        $projectId = (string) $this->createNitrogenFuelBlockProject(runs: 1, meLevel: 0, teLevel: 0)->getId();
        $this->flushAndClear();
        [$project, $owner] = $this->loadedProjectAndOwner($projectId);
        self::assertCount(0, $project->getContributions());
        $roboticsId = $this->connection->fetchOne('SELECT id FROM group_industry_bom_items WHERE project_id = ? AND type_id = ?', [$projectId, self::ROBOTICS]);
        $this->insertMaterialContributionOfOneUnit($projectId, $roboticsId);
        $this->connection->delete('sde_industry_activity_materials', ['type_id' => self::NITROGEN_FUEL_BLOCK_BLUEPRINT, 'material_type_id' => self::ROBOTICS]);

        $this->service->buildBom($project, $owner);
        $this->flushAndClear();

        self::assertSame(
            [[$roboticsId, 1, 0]],
            $this->connection->fetchAllNumeric(
                'SELECT id, required_quantity, fulfilled_quantity FROM group_industry_bom_items WHERE project_id = ? AND type_id = ?',
                [$projectId, self::ROBOTICS],
            ),
        );
        self::assertSame([[ContributionType::Material->value, 1, ContributionStatus::Pending->value, $roboticsId]], $this->persistedContributionRows($projectId));
        self::assertSame(11, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM group_industry_bom_items WHERE project_id = ?', [$projectId]));
    }

    public static function projectOfSeveralLineCountsProvider(): iterable
    {
        yield 'one run, eleven lines' => [1, 11];
        yield 'twenty-five runs at a max production limit of one, thirty-five lines' => [25, 35];
    }

    #[Group('issue-257')]
    #[DataProvider('projectOfSeveralLineCountsProvider')]
    public function testRebuildReadsTheContributedLinesOfTheProjectInOneQueryWhateverItsLineCount(int $runs, int $lineCount): void
    {
        $this->connection->update('sde_industry_blueprints', ['max_production_limit' => 1], ['type_id' => self::NITROGEN_FUEL_BLOCK_BLUEPRINT]);
        $projectId = (string) $this->createNitrogenFuelBlockProject(runs: $runs, meLevel: 0, teLevel: 0)->getId();
        $this->flushAndClear();
        [$project, $owner] = $this->loadedProjectAndOwner($projectId);
        self::assertCount(0, $project->getContributions());

        $contributionQueries = $this->queriesMatching(
            $this->sqlExecutedDuring(fn () => $this->service->buildBom($project, $owner)),
            '/\bgroup_industry_contributions\b/',
        );

        self::assertCount($lineCount, $project->getBomItems());
        self::assertCount(1, $contributionQueries, implode("\n", $contributionQueries));
    }

    private function loadedProjectAndOwner(string $projectId): array
    {
        $project = $this->em->find(GroupIndustryProject::class, $projectId);
        \assert($project instanceof GroupIndustryProject);
        $owner = $this->em->find(User::class, $this->owner->getId());
        \assert($owner instanceof User);

        return [$project, $owner];
    }

    private function insertMaterialContributionOfOneUnit(string $projectId, string $bomItemId): void
    {
        $this->connection->executeStatement(
            "INSERT INTO group_industry_contributions (id, project_id, member_id, bom_item_id, type, quantity, estimated_value, status, is_auto_detected, is_verified, created_at)
             SELECT ?, project_id, id, ?, ?, 1, 76000, ?, false, false, NOW() FROM group_industry_project_members WHERE project_id = ?",
            [Uuid::v7()->toRfc4122(), $bomItemId, ContributionType::Material->value, ContributionStatus::Pending->value, $projectId],
        );
    }

    private function createNitrogenFuelBlockProject(int $runs, int $meLevel, int $teLevel): GroupIndustryProject
    {
        return $this->service->createProject($this->owner, new CreateProjectData(
            name: 'Fuel run',
            items: [['typeId' => self::NITROGEN_FUEL_BLOCK, 'typeName' => 'Nitrogen Fuel Block', 'meLevel' => $meLevel, 'teLevel' => $teLevel, 'runs' => $runs]],
        ));
    }

    private function projectRecordedWithTheBomOfOneRunForTwentyFiveRuns(): GroupIndustryProject
    {
        $project = (new GroupIndustryProject())->setOwner($this->owner)->setName('Fuel run recorded before the plan');
        $ownerMember = (new GroupIndustryProjectMember())->setUser($this->owner)->setRole(GroupMemberRole::Owner)->setStatus(GroupMemberStatus::Accepted);
        $project->addMember($ownerMember);
        $project->addItem((new GroupIndustryProjectItem())
            ->setTypeId(self::NITROGEN_FUEL_BLOCK)
            ->setTypeName('Nitrogen Fuel Block')
            ->setMeLevel(10)
            ->setTeLevel(20)
            ->setRuns(25)
            ->setSortOrder(0));
        $isotopes = (new GroupIndustryBomItem())
            ->setTypeId(self::NITROGEN_ISOTOPES)
            ->setTypeName('Nitrogen Isotopes')
            ->setRequiredQuantity(405)
            ->setIsJob(false)
            ->setEstimatedPrice(512.5)
            ->setFulfilledQuantity(300);
        $finalJob = (new GroupIndustryBomItem())
            ->setTypeId(self::NITROGEN_FUEL_BLOCK)
            ->setTypeName('Nitrogen Fuel Block')
            ->setRequiredQuantity(40)
            ->setIsJob(true)
            ->setJobGroup('final')
            ->setActivityType('manufacturing')
            ->setMeLevel(10)
            ->setTeLevel(20)
            ->setRuns(1);
        $copyJob = (new GroupIndustryBomItem())
            ->setTypeId(self::NITROGEN_FUEL_BLOCK)
            ->setTypeName('Nitrogen Fuel Block')
            ->setRequiredQuantity(1)
            ->setIsJob(true)
            ->setJobGroup('blueprint')
            ->setActivityType('copy')
            ->setMeLevel(0)
            ->setTeLevel(0)
            ->setRuns(1);
        foreach ([$isotopes, $finalJob, $copyJob] as $bomItem) {
            $project->addBomItem($bomItem);
        }
        $this->contribution($project, $ownerMember, $isotopes, ContributionType::Material, 300, ContributionStatus::Approved);
        $this->contribution($project, $ownerMember, $finalJob, ContributionType::JobInstall, 1, ContributionStatus::Pending);
        $this->em->persist($project);

        return $project;
    }

    private function rebuildPersistedBomOf(string $projectId): void
    {
        $project = $this->em->find(GroupIndustryProject::class, $projectId);
        \assert($project instanceof GroupIndustryProject);
        $owner = $this->em->find(User::class, $this->owner->getId());
        \assert($owner instanceof User);
        $this->service->buildBom($project, $owner);
        $this->flushAndClear();
    }

    private function persistedBomRowsWithIds(string $projectId): array
    {
        return $this->connection->fetchAllNumeric(
            'SELECT id, type_id, required_quantity, fulfilled_quantity, estimated_price, is_job, job_group, activity_type, me_level, te_level, runs
             FROM group_industry_bom_items WHERE project_id = ? ORDER BY id',
            [$projectId],
        );
    }

    private function persistedBomRows(string $projectId): array
    {
        return $this->connection->fetchAllNumeric(
            'SELECT type_id, type_name, required_quantity, fulfilled_quantity, estimated_price, is_job, job_group, activity_type, parent_type_id, me_level, te_level, runs
             FROM group_industry_bom_items WHERE project_id = ? ORDER BY is_job, type_id, activity_type',
            [$projectId],
        );
    }

    private function persistedContributionRows(string $projectId): array
    {
        return $this->connection->fetchAllNumeric(
            'SELECT type, quantity, status, bom_item_id FROM group_industry_contributions WHERE project_id = ? ORDER BY type',
            [$projectId],
        );
    }

    private function bomItemOf(GroupIndustryProject $project, int $typeId, bool $isJob): GroupIndustryBomItem
    {
        foreach ($project->getBomItems() as $bomItem) {
            if ($bomItem->getTypeId() === $typeId && $bomItem->isJob() === $isJob && $bomItem->getActivityType() !== 'copy') {
                return $bomItem;
            }
        }
        self::fail("No BOM item for type {$typeId}");
    }

    private function contribution(
        GroupIndustryProject $project,
        GroupIndustryProjectMember $member,
        GroupIndustryBomItem $bomItem,
        ContributionType $type,
        int $quantity,
        ContributionStatus $status,
    ): void {
        $project->addContribution((new GroupIndustryContribution())
            ->setMember($member)
            ->setBomItem($bomItem)
            ->setType($type)
            ->setQuantity($quantity)
            ->setEstimatedValue(0.0)
            ->setStatus($status));
    }

    private function seedNitrogenFuelBlockBlueprint(): void
    {
        $category = (new InvCategory())->setCategoryId(self::SYNTHETIC_CATEGORY_ID)->setCategoryName('Material');
        $group = (new InvGroup())->setGroupId(self::SYNTHETIC_GROUP_ID)->setGroupName('Fuel Block')->setCategory($category);
        $this->em->persist($category);
        $this->em->persist($group);
        foreach (self::TYPE_NAMES as $typeId => $typeName) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group));
        }
        $this->em->flush();

        $this->connection->insert('sde_industry_blueprints', ['type_id' => self::NITROGEN_FUEL_BLOCK_BLUEPRINT, 'max_production_limit' => 200]);
        $this->connection->insert('sde_industry_activities', ['type_id' => self::NITROGEN_FUEL_BLOCK_BLUEPRINT, 'activity_id' => self::ACTIVITY_MANUFACTURING, 'time' => 900]);
        $this->connection->insert('sde_industry_activities', ['type_id' => self::NITROGEN_FUEL_BLOCK_BLUEPRINT, 'activity_id' => self::ACTIVITY_COPYING, 'time' => 4_800]);
        $this->connection->insert('sde_industry_activity_products', [
            'type_id' => self::NITROGEN_FUEL_BLOCK_BLUEPRINT,
            'activity_id' => self::ACTIVITY_MANUFACTURING,
            'product_type_id' => self::NITROGEN_FUEL_BLOCK,
            'quantity' => 40,
        ]);
        foreach ([44 => 4, 3683 => 22, 3689 => 4, 9832 => 9, self::ROBOTICS => 1, 16272 => 170, 16273 => 350, 16275 => 20, self::NITROGEN_ISOTOPES => 450] as $materialTypeId => $baseQuantityPerRun) {
            $this->connection->insert('sde_industry_activity_materials', [
                'type_id' => self::NITROGEN_FUEL_BLOCK_BLUEPRINT,
                'activity_id' => self::ACTIVITY_MANUFACTURING,
                'material_type_id' => $materialTypeId,
                'quantity' => $baseQuantityPerRun,
            ]);
        }
    }
}
