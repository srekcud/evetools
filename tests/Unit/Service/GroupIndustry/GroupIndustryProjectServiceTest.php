<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\GroupIndustry;

use App\Entity\GroupIndustryBomItem;
use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectItem;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Service\GroupIndustry\CreateProjectData;
use App\Service\GroupIndustry\GroupIndustryProjectService;
use App\Service\Industry\IndustryTreeService;
use App\Service\JitaMarketService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(GroupIndustryProjectService::class)]
class GroupIndustryProjectServiceTest extends TestCase
{
    private IndustryTreeService&Stub $treeService;
    private JitaMarketService&Stub $jitaMarketService;
    private EntityManagerInterface&Stub $entityManager;
    private Connection&Stub $connection;
    private GroupIndustryProjectService $service;

    protected function setUp(): void
    {
        $this->treeService = $this->createStub(IndustryTreeService::class);
        $this->jitaMarketService = $this->createStub(JitaMarketService::class);
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->connection = $this->createStub(Connection::class);

        $this->entityManager->method('getConnection')->willReturn($this->connection);

        $this->service = new GroupIndustryProjectService(
            $this->treeService,
            $this->jitaMarketService,
            $this->entityManager,
        );
    }

    // ===========================================
    // Helper methods
    // ===========================================

    private function createUser(): User&Stub
    {
        return $this->createStub(User::class);
    }

    /**
     * Build a simple flat production tree node (no sub-components).
     *
     * @param list<array{typeId: int, typeName: string, quantity: int}> $rawMaterials
     * @return array<string, mixed>
     */
    private function buildFlatTreeNode(
        int $blueprintTypeId,
        int $productTypeId,
        string $productName,
        int $runs,
        int $outputPerRun = 1,
        array $rawMaterials = [],
        int $depth = 0,
        string $activityType = 'manufacturing',
        bool $hasCopy = false,
    ): array {
        $materials = [];
        foreach ($rawMaterials as $mat) {
            $materials[] = [
                'typeId' => $mat['typeId'],
                'typeName' => $mat['typeName'],
                'quantity' => $mat['quantity'],
                'isBuildable' => false,
                'activityType' => null,
            ];
        }

        return [
            'blueprintTypeId' => $blueprintTypeId,
            'productTypeId' => $productTypeId,
            'productTypeName' => $productName,
            'quantity' => $runs * $outputPerRun,
            'runs' => $runs,
            'outputPerRun' => $outputPerRun,
            'depth' => $depth,
            'activityType' => $activityType,
            'hasCopy' => $hasCopy,
            'materials' => $materials,
            'structureBonus' => 0.0,
            'structureName' => null,
            'productCategory' => null,
        ];
    }

    /**
     * Build a tree with an intermediate buildable component.
     *
     * @param list<array{typeId: int, typeName: string, quantity: int}> $compRawMaterials
     * @param list<array{typeId: int, typeName: string, quantity: int}> $finalRawMaterials
     * @return array<string, mixed>
     */
    private function buildTreeWithComponent(
        int $finalBpId,
        int $finalProductId,
        string $finalName,
        int $finalRuns,
        int $compBpId,
        int $compProductId,
        string $compName,
        int $compQuantity,
        int $compRuns,
        string $compActivityType = 'manufacturing',
        bool $compHasCopy = false,
        bool $finalHasCopy = false,
        array $compRawMaterials = [],
        array $finalRawMaterials = [],
    ): array {
        $subTree = $this->buildFlatTreeNode(
            $compBpId,
            $compProductId,
            $compName,
            $compRuns,
            1,
            $compRawMaterials,
            1,
            $compActivityType,
            $compHasCopy,
        );

        $tree = $this->buildFlatTreeNode(
            $finalBpId,
            $finalProductId,
            $finalName,
            $finalRuns,
            1,
            $finalRawMaterials,
            0,
            'manufacturing',
            $finalHasCopy,
        );

        // Add the buildable component as a material
        $tree['materials'][] = [
            'typeId' => $compProductId,
            'typeName' => $compName,
            'quantity' => $compQuantity,
            'isBuildable' => true,
            'activityType' => $compActivityType,
            'blueprint' => $subTree,
        ];

        return $tree;
    }

    /**
     * @return list<GroupIndustryBomItem>
     */
    private function getMaterialBomItems(GroupIndustryProject $project): array
    {
        return array_values(array_filter(
            $project->getBomItems()->toArray(),
            fn (GroupIndustryBomItem $item) => !$item->isJob(),
        ));
    }

    /**
     * @return list<GroupIndustryBomItem>
     */
    private function getJobBomItems(GroupIndustryProject $project): array
    {
        return array_values(array_filter(
            $project->getBomItems()->toArray(),
            fn (GroupIndustryBomItem $item) => $item->isJob(),
        ));
    }

    /**
     * Find a job BOM item by jobGroup.
     */
    private function findJobByGroup(GroupIndustryProject $project, string $jobGroup): ?GroupIndustryBomItem
    {
        foreach ($this->getJobBomItems($project) as $item) {
            if ($item->getJobGroup() === $jobGroup) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Find a job BOM item by activityType.
     */
    private function findJobByActivity(GroupIndustryProject $project, string $activityType): ?GroupIndustryBomItem
    {
        foreach ($this->getJobBomItems($project) as $item) {
            if ($item->getActivityType() === $activityType) {
                return $item;
            }
        }
        return null;
    }

    // ===========================================
    // createProject() -- basic creation
    // ===========================================

    public function testCreateProjectBasic(): void
    {
        $user = $this->createUser();

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 10, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 250000],
        ]);

        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturn([34 => 5.5]);

        $data = new CreateProjectData(
            name: 'Fleet Doctrine',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10],
            ],
        );

        $project = $this->service->createProject($user, $data);

        $this->assertSame('Fleet Doctrine', $project->getName());
        $this->assertSame(GroupProjectStatus::Published, $project->getStatus());
        $this->assertSame($user, $project->getOwner());

        // Short link code: 10 hex chars
        $this->assertMatchesRegularExpression('/^[0-9a-f]{10}$/', $project->getShortLinkCode());
    }

    public function testCreateProjectPersistsAndFlushes(): void
    {
        $user = $this->createUser();

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 1);

        $treeStub = $this->createStub(IndustryTreeService::class);
        $treeStub->method('buildProductionTree')->willReturn($tree);

        $jitaStub = $this->createStub(JitaMarketService::class);
        $jitaStub->method('getCheapestPercentilePrices')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $conn = $this->createStub(Connection::class);
        $em->method('getConnection')->willReturn($conn);

        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = new GroupIndustryProjectService($treeStub, $jitaStub, $em);

        $data = new CreateProjectData(
            name: 'Persist Test',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1],
            ],
        );

        $service->createProject($user, $data);
    }

    public function testCreateProjectOwnerAsMember(): void
    {
        $user = $this->createUser();

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 1);
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturn([]);

        $data = new CreateProjectData(
            name: 'Test',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1],
            ],
        );

        $project = $this->service->createProject($user, $data);

        $members = $project->getMembers()->toArray();
        $this->assertCount(1, $members);

        /** @var GroupIndustryProjectMember $ownerMember */
        $ownerMember = $members[0];
        $this->assertSame($user, $ownerMember->getUser());
        $this->assertSame(GroupMemberRole::Owner, $ownerMember->getRole());
        $this->assertSame(GroupMemberStatus::Accepted, $ownerMember->getStatus());
    }

    public function testCreateProjectWithItems(): void
    {
        $user = $this->createUser();

        $rifterTree = $this->buildFlatTreeNode(586, 587, 'Rifter', 10, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 250000],
        ]);
        $sabreTree = $this->buildFlatTreeNode(22442, 22456, 'Sabre', 5, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 100000],
        ]);

        $this->treeService->method('buildProductionTree')
            ->willReturnCallback(fn (int $productTypeId) => match ($productTypeId) {
                587 => $rifterTree,
                22456 => $sabreTree,
                default => throw new \RuntimeException('Unexpected typeId'),
            });
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturn([34 => 5.5]);

        $data = new CreateProjectData(
            name: 'Multi-item',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10],
                ['typeId' => 22456, 'typeName' => 'Sabre', 'meLevel' => 8, 'teLevel' => 16, 'runs' => 5],
            ],
        );

        $project = $this->service->createProject($user, $data);

        $items = $project->getItems()->toArray();
        $this->assertCount(2, $items);

        /** @var GroupIndustryProjectItem $firstItem */
        $firstItem = $items[0];
        $this->assertSame(587, $firstItem->getTypeId());
        $this->assertSame('Rifter', $firstItem->getTypeName());
        $this->assertSame(10, $firstItem->getMeLevel());
        $this->assertSame(20, $firstItem->getTeLevel());
        $this->assertSame(10, $firstItem->getRuns());
        $this->assertSame(0, $firstItem->getSortOrder());

        /** @var GroupIndustryProjectItem $secondItem */
        $secondItem = $items[1];
        $this->assertSame(22456, $secondItem->getTypeId());
        $this->assertSame('Sabre', $secondItem->getTypeName());
        $this->assertSame(8, $secondItem->getMeLevel());
        $this->assertSame(16, $secondItem->getTeLevel());
        $this->assertSame(5, $secondItem->getRuns());
        $this->assertSame(1, $secondItem->getSortOrder());
    }

    public function testCreateProjectEmptyNameStoresNull(): void
    {
        $user = $this->createUser();

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 1);
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturn([]);

        $data = new CreateProjectData(
            name: '',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1],
            ],
        );

        $project = $this->service->createProject($user, $data);

        $this->assertNull($project->getName());
    }

    // ===========================================
    // buildBom() -- simple item (leaf materials only)
    // ===========================================

    public function testBuildBomWithSimpleItem(): void
    {
        $user = $this->createUser();

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 10, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 250000],
            ['typeId' => 35, 'typeName' => 'Pyerite', 'quantity' => 50000],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->jitaMarketService->method('getCheapestPercentilePrices')
            ->willReturn([34 => 5.5, 35 => 10.0]);

        $data = new CreateProjectData(
            name: 'Simple',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10],
            ],
        );

        $project = $this->service->createProject($user, $data);

        $materials = $this->getMaterialBomItems($project);
        $this->assertCount(2, $materials);

        $tritanium = null;
        $pyerite = null;
        foreach ($materials as $mat) {
            if ($mat->getTypeId() === 34) {
                $tritanium = $mat;
            }
            if ($mat->getTypeId() === 35) {
                $pyerite = $mat;
            }
        }

        $this->assertNotNull($tritanium);
        $this->assertSame('Tritanium', $tritanium->getTypeName());
        $this->assertSame(250000, $tritanium->getRequiredQuantity());
        $this->assertFalse($tritanium->isJob());
        $this->assertSame(0, $tritanium->getFulfilledQuantity());

        $this->assertNotNull($pyerite);
        $this->assertSame('Pyerite', $pyerite->getTypeName());
        $this->assertSame(50000, $pyerite->getRequiredQuantity());
    }

    // ===========================================
    // buildBom() -- intermediate components become jobs
    // ===========================================

    public function testBuildBomWithIntermediateComponents(): void
    {
        $user = $this->createUser();

        $tree = $this->buildTreeWithComponent(
            finalBpId: 586,
            finalProductId: 587,
            finalName: 'Rifter',
            finalRuns: 5,
            compBpId: 1000,
            compProductId: 1001,
            compName: 'Advanced Component',
            compQuantity: 50,
            compRuns: 50,
            compRawMaterials: [
                ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 10000],
            ],
        );

        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->jitaMarketService->method('getCheapestPercentilePrices')
            ->willReturn([34 => 5.5]);

        $data = new CreateProjectData(
            name: 'T2 Build',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5],
            ],
        );

        $project = $this->service->createProject($user, $data);

        $jobs = $this->getJobBomItems($project);
        // Should have: 1 final job (Rifter manufacturing) + 1 component job (Advanced Component manufacturing)
        $this->assertGreaterThanOrEqual(2, count($jobs));

        $finalJob = $this->findJobByGroup($project, 'final');
        $this->assertNotNull($finalJob);
        $this->assertSame(587, $finalJob->getTypeId());
        $this->assertTrue($finalJob->isJob());
        $this->assertSame('final', $finalJob->getJobGroup());
        $this->assertSame('manufacturing', $finalJob->getActivityType());
        $this->assertSame(5, $finalJob->getRuns());

        $componentJobs = array_filter(
            $jobs,
            fn (GroupIndustryBomItem $item) => $item->getJobGroup() === 'component',
        );
        $this->assertCount(1, $componentJobs);
        $componentJob = array_values($componentJobs)[0];
        $this->assertSame(1001, $componentJob->getTypeId());
        $this->assertSame('component', $componentJob->getJobGroup());
        $this->assertSame('manufacturing', $componentJob->getActivityType());
        $this->assertSame(50, $componentJob->getRuns());
        // Intermediate manufacturing defaults to ME 10 / TE 20
        $this->assertSame(10, $componentJob->getMeLevel());
        $this->assertSame(20, $componentJob->getTeLevel());

        $materials = $this->getMaterialBomItems($project);
        $this->assertNotEmpty($materials);
        $tritanium = null;
        foreach ($materials as $mat) {
            if ($mat->getTypeId() === 34) {
                $tritanium = $mat;
            }
        }
        $this->assertNotNull($tritanium);
        $this->assertSame(10000, $tritanium->getRequiredQuantity());
    }

    // ===========================================
    // buildBom() -- copy step
    // ===========================================

    public function testBuildBomWithCopyStep(): void
    {
        $user = $this->createUser();

        $tree = $this->buildFlatTreeNode(586, 587, 'Sabre', 10, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 50000],
        ], 0, 'manufacturing', true); // hasCopy=true

        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->jitaMarketService->method('getCheapestPercentilePrices')
            ->willReturn([34 => 5.5]);

        $data = new CreateProjectData(
            name: 'T2 Sabre',
            items: [
                ['typeId' => 587, 'typeName' => 'Sabre', 'meLevel' => 2, 'teLevel' => 4, 'runs' => 10],
            ],
        );

        $project = $this->service->createProject($user, $data);

        $copyJob = $this->findJobByActivity($project, 'copy');
        $this->assertNotNull($copyJob, 'Copy BOM item should be created when hasCopy is true');
        $this->assertTrue($copyJob->isJob());
        $this->assertSame('blueprint', $copyJob->getJobGroup());
        $this->assertSame('copy', $copyJob->getActivityType());
        $this->assertSame(10, $copyJob->getRuns());
        $this->assertSame(0, $copyJob->getMeLevel());
        $this->assertSame(0, $copyJob->getTeLevel());
    }

    // ===========================================
    // buildBom() -- material aggregation across items
    // ===========================================

    public function testBuildBomMaterialAggregation(): void
    {
        $user = $this->createUser();

        $rifterTree = $this->buildFlatTreeNode(586, 587, 'Rifter', 10, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 250000],
            ['typeId' => 35, 'typeName' => 'Pyerite', 'quantity' => 50000],
        ]);
        $sabreTree = $this->buildFlatTreeNode(22442, 22456, 'Sabre', 5, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 100000],
            ['typeId' => 36, 'typeName' => 'Mexallon', 'quantity' => 20000],
        ]);

        $this->treeService->method('buildProductionTree')
            ->willReturnCallback(fn (int $productTypeId) => match ($productTypeId) {
                587 => $rifterTree,
                22456 => $sabreTree,
                default => throw new \RuntimeException('Unexpected typeId'),
            });
        $this->jitaMarketService->method('getCheapestPercentilePrices')
            ->willReturn([34 => 5.5, 35 => 10.0, 36 => 25.0]);

        $data = new CreateProjectData(
            name: 'Fleet',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10],
                ['typeId' => 22456, 'typeName' => 'Sabre', 'meLevel' => 2, 'teLevel' => 4, 'runs' => 5],
            ],
        );

        $project = $this->service->createProject($user, $data);

        $materials = $this->getMaterialBomItems($project);
        // Should have 3 unique materials: Tritanium, Pyerite, Mexallon
        $this->assertCount(3, $materials);

        // Tritanium should be aggregated: 250000 + 100000 = 350000
        $tritanium = null;
        foreach ($materials as $mat) {
            if ($mat->getTypeId() === 34) {
                $tritanium = $mat;
            }
        }
        $this->assertNotNull($tritanium);
        $this->assertSame(350000, $tritanium->getRequiredQuantity());
    }

    // ===========================================
    // buildBom() -- pricing
    // ===========================================

    public function testBuildBomPricing(): void
    {
        $user = $this->createUser();

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 10, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 250000],
            ['typeId' => 35, 'typeName' => 'Pyerite', 'quantity' => 50000],
        ]);

        $treeStub = $this->createStub(IndustryTreeService::class);
        $treeStub->method('buildProductionTree')->willReturn($tree);

        $jitaMock = $this->createMock(JitaMarketService::class);
        $jitaMock->expects($this->once())
            ->method('getCheapestPercentilePrices')
            ->with($this->callback(function (array $typeIds) {
                sort($typeIds);
                return $typeIds === [34, 35];
            }))
            ->willReturn([34 => 5.5, 35 => 10.0]);

        $em = $this->createStub(EntityManagerInterface::class);
        $conn = $this->createStub(Connection::class);
        $em->method('getConnection')->willReturn($conn);

        $service = new GroupIndustryProjectService($treeStub, $jitaMock, $em);

        $data = new CreateProjectData(
            name: 'Priced',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10],
            ],
        );

        $project = $service->createProject($user, $data);

        $materials = $this->getMaterialBomItems($project);
        foreach ($materials as $mat) {
            if ($mat->getTypeId() === 34) {
                $this->assertSame(5.5, $mat->getEstimatedPrice());
            }
            if ($mat->getTypeId() === 35) {
                $this->assertSame(10.0, $mat->getEstimatedPrice());
            }
        }
    }

    public function testBuildBomPricingNullForMissingType(): void
    {
        $user = $this->createUser();

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 10, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 250000],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturn([34 => null]);

        $data = new CreateProjectData(
            name: 'No Price',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 10],
            ],
        );

        $project = $this->service->createProject($user, $data);

        $materials = $this->getMaterialBomItems($project);
        $this->assertCount(1, $materials);
        $this->assertNull($materials[0]->getEstimatedPrice());
    }

    // ===========================================
    // buildBom() -- blacklists
    // ===========================================

    public function testBuildBomWithBlacklists(): void
    {
        $user = $this->createUser();

        // Set up the connection stub to resolve group IDs to type IDs
        $this->connection->method('fetchAllAssociative')
            ->willReturn([
                ['type_id' => 11399],
                ['type_id' => 11400],
            ]);

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 5, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 100000],
        ]);

        // Capture the excludedTypeIds passed to buildProductionTree
        $capturedExcluded = null;
        $treeStub = $this->createStub(IndustryTreeService::class);
        $treeStub->method('buildProductionTree')
            ->willReturnCallback(function (
                int $productTypeId,
                int $runs,
                int $finalMe,
                array $excludedTypeIds,
            ) use ($tree, &$capturedExcluded) {
                $capturedExcluded = $excludedTypeIds;
                return $tree;
            });

        $jitaStub = $this->createStub(JitaMarketService::class);
        $jitaStub->method('getCheapestPercentilePrices')->willReturn([34 => 5.5]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->connection);

        $service = new GroupIndustryProjectService($treeStub, $jitaStub, $em);

        $data = new CreateProjectData(
            name: 'Blacklisted',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5],
            ],
            blacklistGroupIds: [334], // Advanced Components group
            blacklistTypeIds: [9999],  // Individual blacklisted type
        );

        $service->createProject($user, $data);

        // Resolved should contain: individual type (9999) + resolved group types (11399, 11400)
        $this->assertNotNull($capturedExcluded);
        sort($capturedExcluded);
        $this->assertSame([9999, 11399, 11400], $capturedExcluded);
    }

    public function testBuildBomWithEmptyBlacklists(): void
    {
        $user = $this->createUser();

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 5, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 100000],
        ]);

        $capturedExcluded = null;
        $treeStub = $this->createStub(IndustryTreeService::class);
        $treeStub->method('buildProductionTree')
            ->willReturnCallback(function (
                int $productTypeId,
                int $runs,
                int $finalMe,
                array $excludedTypeIds,
            ) use ($tree, &$capturedExcluded) {
                $capturedExcluded = $excludedTypeIds;
                return $tree;
            });

        $jitaStub = $this->createStub(JitaMarketService::class);
        $jitaStub->method('getCheapestPercentilePrices')->willReturn([34 => 5.5]);

        $em = $this->createStub(EntityManagerInterface::class);
        $conn = $this->createStub(Connection::class);
        $em->method('getConnection')->willReturn($conn);

        $service = new GroupIndustryProjectService($treeStub, $jitaStub, $em);

        $data = new CreateProjectData(
            name: 'No Blacklist',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5],
            ],
        );

        $service->createProject($user, $data);

        $this->assertSame([], $capturedExcluded);
    }

    // ===========================================
    // buildBom() -- reaction nodes
    // ===========================================

    public function testBuildBomReactionNodeHasZeroMeTe(): void
    {
        $user = $this->createUser();

        $tree = $this->buildTreeWithComponent(
            finalBpId: 586,
            finalProductId: 587,
            finalName: 'Ship',
            finalRuns: 5,
            compBpId: 2000,
            compProductId: 2001,
            compName: 'Reaction Product',
            compQuantity: 100,
            compRuns: 1,
            compActivityType: 'reaction',
            compRawMaterials: [
                ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 5000],
            ],
        );

        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturn([34 => 5.5]);

        $data = new CreateProjectData(
            name: 'Reaction',
            items: [
                ['typeId' => 587, 'typeName' => 'Ship', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5],
            ],
        );

        $project = $this->service->createProject($user, $data);

        $reactionJob = $this->findJobByActivity($project, 'reaction');
        $this->assertNotNull($reactionJob);
        $this->assertSame(0, $reactionJob->getMeLevel());
        $this->assertSame(0, $reactionJob->getTeLevel());
        $this->assertSame('component', $reactionJob->getJobGroup());
    }

    // ===========================================
    // buildBom() -- job aggregation across items
    // ===========================================

    public function testBuildBomJobAggregation(): void
    {
        $user = $this->createUser();

        // Both items share the same intermediate component (typeId 1001)
        $tree1 = $this->buildTreeWithComponent(
            finalBpId: 586,
            finalProductId: 587,
            finalName: 'Rifter',
            finalRuns: 10,
            compBpId: 1000,
            compProductId: 1001,
            compName: 'Shared Component',
            compQuantity: 20,
            compRuns: 20,
        );

        $tree2 = $this->buildTreeWithComponent(
            finalBpId: 700,
            finalProductId: 701,
            finalName: 'Thrasher',
            finalRuns: 5,
            compBpId: 1000,
            compProductId: 1001,
            compName: 'Shared Component',
            compQuantity: 15,
            compRuns: 15,
        );

        $this->treeService->method('buildProductionTree')
            ->willReturnCallback(fn (int $productTypeId) => match ($productTypeId) {
                587 => $tree1,
                701 => $tree2,
                default => throw new \RuntimeException('Unexpected typeId'),
            });
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturn([]);

        $data = new CreateProjectData(
            name: 'Job Aggregation',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 10],
                ['typeId' => 701, 'typeName' => 'Thrasher', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5],
            ],
        );

        $project = $this->service->createProject($user, $data);

        // The shared component should be aggregated: 20 + 15 = 35 runs
        $componentJobs = array_values(array_filter(
            $this->getJobBomItems($project),
            fn (GroupIndustryBomItem $item) => $item->getJobGroup() === 'component',
        ));
        $this->assertCount(1, $componentJobs);
        $this->assertSame(1001, $componentJobs[0]->getTypeId());
        $this->assertSame(35, $componentJobs[0]->getRuns());
    }

    // ===========================================
    // createProject() -- optional fields
    // ===========================================

    public function testCreateProjectWithOptionalFields(): void
    {
        $user = $this->createUser();

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 1);
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturn([]);

        $data = new CreateProjectData(
            name: 'Full Options',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1],
            ],
            containerName: 'Fleet Doctrine Materials',
            lineRentalRatesOverride: ['ship_t2' => 2500000],
            brokerFeePercent: 2.5,
            salesTaxPercent: 3.0,
        );

        $project = $this->service->createProject($user, $data);

        $this->assertSame('Fleet Doctrine Materials', $project->getContainerName());
        $this->assertSame(['ship_t2' => 2500000], $project->getLineRentalRatesOverride());
        $this->assertSame(2.5, $project->getBrokerFeePercent());
        $this->assertSame(3.0, $project->getSalesTaxPercent());
    }

    // ===========================================
    // buildBom() -- intermediate copy step
    // ===========================================

    public function testBuildBomIntermediateCopyStep(): void
    {
        $user = $this->createUser();

        $tree = $this->buildTreeWithComponent(
            finalBpId: 586,
            finalProductId: 587,
            finalName: 'Ship',
            finalRuns: 5,
            compBpId: 1000,
            compProductId: 1001,
            compName: 'T2 Component',
            compQuantity: 10,
            compRuns: 10,
            compHasCopy: true,
            compRawMaterials: [
                ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 5000],
            ],
        );

        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->jitaMarketService->method('getCheapestPercentilePrices')->willReturn([34 => 5.5]);

        $data = new CreateProjectData(
            name: 'With Component Copy',
            items: [
                ['typeId' => 587, 'typeName' => 'Ship', 'meLevel' => 10, 'teLevel' => 20, 'runs' => 5],
            ],
        );

        $project = $this->service->createProject($user, $data);

        $blueprintJobs = array_values(array_filter(
            $this->getJobBomItems($project),
            fn (GroupIndustryBomItem $item) => $item->getJobGroup() === 'blueprint',
        ));
        $this->assertCount(1, $blueprintJobs);
        $this->assertSame(1001, $blueprintJobs[0]->getTypeId());
        $this->assertSame('copy', $blueprintJobs[0]->getActivityType());
        $this->assertSame(10, $blueprintJobs[0]->getRuns());
    }

    // ===========================================
    // buildBom() -- no materials, no pricing call
    // ===========================================

    public function testBuildBomNoMaterialsSkipsPricing(): void
    {
        $user = $this->createUser();

        $tree = $this->buildFlatTreeNode(586, 587, 'Rifter', 1, 1, []);

        $treeStub = $this->createStub(IndustryTreeService::class);
        $treeStub->method('buildProductionTree')->willReturn($tree);

        $jitaMock = $this->createMock(JitaMarketService::class);
        $jitaMock->expects($this->never())
            ->method('getCheapestPercentilePrices');

        $em = $this->createStub(EntityManagerInterface::class);
        $conn = $this->createStub(Connection::class);
        $em->method('getConnection')->willReturn($conn);

        $service = new GroupIndustryProjectService($treeStub, $jitaMock, $em);

        $data = new CreateProjectData(
            name: 'No Mats',
            items: [
                ['typeId' => 587, 'typeName' => 'Rifter', 'meLevel' => 0, 'teLevel' => 0, 'runs' => 1],
            ],
        );

        $project = $service->createProject($user, $data);

        $materials = $this->getMaterialBomItems($project);
        $this->assertCount(0, $materials);
    }
}
