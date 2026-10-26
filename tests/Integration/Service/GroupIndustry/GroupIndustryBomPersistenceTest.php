<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\GroupIndustry;

use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryContribution;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Service\GroupIndustry\CreateProjectData;
use App\Service\GroupIndustry\GroupIndustryProjectService;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\InventionService;
use App\Service\JitaMarketService;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterization of the group BOM written to the database by createProject() and buildBom(), before
 * buildProductionTree() is replaced by src/Industry/Application (docs/specs/industry-engine.md, §7 rank 6:
 * BOM persisted before / after, approved contributions do not move).
 *
 * The real IndustryTreeService reads the Nitrogen Fuel Block blueprint seeded in the SDE tables (dev SDE on
 * 2026-10-07, see tests/Fixtures/Golden/sde-blueprint-recipes.json); only the Jita prices are stubbed.
 * Calculation cases on the goldens: tests/Unit/Service/GroupIndustry/GroupIndustryBomGoldenCharacterizationTest.php.
 */
final class GroupIndustryBomPersistenceTest extends IntegrationTestCase
{
    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ACTIVITY_COPYING = 5;

    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int NITROGEN_FUEL_BLOCK_BLUEPRINT = 4314;
    private const int NITROGEN_ISOTOPES = 17888;
    private const int ROBOTICS = 9848;

    private const int SYNTHETIC_CATEGORY_ID = 999_993_001;
    private const int SYNTHETIC_GROUP_ID = 999_993_002;

    /** @var array<int, string> */
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
            self::getContainer()->get(IndustryTreeService::class),
            $jitaMarketService,
            $this->em,
            self::getContainer()->get(IndustryBlueprintRepository::class),
            self::getContainer()->get(InventionService::class),
            self::getContainer()->get(IndustryActivityProductRepository::class),
        );
    }

    #[Group('issue-2')]
    public function testCreatedProjectCurrentlyPersistsTheBomOfOneRunForTwentyFiveRunsOfNitrogenFuelBlockIssue2(): void
    {
        $projectId = (string) $this->createNitrogenFuelBlockProject(runs: 25, meLevel: 10, teLevel: 20)->getId();
        $this->em->clear();

        self::assertSame(
            [
                [44, 'Enriched Uranium', 4, 0, null, false, null, null, null, null, null, null],
                [3683, 'Oxygen', 20, 0, null, false, null, null, null, null, null, null],
                [3689, 'Mechanical Parts', 4, 0, null, false, null, null, null, null, null, null],
                [9832, 'Coolant', 9, 0, null, false, null, null, null, null, null, null],
                [9848, 'Robotics', 1, 0, 76_000.0, false, null, null, null, null, null, null],
                [16272, 'Heavy Water', 153, 0, null, false, null, null, null, null, null, null],
                [16273, 'Liquid Ozone', 315, 0, null, false, null, null, null, null, null, null],
                [16275, 'Strontium Clathrates', 18, 0, null, false, null, null, null, null, null, null],
                [17888, 'Nitrogen Isotopes', 405, 0, 512.5, false, null, null, null, null, null, null],
                [4051, 'Nitrogen Fuel Block', 1, 0, null, true, 'blueprint', 'copy', null, 0, 0, 1],
                [4051, 'Nitrogen Fuel Block', 40, 0, null, true, 'final', 'manufacturing', null, 10, 20, 1],
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

    public function testRebuildingThePersistedBomCurrentlyAppendsASecondBomAndLeavesTheContributionsUntouched(): void
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

        $reloadedProject = $this->em->find(GroupIndustryProject::class, $projectId);
        \assert($reloadedProject instanceof GroupIndustryProject);
        $this->service->buildBom($reloadedProject, $this->em->find(User::class, $this->owner->getId()));
        $this->flushAndClear();

        self::assertSame(
            [
                [ContributionType::JobInstall->value, 1, ContributionStatus::Pending->value, $finalJobId],
                [ContributionType::Material->value, 300, ContributionStatus::Approved->value, $isotopesId],
            ],
            $contributionsBefore,
        );
        self::assertSame($contributionsBefore, $this->persistedContributionRows($projectId));
        self::assertSame(22, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM group_industry_bom_items WHERE project_id = ?', [$projectId]));
        self::assertSame(
            [[$isotopesId, 450, 300]],
            $this->connection->fetchAllNumeric(
                'SELECT id, required_quantity, fulfilled_quantity FROM group_industry_bom_items WHERE id = ?',
                [$isotopesId],
            ),
        );
        self::assertSame(
            [[450, 0], [450, 300]],
            $this->connection->fetchAllNumeric(
                'SELECT required_quantity, fulfilled_quantity FROM group_industry_bom_items WHERE project_id = ? AND type_id = ? ORDER BY fulfilled_quantity',
                [$projectId, self::NITROGEN_ISOTOPES],
            ),
        );
        self::assertSame(
            [[1, 40, 'final']],
            $this->connection->fetchAllNumeric(
                'SELECT runs, required_quantity, job_group FROM group_industry_bom_items WHERE id = ?',
                [$finalJobId],
            ),
        );
        self::assertSame(
            [['final', 1, 0, 0], ['final', 1, 0, 0]],
            $this->connection->fetchAllNumeric(
                "SELECT job_group, runs, me_level, te_level FROM group_industry_bom_items WHERE project_id = ? AND is_job AND activity_type = 'manufacturing'",
                [$projectId],
            ),
        );
    }

    private function createNitrogenFuelBlockProject(int $runs, int $meLevel, int $teLevel): GroupIndustryProject
    {
        return $this->service->createProject($this->owner, new CreateProjectData(
            name: 'Fuel run',
            items: [['typeId' => self::NITROGEN_FUEL_BLOCK, 'typeName' => 'Nitrogen Fuel Block', 'meLevel' => $meLevel, 'teLevel' => $teLevel, 'runs' => $runs]],
        ));
    }

    /**
     * @return list<list<mixed>> typeId, typeName, required quantity, fulfilled quantity, estimated price, is job,
     *                           job group, activity, parent typeId, ME, TE, runs; materials first, then jobs
     */
    private function persistedBomRows(string $projectId): array
    {
        return $this->connection->fetchAllNumeric(
            'SELECT type_id, type_name, required_quantity, fulfilled_quantity, estimated_price, is_job, job_group, activity_type, parent_type_id, me_level, te_level, runs
             FROM group_industry_bom_items WHERE project_id = ? ORDER BY is_job, type_id, activity_type',
            [$projectId],
        );
    }

    /** @return list<list<mixed>> type, quantity, status, BOM item id */
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
