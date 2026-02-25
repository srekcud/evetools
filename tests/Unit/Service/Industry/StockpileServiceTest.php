<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\StockpileService;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(StockpileService::class)]
#[AllowMockObjectsWithoutExpectations]
class StockpileServiceTest extends TestCase
{
    private IndustryStockpileTargetRepository&MockObject $targetRepository;
    private CachedAssetRepository&MockObject $assetRepository;
    private JitaMarketService&MockObject $jitaMarketService;
    private IndustryTreeService&MockObject $treeService;
    private EntityManagerInterface&MockObject $entityManager;
    private StockpileService $service;
    private User&MockObject $user;

    protected function setUp(): void
    {
        $this->targetRepository = $this->createMock(IndustryStockpileTargetRepository::class);
        $this->assetRepository = $this->createMock(CachedAssetRepository::class);
        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->treeService = $this->createMock(IndustryTreeService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->service = new StockpileService(
            $this->targetRepository,
            $this->assetRepository,
            $this->jitaMarketService,
            $this->treeService,
            $this->entityManager,
        );

        $this->user = $this->createMock(User::class);
    }

    /**
     * Build a mock production tree:
     * - Root product (typeId: 100, "Widget") at depth 0, outputPerRun = 1
     *   - Material A (typeId: 1, "Raw Ore", NOT buildable) -> raw_material
     *   - Material B (typeId: 2, "Component Frame", buildable) -> component
     *     - Sub-material C (typeId: 3, "Sub-Component", buildable) -> intermediate
     *       - Sub-sub-material D (typeId: 1, "Raw Ore", NOT buildable) -> raw_material (merges with A!)
     *     - Sub-material E (typeId: 4, "Raw Metal", NOT buildable) -> raw_material
     *
     * @return array<string, mixed>
     */
    private function buildMockTree(): array
    {
        return [
            'blueprintTypeId' => 99,
            'productTypeId' => 100,
            'productTypeName' => 'Widget',
            'quantity' => 1,
            'runs' => 1,
            'outputPerRun' => 1,
            'depth' => 0,
            'activityType' => 'manufacturing',
            'hasCopy' => false,
            'materials' => [
                [
                    'typeId' => 1,
                    'typeName' => 'Raw Ore',
                    'quantity' => 500,
                    'isBuildable' => false,
                    'activityType' => null,
                ],
                [
                    'typeId' => 2,
                    'typeName' => 'Component Frame',
                    'quantity' => 10,
                    'isBuildable' => true,
                    'activityType' => 'manufacturing',
                    'blueprint' => [
                        'blueprintTypeId' => 98,
                        'productTypeId' => 2,
                        'productTypeName' => 'Component Frame',
                        'quantity' => 10,
                        'runs' => 1,
                        'outputPerRun' => 10,
                        'depth' => 1,
                        'activityType' => 'manufacturing',
                        'hasCopy' => false,
                        'materials' => [
                            [
                                'typeId' => 3,
                                'typeName' => 'Sub-Component',
                                'quantity' => 20,
                                'isBuildable' => true,
                                'activityType' => 'manufacturing',
                                'blueprint' => [
                                    'blueprintTypeId' => 97,
                                    'productTypeId' => 3,
                                    'productTypeName' => 'Sub-Component',
                                    'quantity' => 20,
                                    'runs' => 2,
                                    'outputPerRun' => 10,
                                    'depth' => 2,
                                    'activityType' => 'manufacturing',
                                    'hasCopy' => false,
                                    'materials' => [
                                        [
                                            'typeId' => 1,
                                            'typeName' => 'Raw Ore',
                                            'quantity' => 200,
                                            'isBuildable' => false,
                                            'activityType' => null,
                                        ],
                                    ],
                                    'structureBonus' => 0.0,
                                    'structureName' => null,
                                    'productCategory' => null,
                                ],
                            ],
                            [
                                'typeId' => 4,
                                'typeName' => 'Raw Metal',
                                'quantity' => 100,
                                'isBuildable' => false,
                                'activityType' => null,
                            ],
                        ],
                        'structureBonus' => 0.0,
                        'structureName' => null,
                        'productCategory' => null,
                    ],
                ],
            ],
            'structureBonus' => 0.0,
            'structureName' => null,
            'productCategory' => null,
        ];
    }

    public function testFlattenTreeClassifiesRawMaterials(): void
    {
        $tree = $this->buildMockTree();
        $result = $this->service->flattenTree($tree, 100);

        // typeId 1 ("Raw Ore") should be raw_material
        $this->assertArrayHasKey(1, $result);
        $this->assertSame('raw_material', $result[1]['stage']);

        // typeId 4 ("Raw Metal") should also be raw_material
        $this->assertArrayHasKey(4, $result);
        $this->assertSame('raw_material', $result[4]['stage']);
    }

    public function testFlattenTreeClassifiesComponents(): void
    {
        $tree = $this->buildMockTree();
        $result = $this->service->flattenTree($tree, 100);

        // typeId 2 ("Component Frame") is buildable at depth 0 -> component
        $this->assertArrayHasKey(2, $result);
        $this->assertSame('component', $result[2]['stage']);
    }

    public function testFlattenTreeClassifiesIntermediates(): void
    {
        $tree = $this->buildMockTree();
        $result = $this->service->flattenTree($tree, 100);

        // typeId 3 ("Sub-Component") is buildable at depth 1+ -> intermediate
        $this->assertArrayHasKey(3, $result);
        $this->assertSame('intermediate', $result[3]['stage']);
    }

    public function testFlattenTreeClassifiesFinalProduct(): void
    {
        $tree = $this->buildMockTree();
        $result = $this->service->flattenTree($tree, 100);

        // Root product (typeId 100) -> final_product
        $this->assertArrayHasKey(100, $result);
        $this->assertSame('final_product', $result[100]['stage']);
        $this->assertSame('Widget', $result[100]['typeName']);
        $this->assertSame(1, $result[100]['quantity']); // 1 run * 1 outputPerRun
    }

    public function testFlattenTreeMergesDuplicateTypeIds(): void
    {
        $tree = $this->buildMockTree();
        $result = $this->service->flattenTree($tree, 100);

        // typeId 1 ("Raw Ore") appears twice: 500 (direct) + 200 (deep) = 700
        $this->assertSame(700, $result[1]['quantity']);
    }

    public function testImportReplaceDeletesExisting(): void
    {
        $tree = $this->buildMockTree();
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->jitaMarketService->method('getPrices')->willReturn([]);

        // Expect deleteAllForUser to be called in replace mode
        $this->targetRepository->expects($this->once())
            ->method('deleteAllForUser')
            ->with($this->user);

        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->atLeastOnce())->method('flush');

        $this->service->importFromBlueprint($this->user, 100, 1, 0, 0, 'replace');
    }

    public function testImportMergeAddsQuantities(): void
    {
        $tree = $this->buildMockTree();
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->jitaMarketService->method('getPrices')->willReturn([]);

        // Simulate existing target for "Raw Ore" (typeId 1) with quantity 300
        $existingTarget = $this->createMock(IndustryStockpileTarget::class);
        $existingTarget->method('getTargetQuantity')->willReturn(300);
        $existingTarget->expects($this->once())
            ->method('setTargetQuantity')
            ->with(1000); // 300 existing + 700 new (500 + 200 merged)
        $existingTarget->expects($this->once())
            ->method('setUpdatedAt');

        $this->targetRepository->method('findByUserAndTypeId')
            ->willReturnCallback(function (User $user, int $typeId) use ($existingTarget) {
                // Only typeId 1 has an existing target
                return $typeId === 1 ? $existingTarget : null;
            });

        // deleteAllForUser should NOT be called in merge mode
        $this->targetRepository->expects($this->never())->method('deleteAllForUser');

        $this->entityManager->expects($this->atLeastOnce())->method('flush');

        $this->service->importFromBlueprint($this->user, 100, 1, 0, 0, 'merge');
    }

    public function testKpiPipelineHealth(): void
    {
        // 4 targets: 2 met (>=100%), 2 not met
        $targets = [
            $this->createTarget(1, 'Ore A', 100, 'raw_material', 100, 100),
            $this->createTarget(2, 'Ore B', 50, 'raw_material', 100, 100),
            $this->createTarget(3, 'Component', 100, 'component', 100, 100),
            $this->createTarget(4, 'Metal', 10, 'raw_material', 100, 100),
        ];

        $this->targetRepository->method('findByUser')->willReturn($targets);
        $this->assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([
            1 => 100,  // 100% met
            2 => 30,   // 60% partial
            3 => 200,  // 200% met (over)
            4 => 2,    // 20% critical
        ]);
        $this->jitaMarketService->method('getPrices')->willReturn([
            1 => 10.0,
            2 => 20.0,
            3 => 100.0,
            4 => 50.0,
        ]);

        $status = $this->service->getStockpileStatus($this->user);

        // 2 out of 4 targets met = 50%
        $this->assertSame(50.0, $status['kpis']['pipelineHealth']);
    }

    public function testBottleneckIdentifiesLowestPercent(): void
    {
        $targets = [
            $this->createTarget(1, 'Ore A', 100, 'raw_material', 100, 100),
            $this->createTarget(2, 'Ore B', 100, 'raw_material', 100, 100),
            $this->createTarget(3, 'Worst Item', 100, 'component', 100, 100),
        ];

        $this->targetRepository->method('findByUser')->willReturn($targets);
        $this->assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([
            1 => 80,   // 80%
            2 => 60,   // 60%
            3 => 5,    // 5% - this is the worst
        ]);
        $this->jitaMarketService->method('getPrices')->willReturn([
            1 => 10.0,
            2 => 20.0,
            3 => 100.0,
        ]);

        $status = $this->service->getStockpileStatus($this->user);

        $this->assertNotNull($status['kpis']['bottleneck']);
        $this->assertSame(3, $status['kpis']['bottleneck']['typeId']);
        $this->assertSame('Worst Item', $status['kpis']['bottleneck']['typeName']);
        $this->assertSame(5.0, $status['kpis']['bottleneck']['percent']);
    }

    public function testEstOutputCountsFullyBuildable(): void
    {
        // Two final products from source 100 and source 200
        // Source 100: all upstream targets met
        // Source 200: some upstream targets NOT met
        $targets = [
            $this->createTarget(100, 'Widget A', 10, 'final_product', 100, 100),
            $this->createTarget(1, 'Ore', 50, 'raw_material', 100, 100),
            $this->createTarget(2, 'Frame', 20, 'component', 100, 100),
            $this->createTarget(200, 'Widget B', 5, 'final_product', 200, 200),
            $this->createTarget(3, 'Metal', 100, 'raw_material', 200, 200),
        ];

        $this->targetRepository->method('findByUser')->willReturn($targets);
        $this->assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([
            100 => 10,  // met
            1 => 50,    // met
            2 => 20,    // met
            200 => 5,   // met
            3 => 30,    // NOT met (30/100 = 30%)
        ]);
        $this->jitaMarketService->method('getPrices')->willReturn([
            100 => 1000.0,
            1 => 10.0,
            2 => 50.0,
            200 => 2000.0,
            3 => 20.0,
        ]);

        $status = $this->service->getStockpileStatus($this->user);

        // Widget A (source 100): all targets met -> ready
        // Widget B (source 200): typeId 3 not met -> NOT ready
        $this->assertSame(1, $status['kpis']['estOutput']['ready']);
        $this->assertSame(2, $status['kpis']['estOutput']['total']);
    }

    public function testPreviewImportReturnsCorrectStagesAndEstimatedCost(): void
    {
        $tree = $this->buildMockTree();
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        // Prices for all type IDs in the tree
        $this->jitaMarketService->method('getPrices')->willReturn([
            100 => 5000000.0,  // Widget (final product)
            1 => 10.0,         // Raw Ore
            2 => 500.0,        // Component Frame
            3 => 200.0,        // Sub-Component
            4 => 50.0,         // Raw Metal
        ]);

        $result = $this->service->previewImport($this->user, 100, 1, 0, 0);

        // Verify structure
        $this->assertArrayHasKey('stages', $result);
        $this->assertArrayHasKey('totalItems', $result);
        $this->assertArrayHasKey('estimatedCost', $result);

        // 5 unique type IDs: 100 (final), 1 (raw, merged), 2 (component), 3 (intermediate), 4 (raw)
        $this->assertSame(5, $result['totalItems']);

        // Check stage classification
        $stageNames = array_keys(array_filter($result['stages'], fn (array $items) => !empty($items)));
        $this->assertContains('final_product', $stageNames);
        $this->assertContains('raw_material', $stageNames);
        $this->assertContains('component', $stageNames);
        $this->assertContains('intermediate', $stageNames);

        // Final product: Widget (typeId 100), quantity = 1 * 1 = 1
        $this->assertCount(1, $result['stages']['final_product']);
        $this->assertSame(100, $result['stages']['final_product'][0]['typeId']);
        $this->assertSame(1, $result['stages']['final_product'][0]['quantity']);

        // Raw materials: typeId 1 (merged 500+200=700) and typeId 4 (100)
        $rawTypeIds = array_column($result['stages']['raw_material'], 'typeId');
        $this->assertContains(1, $rawTypeIds);
        $this->assertContains(4, $rawTypeIds);

        // Estimated cost = sum of (quantity * unitPrice) for all items
        // Widget: 1 * 5000000 = 5000000
        // Raw Ore: 700 * 10 = 7000
        // Component Frame: 10 * 500 = 5000
        // Sub-Component: 20 * 200 = 4000
        // Raw Metal: 100 * 50 = 5000
        $expectedCost = 5000000.0 + 7000.0 + 5000.0 + 4000.0 + 5000.0;
        $this->assertSame($expectedCost, $result['estimatedCost']);
    }

    public function testGetStockpileStatusReturnsZeroKpisForEmptyTargets(): void
    {
        $this->targetRepository->method('findByUser')->willReturn([]);

        $status = $this->service->getStockpileStatus($this->user);

        $this->assertSame(0, $status['targetCount']);
        // All 4 stages must always be present, even when empty
        $this->assertCount(4, $status['stages']);
        foreach (['raw_material', 'intermediate', 'component', 'final_product'] as $stage) {
            $this->assertArrayHasKey($stage, $status['stages']);
            $this->assertSame([], $status['stages'][$stage]['items']);
            $this->assertSame(0.0, $status['stages'][$stage]['totalValue']);
            $this->assertSame(100.0, $status['stages'][$stage]['healthPercent']);
        }
        $this->assertSame(100.0, $status['kpis']['pipelineHealth']);
        $this->assertSame(0.0, $status['kpis']['totalInvested']);
        $this->assertNull($status['kpis']['bottleneck']);
        $this->assertSame(0, $status['kpis']['estOutput']['ready']);
        $this->assertSame(0, $status['kpis']['estOutput']['total']);
        $this->assertSame([], $status['kpis']['estOutput']['readyNames']);
        $this->assertSame([], $status['shoppingList']);
    }

    public function testStagesWithNoTargetsStillPresent(): void
    {
        // Targets only in raw_material and final_product — intermediate and component should still exist
        $targets = [
            $this->createTarget(1, 'Ore', 100, 'raw_material', 100, 1),
            $this->createTarget(100, 'Widget', 10, 'final_product', 100, 100),
        ];

        $this->targetRepository->method('findByUser')->willReturn($targets);
        $this->assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([
            1 => 50,
            100 => 10,
        ]);
        $this->jitaMarketService->method('getPrices')->willReturn([
            1 => 10.0,
            100 => 5000.0,
        ]);

        $status = $this->service->getStockpileStatus($this->user);

        // All 4 stages must be present
        $this->assertCount(4, $status['stages']);
        foreach (['raw_material', 'intermediate', 'component', 'final_product'] as $stage) {
            $this->assertArrayHasKey($stage, $status['stages']);
            $this->assertArrayHasKey('items', $status['stages'][$stage]);
            $this->assertArrayHasKey('totalValue', $status['stages'][$stage]);
            $this->assertArrayHasKey('healthPercent', $status['stages'][$stage]);
        }

        // Stages without targets should have empty defaults
        $this->assertSame([], $status['stages']['intermediate']['items']);
        $this->assertSame(0.0, $status['stages']['intermediate']['totalValue']);
        $this->assertSame(100.0, $status['stages']['intermediate']['healthPercent']);

        $this->assertSame([], $status['stages']['component']['items']);
        $this->assertSame(0.0, $status['stages']['component']['totalValue']);
        $this->assertSame(100.0, $status['stages']['component']['healthPercent']);

        // Stages with targets should have items
        $this->assertCount(1, $status['stages']['raw_material']['items']);
        $this->assertCount(1, $status['stages']['final_product']['items']);
    }

    public function testZeroQuantityTargetIsTreatedAsMet(): void
    {
        // A target with targetQuantity = 0 should be considered 100% met
        $targets = [
            $this->createTarget(1, 'Zero Qty Item', 0, 'raw_material', 100, 1),
            $this->createTarget(2, 'Normal Item', 50, 'raw_material', 100, 2),
        ];

        $this->targetRepository->method('findByUser')->willReturn($targets);
        $this->assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([
            1 => 0,   // 0 stock, but target is 0 so it is met (100%)
            2 => 50,   // 100% met
        ]);
        $this->jitaMarketService->method('getPrices')->willReturn([
            1 => 10.0,
            2 => 20.0,
        ]);

        $status = $this->service->getStockpileStatus($this->user);

        // Both targets met: zero-qty is auto-met, normal item is fully stocked
        $this->assertSame(100.0, $status['kpis']['pipelineHealth']);
        $this->assertNull($status['kpis']['bottleneck']); // No unmet targets
    }

    public function testAllTargetsMetGivesFullPipelineHealth(): void
    {
        $targets = [
            $this->createTarget(100, 'Widget', 10, 'final_product', 100, 100),
            $this->createTarget(1, 'Ore', 500, 'raw_material', 100, 1),
            $this->createTarget(2, 'Frame', 20, 'component', 100, 2),
            $this->createTarget(3, 'Metal', 100, 'raw_material', 100, 3),
        ];

        $this->targetRepository->method('findByUser')->willReturn($targets);
        $this->assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([
            100 => 15,   // 150% over-stocked
            1 => 500,    // 100% exact
            2 => 30,     // 150%
            3 => 200,    // 200%
        ]);
        $this->jitaMarketService->method('getPrices')->willReturn([
            100 => 5000000.0,
            1 => 10.0,
            2 => 500.0,
            3 => 50.0,
        ]);

        $status = $this->service->getStockpileStatus($this->user);

        // All 4 targets are met
        $this->assertSame(100.0, $status['kpis']['pipelineHealth']);
        $this->assertNull($status['kpis']['bottleneck']);

        // Shopping list should be empty (no deficits)
        $this->assertSame([], $status['shoppingList']);

        // Estimated output: Widget (source 100) has all upstream met
        $this->assertSame(1, $status['kpis']['estOutput']['ready']);
        $this->assertSame(1, $status['kpis']['estOutput']['total']);
        $this->assertSame(['Widget'], $status['kpis']['estOutput']['readyNames']);

        // Total invested = sum of stock * unitPrice (capped at 100% for percent but stock value counts all)
        // 15 * 5000000 + 500 * 10 + 30 * 500 + 200 * 50 = 75000000 + 5000 + 15000 + 10000 = 75030000
        $this->assertSame(75030000.0, $status['kpis']['totalInvested']);
    }

    /**
     * Create a mock IndustryStockpileTarget with predictable values.
     */
    private function createTarget(int $typeId, string $typeName, int $targetQty, string $stage, int $sourceProductTypeId, int $selfId): IndustryStockpileTarget&MockObject
    {
        $target = $this->createMock(IndustryStockpileTarget::class);
        $uuid = $this->createMock(\Symfony\Component\Uid\Uuid::class);
        $uuid->method('toRfc4122')->willReturn(sprintf('00000000-0000-0000-0000-%012d', $selfId));

        $target->method('getId')->willReturn($uuid);
        $target->method('getTypeId')->willReturn($typeId);
        $target->method('getTypeName')->willReturn($typeName);
        $target->method('getTargetQuantity')->willReturn($targetQty);
        $target->method('getStage')->willReturn($stage);
        $target->method('getSourceProductTypeId')->willReturn($sourceProductTypeId);
        $target->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2026-01-01'));
        $target->method('getUpdatedAt')->willReturn(null);

        return $target;
    }
}
