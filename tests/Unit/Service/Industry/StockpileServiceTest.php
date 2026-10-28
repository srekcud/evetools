<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStockpileImport;
use App\Entity\IndustryStockpileTarget;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\IndustryStockpileImportRepository;
use App\Repository\IndustryStockpileTargetRepository;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\StockpileService;
use App\Service\Industry\StockpileStartingStockReducer;
use App\Service\JitaMarketService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(StockpileService::class)]
#[AllowMockObjectsWithoutExpectations]
class StockpileServiceTest extends TestCase
{
    private IndustryStockpileTargetRepository&MockObject $targetRepository;
    private CachedAssetRepository&MockObject $assetRepository;
    private JitaMarketService&MockObject $jitaMarketService;
    private IndustryTreeService&MockObject $treeService;
    private EntityManagerInterface&MockObject $entityManager;
    private IndustryStockpileImportRepository&MockObject $importRepository;
    private StockpileService $service;
    private User&MockObject $user;

    protected function setUp(): void
    {
        $this->targetRepository = $this->createMock(IndustryStockpileTargetRepository::class);
        $this->assetRepository = $this->createMock(CachedAssetRepository::class);
        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->treeService = $this->createMock(IndustryTreeService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->importRepository = $this->createMock(IndustryStockpileImportRepository::class);

        $this->service = new StockpileService(
            $this->targetRepository,
            $this->assetRepository,
            $this->jitaMarketService,
            $this->treeService,
            $this->entityManager,
            $this->importRepository,
            new StockpileStartingStockReducer($this->treeService),
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
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([]);

        // Expect deleteAllForUser to be called in replace mode
        $this->targetRepository->expects($this->once())
            ->method('deleteAllForUser')
            ->with($this->user);

        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->atLeastOnce())->method('flush');

        $this->service->importFromBlueprint($this->user, 100, 1, 0, 'replace');
    }

    public function testImportMergeAddsQuantities(): void
    {
        $tree = $this->buildMockTree();
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([]);

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

        $this->service->importFromBlueprint($this->user, 100, 1, 0, 'merge');
    }

    public function testImportInReplaceModeKeepsOnlyTheStockpileImportOfTheLastImport(): void
    {
        $recordedImports = $this->recordStockpileImports();
        $this->treeService->method('buildProductionTree')->willReturn($this->buildMockTree());
        $firstRuns = 2;
        $firstMe = 4;
        $secondRuns = 3;
        $secondMe = 6;
        $replacingRuns = 5;
        $replacingMe = 8;

        $this->service->importFromBlueprint($this->user, 100, $firstRuns, $firstMe, 'merge');
        $this->service->importFromBlueprint($this->user, 100, $secondRuns, $secondMe, 'merge');
        $this->service->importFromBlueprint($this->user, 100, $replacingRuns, $replacingMe, 'replace');

        $this->assertSame([['pilot', 100, 5, 8]], $this->describeStockpileImports($recordedImports));
    }

    public function testImportInMergeModeAddsOneStockpileImport(): void
    {
        $recordedImports = $this->recordStockpileImports();
        $this->treeService->method('buildProductionTree')->willReturn($this->buildMockTree());
        $replacingRuns = 5;
        $replacingMe = 8;
        $mergedRuns = 7;
        $mergedMe = 9;

        $this->service->importFromBlueprint($this->user, 100, $replacingRuns, $replacingMe, 'replace');
        $this->service->importFromBlueprint($this->user, 100, $mergedRuns, $mergedMe, 'merge');

        $this->assertSame([['pilot', 100, 5, 8], ['pilot', 100, 7, 9]], $this->describeStockpileImports($recordedImports));
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
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            1 => ['total' => 100, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 100]]],
            2 => ['total' => 30, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 30]]],
            3 => ['total' => 200, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 200]]],
            4 => ['total' => 2, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 2]]],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
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
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            1 => ['total' => 80, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 80]]],
            2 => ['total' => 60, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 60]]],
            3 => ['total' => 5, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 5]]],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            1 => 10.0,
            2 => 20.0,
            3 => 100.0,
        ]);

        $status = $this->service->getStockpileStatus($this->user);

        $this->assertSame(
            ['typeId' => 3, 'typeName' => 'Worst Item', 'percent' => 5.0, 'blocksProducts' => 0],
            $status['kpis']['bottleneck'],
        );
    }

    public function testEstOutputCountsBuildableAndInStockFinalProducts(): void
    {
        $targets = [
            $this->createTarget(100, 'Widget A', 10, 'final_product', 100, 100),
            $this->createTarget(1, 'Ore', 50, 'raw_material', 100, 100),
            $this->createTarget(2, 'Frame', 20, 'component', 100, 100),
            $this->createTarget(200, 'Widget B', 5, 'final_product', 200, 200),
            $this->createTarget(3, 'Metal', 100, 'raw_material', 200, 200),
        ];

        $this->targetRepository->method('findByUser')->willReturn($targets);
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            100 => ['total' => 10, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 10]]],
            1 => ['total' => 50, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 50]]],
            2 => ['total' => 20, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 20]]],
            200 => ['total' => 5, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 5]]],
            3 => ['total' => 30, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 30]]],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            100 => 1000.0,
            1 => 10.0,
            2 => 50.0,
            200 => 2000.0,
            3 => 20.0,
        ]);

        $status = $this->service->getStockpileStatus($this->user);

        $this->assertSame(2, $status['kpis']['estOutput']['ready']);
        $this->assertSame(2, $status['kpis']['estOutput']['total']);
        $this->assertSame(['Widget A', 'Widget B'], $status['kpis']['estOutput']['readyNames']);
    }

    public function testPreviewImportReturnsCorrectStagesAndEstimatedCost(): void
    {
        $tree = $this->buildMockTree();
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        // Prices for all type IDs in the tree
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            100 => 5000000.0,  // Widget (final product)
            1 => 10.0,         // Raw Ore
            2 => 500.0,        // Component Frame
            3 => 200.0,        // Sub-Component
            4 => 50.0,         // Raw Metal
        ]);

        $result = $this->service->previewImport($this->user, 100, 1, 0);

        // Verify structure
        $this->assertSame(['stages', 'totalItems', 'estimatedCost', 'missingPriceTypeIds'], array_keys($result));
        $this->assertSame([], $result['missingPriceTypeIds']);

        // 5 unique type IDs: 100 (final), 1 (raw, merged), 2 (component), 3 (intermediate), 4 (raw)
        $this->assertSame(5, $result['totalItems']);

        // Check stage classification
        $this->assertSame([
            'raw_material' => [
                ['typeId' => 1, 'typeName' => 'Raw Ore', 'quantity' => 700, 'unitPrice' => 10.0],
                ['typeId' => 4, 'typeName' => 'Raw Metal', 'quantity' => 100, 'unitPrice' => 50.0],
            ],
            'intermediate' => [['typeId' => 3, 'typeName' => 'Sub-Component', 'quantity' => 20, 'unitPrice' => 200.0]],
            'component' => [['typeId' => 2, 'typeName' => 'Component Frame', 'quantity' => 10, 'unitPrice' => 500.0]],
            'final_product' => [['typeId' => 100, 'typeName' => 'Widget', 'quantity' => 1, 'unitPrice' => 5000000.0]],
        ], $result['stages']);

        // Final product: Widget (typeId 100), quantity = 1 * 1 = 1
        $this->assertCount(1, $result['stages']['final_product']);
        $this->assertSame(100, $result['stages']['final_product'][0]['typeId']);
        $this->assertSame(1, $result['stages']['final_product'][0]['quantity']);

        // Raw materials: typeId 1 (merged 500+200=700) and typeId 4 (100)
        $this->assertSame([1 => 700, 4 => 100], array_column($result['stages']['raw_material'], 'quantity', 'typeId'));

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
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            1 => ['total' => 50, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 50]]],
            100 => ['total' => 10, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 10]]],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
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
        $this->assertSame(
            [[1, 50, 50.0, 'partial', 500.0]],
            array_map(static fn (array $item): array => [$item['typeId'], $item['stock'], $item['percent'], $item['status'], $item['stockValue']], $status['stages']['raw_material']['items']),
        );
        $this->assertSame(
            [[100, 10, 100.0, 'met', 50000.0]],
            array_map(static fn (array $item): array => [$item['typeId'], $item['stock'], $item['percent'], $item['status'], $item['stockValue']], $status['stages']['final_product']['items']),
        );
    }

    public function testZeroQuantityTargetIsTreatedAsMet(): void
    {
        // A target with targetQuantity = 0 should be considered 100% met
        $targets = [
            $this->createTarget(1, 'Zero Qty Item', 0, 'raw_material', 100, 1),
            $this->createTarget(2, 'Normal Item', 50, 'raw_material', 100, 2),
        ];

        $this->targetRepository->method('findByUser')->willReturn($targets);
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            1 => ['total' => 0, 'locations' => []],
            2 => ['total' => 50, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 50]]],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
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
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            100 => ['total' => 15, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 15]]],
            1 => ['total' => 500, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 500]]],
            2 => ['total' => 30, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 30]]],
            3 => ['total' => 200, 'locations' => [['locationId' => 1000, 'locationName' => 'Jita', 'systemName' => 'Jita', 'quantity' => 200]]],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
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

    private function recordStockpileImports(): \ArrayObject
    {
        $recordedImports = new \ArrayObject();
        $this->importRepository->method('deleteAllForUser')->willReturnCallback(
            static function () use ($recordedImports): void {
                $recordedImports->exchangeArray([]);
            },
        );
        $this->entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use ($recordedImports): void {
                if ($entity instanceof IndustryStockpileImport) {
                    $recordedImports->append($entity);
                }
            },
        );

        return $recordedImports;
    }

    private function describeStockpileImports(\ArrayObject $recordedImports): array
    {
        return array_map(
            fn (IndustryStockpileImport $stockpileImport): array => [
                $stockpileImport->getUser() === $this->user ? 'pilot' : 'another user',
                $stockpileImport->getProductTypeId(),
                $stockpileImport->getRuns(),
                $stockpileImport->getMe(),
            ],
            $recordedImports->getArrayCopy(),
        );
    }

    public function testEstOutputCountsOnlyTheFinalProductsInStockOrWhoseUpstreamTargetsAreAllMet(): void
    {
        $this->targetRepository->method('findByUser')->willReturn([
            $this->createTarget(587, 'Rifter', 4, 'final_product', 587, 1),
            $this->createTarget(34, 'Tritanium', 3_000, 'raw_material', 587, 2),
            $this->createTarget(638, 'Raven', 2, 'final_product', 638, 3),
            $this->createTarget(36, 'Mexallon', 900, 'raw_material', 638, 4),
            $this->createTarget(16, 'Nocxium', 75, 'raw_material', 638, 5),
        ]);
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            34 => ['total' => 3_000, 'locations' => []],
            638 => ['total' => 1, 'locations' => []],
            36 => ['total' => 900, 'locations' => []],
            16 => ['total' => 40, 'locations' => []],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([587 => 512_000.0, 34 => 4.75, 638 => 141_000_000.0, 36 => 68.0, 16 => 815.0]);

        $status = $this->service->getStockpileStatus($this->user);

        $this->assertSame(['ready' => 1, 'total' => 2, 'readyNames' => ['Rifter']], $status['kpis']['estOutput']);
    }

    public function testPipelineHealthIsTheShareOfMetTargetsRoundedToOneDecimal(): void
    {
        $this->targetRepository->method('findByUser')->willReturn([
            $this->createTarget(34, 'Tritanium', 900, 'raw_material', 587, 1),
            $this->createTarget(35, 'Pyerite', 400, 'raw_material', 587, 2),
            $this->createTarget(36, 'Mexallon', 250, 'raw_material', 587, 3),
        ]);
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            34 => ['total' => 900, 'locations' => []],
            35 => ['total' => 120, 'locations' => []],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 4.75, 35 => 11.2, 36 => 68.0]);

        $status = $this->service->getStockpileStatus($this->user);

        $this->assertSame(33.3, $status['kpis']['pipelineHealth']);
        $this->assertSame(33.3, $status['stages']['raw_material']['healthPercent']);
    }

    public function testBottleneckBlocksOnlyTheFinalProductOfItsSourceNotTheOtherTargetsOfThatSource(): void
    {
        $this->targetRepository->method('findByUser')->willReturn([
            $this->createTarget(587, 'Rifter', 3, 'final_product', 587, 1),
            $this->createTarget(34, 'Tritanium', 1_000, 'raw_material', 587, 2),
            $this->createTarget(3828, 'Construction Blocks', 20, 'component', 587, 3),
        ]);
        $this->assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            587 => ['total' => 3, 'locations' => []],
            34 => ['total' => 50, 'locations' => []],
            3828 => ['total' => 15, 'locations' => []],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([587 => 512_000.0, 34 => 4.75, 3828 => 1_430.0]);

        $status = $this->service->getStockpileStatus($this->user);

        $this->assertSame(
            ['typeId' => 34, 'typeName' => 'Tritanium', 'percent' => 5.0, 'blocksProducts' => 1],
            $status['kpis']['bottleneck'],
        );
    }

    /**
     * Create a mock IndustryStockpileTarget with predictable values.
     */
    private function createTarget(int $typeId, string $typeName, int $targetQty, string $stage, int $sourceProductTypeId, int $selfId): IndustryStockpileTarget
    {
        $target = (new IndustryStockpileTarget())
            ->setUser($this->user)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setTargetQuantity($targetQty)
            ->setStage($stage)
            ->setSourceProductTypeId($sourceProductTypeId);
        (new \ReflectionProperty(IndustryStockpileTarget::class, 'id'))
            ->setValue($target, Uuid::fromString(sprintf('00000000-0000-4000-8000-%012d', $selfId)));

        return $target;
    }
}
