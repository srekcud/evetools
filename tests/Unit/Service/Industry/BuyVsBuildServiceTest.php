<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Service\Industry\BuyVsBuildService;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\InventionService;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuyVsBuildService::class)]
class BuyVsBuildServiceTest extends TestCase
{
    private IndustryTreeService&MockObject $treeService;
    private JitaMarketService&MockObject $jitaMarketService;
    private StructureMarketService&MockObject $structureMarketService;
    private EsiCostIndexService&MockObject $esiCostIndexService;
    private InventionService&MockObject $inventionService;
    private TypeNameResolver&MockObject $typeNameResolver;
    private IndustryBlacklistService&MockObject $blacklistService;
    private IndustryActivityMaterialRepository&MockObject $materialRepository;
    private BuyVsBuildService $service;

    private const SOLAR_SYSTEM_ID = 30002510;
    private const SELL_STRUCTURE_ID = 1035466617946;

    protected function setUp(): void
    {
        $this->treeService = $this->createMock(IndustryTreeService::class);
        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->structureMarketService = $this->createMock(StructureMarketService::class);
        $this->esiCostIndexService = $this->createMock(EsiCostIndexService::class);
        $this->inventionService = $this->createMock(InventionService::class);
        $this->typeNameResolver = $this->createMock(TypeNameResolver::class);
        $this->blacklistService = $this->createMock(IndustryBlacklistService::class);
        $this->materialRepository = $this->createMock(IndustryActivityMaterialRepository::class);

        $this->service = new BuyVsBuildService(
            $this->treeService,
            $this->jitaMarketService,
            $this->structureMarketService,
            $this->esiCostIndexService,
            $this->inventionService,
            $this->typeNameResolver,
            $this->blacklistService,
            $this->materialRepository,
        );
    }

    /**
     * Build a tree where the product has buildable intermediate components.
     */
    private function buildTreeWithIntermediates(): array
    {
        return [
            'blueprintTypeId' => 22455,
            'productTypeId' => 22456,
            'productTypeName' => 'Sabre',
            'quantity' => 1,
            'runs' => 1,
            'outputPerRun' => 1,
            'depth' => 0,
            'activityType' => 'manufacturing',
            'hasCopy' => false,
            'materials' => [
                [
                    'typeId' => 34, // Tritanium (raw, not buildable)
                    'typeName' => 'Tritanium',
                    'quantity' => 50000,
                    'isBuildable' => false,
                    'activityType' => null,
                ],
                [
                    'typeId' => 11399, // Morphite (raw)
                    'typeName' => 'Morphite',
                    'quantity' => 100,
                    'isBuildable' => false,
                    'activityType' => null,
                ],
                [
                    'typeId' => 11530, // Advanced component (buildable)
                    'typeName' => 'Plasma Thruster',
                    'quantity' => 50,
                    'isBuildable' => true,
                    'activityType' => 'manufacturing',
                    'blueprint' => [
                        'blueprintTypeId' => 11529,
                        'productTypeId' => 11530,
                        'productTypeName' => 'Plasma Thruster',
                        'quantity' => 50,
                        'runs' => 5,
                        'outputPerRun' => 10,
                        'depth' => 1,
                        'activityType' => 'manufacturing',
                        'hasCopy' => false,
                        'materials' => [
                            [
                                'typeId' => 34, // Tritanium
                                'typeName' => 'Tritanium',
                                'quantity' => 10000,
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

    public function testAnalyzeBuildCheaperThanBuy(): void
    {
        $tree = $this->buildTreeWithIntermediates();
        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->inventionService->method('isT2')->willReturn(true);
        $this->typeNameResolver->method('resolve')->willReturnCallback(fn (int $id) => match ($id) {
            22456 => 'Sabre',
            11530 => 'Plasma Thruster',
            default => "Type #{$id}",
        });

        // Jita prices: Plasma Thruster costs 200 ISK each to buy
        // But materials to build are cheap (Tritanium 5.0 * 10000 = 50000 for 50 units = 1000/unit)
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            22456 => 50000000.0,
            34 => 5.0,
            11399 => 15000.0,
            11530 => 200.0,
        ]);

        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $this->structureMarketService->method('getLowestSellPrices')->willReturn([11530 => null]);
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([]);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(1000000.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(100.0);

        $result = $this->service->analyze(
            22456,
            1,
            2,
            self::SOLAR_SYSTEM_ID,
            self::SELL_STRUCTURE_ID,
            0.036,
            0.036,
            null,
        );

        $this->assertSame(22456, $result['typeId']);
        $this->assertSame('Sabre', $result['typeName']);
        $this->assertTrue($result['isT2']);
        $this->assertSame(1, $result['runs']);

        // Should have 1 analyzed component (Plasma Thruster)
        $this->assertCount(1, $result['components']);
        $comp = $result['components'][0];
        $this->assertSame(11530, $comp['typeId']);
        $this->assertSame('Plasma Thruster', $comp['typeName']);
        $this->assertSame(50, $comp['quantity']);

        // Build cost: 10000 * 5.0 + 100 (job install) = 50100
        // Buy cost Jita: 50 * 200 = 10000
        // In this case buy is cheaper (savings = 10000 - 50100 = -40100, negative means building is more expensive)
        $this->assertSame('buy', $comp['verdict']);
        $this->assertLessThan(0.0, $comp['savings']); // Negative savings means buy is cheaper
        $this->assertGreaterThan(0.0, $comp['savingsPercent']);

        // Verify new fields
        $this->assertSame(10000.0, $comp['buyCostJita']);
        $this->assertNull($comp['buyCostStructure']);
        $this->assertSame(100.0, $comp['buildJobInstallCost']);
        $this->assertSame(10, $comp['meUsed']);
        $this->assertSame(5, $comp['runs']);
        $this->assertCount(1, $comp['buildMaterials']);
        $this->assertSame(34, $comp['buildMaterials'][0]['typeId']);
        $this->assertSame(10000, $comp['buildMaterials'][0]['quantity']);
        $this->assertSame(5.0, $comp['buildMaterials'][0]['unitPrice']);
        $this->assertSame(50000.0, $comp['buildMaterials'][0]['totalPrice']);

        $this->assertGreaterThan(0.0, $result['buildAllCost']);
        $this->assertGreaterThan(0.0, $result['buyAllCost']);
        $this->assertGreaterThan(0.0, $result['optimalMixCost']);
    }

    public function testAnalyzeNoBuildableComponents(): void
    {
        // Simple tree with only raw materials
        $tree = [
            'blueprintTypeId' => 586,
            'productTypeId' => 587,
            'productTypeName' => 'Rifter',
            'quantity' => 1,
            'runs' => 1,
            'outputPerRun' => 1,
            'depth' => 0,
            'activityType' => 'manufacturing',
            'hasCopy' => false,
            'materials' => [
                [
                    'typeId' => 34,
                    'typeName' => 'Tritanium',
                    'quantity' => 10000,
                    'isBuildable' => false,
                    'activityType' => null,
                ],
            ],
            'structureBonus' => 0.0,
            'structureName' => null,
            'productCategory' => null,
        ];

        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->inventionService->method('isT2')->willReturn(false);
        $this->typeNameResolver->method('resolve')->willReturnCallback(fn (int $id) => "Type #{$id}");

        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            587 => 100000.0,
            34 => 5.0,
        ]);
        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $this->structureMarketService->method('getLowestSellPrices')->willReturn([]);
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([]);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(50000.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(1000.0);

        $result = $this->service->analyze(
            587, 1, 10,
            self::SOLAR_SYSTEM_ID, self::SELL_STRUCTURE_ID,
            0.0, 0.0, null,
        );

        $this->assertCount(0, $result['components']);
        $this->assertSame(0.0, $result['buildAllCost']);
        $this->assertSame(0.0, $result['buyAllCost']);
        $this->assertSame(0.0, $result['optimalMixCost']);
        $this->assertSame([], $result['buildTypeIds']);
        $this->assertSame([], $result['buyTypeIds']);
    }

    public function testAnalyzeMarginCalculation(): void
    {
        $tree = [
            'blueprintTypeId' => 586,
            'productTypeId' => 587,
            'productTypeName' => 'Rifter',
            'quantity' => 1,
            'runs' => 1,
            'outputPerRun' => 1,
            'depth' => 0,
            'activityType' => 'manufacturing',
            'hasCopy' => false,
            'materials' => [
                [
                    'typeId' => 34,
                    'typeName' => 'Tritanium',
                    'quantity' => 10000,
                    'isBuildable' => false,
                    'activityType' => null,
                ],
            ],
            'structureBonus' => 0.0,
            'structureName' => null,
            'productCategory' => null,
        ];

        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->inventionService->method('isT2')->willReturn(false);
        $this->typeNameResolver->method('resolve')->willReturnCallback(fn (int $id) => "Type #{$id}");

        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            587 => 200000.0,
            34 => 5.0,
        ]);
        $this->structureMarketService->method('getLowestSellPrice')->willReturn(200000.0);
        $this->structureMarketService->method('getLowestSellPrices')->willReturn([]);
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([]);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        $result = $this->service->analyze(
            587, 1, 10,
            self::SOLAR_SYSTEM_ID, self::SELL_STRUCTURE_ID,
            0.0, 0.0, null,
        );

        // Sell price = 200000, no fees
        // Material cost = 10000 * 5 = 50000, job cost = 0
        // totalProductionCost = 50000
        // profit = 200000 - 50000 = 150000
        // margin = (150000 / 50000) * 100 = 300%
        $this->assertSame(50000.0, $result['totalProductionCost']);
        $this->assertSame(200000.0, $result['sellPrice']);
        $this->assertSame(300.0, $result['marginPercent']);
    }

    public function testAnalyzeNoSellPriceReturnsNullMargin(): void
    {
        $tree = [
            'blueprintTypeId' => 586,
            'productTypeId' => 587,
            'productTypeName' => 'Rifter',
            'quantity' => 1,
            'runs' => 1,
            'outputPerRun' => 1,
            'depth' => 0,
            'activityType' => 'manufacturing',
            'hasCopy' => false,
            'materials' => [
                ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 1000, 'isBuildable' => false, 'activityType' => null],
            ],
            'structureBonus' => 0.0,
            'structureName' => null,
            'productCategory' => null,
        ];

        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->inventionService->method('isT2')->willReturn(false);
        $this->typeNameResolver->method('resolve')->willReturnCallback(fn (int $id) => "Type #{$id}");
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([587 => null, 34 => 5.0]);
        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $this->structureMarketService->method('getLowestSellPrices')->willReturn([]);
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([]);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        $result = $this->service->analyze(
            587, 1, 10,
            self::SOLAR_SYSTEM_ID, self::SELL_STRUCTURE_ID,
            0.0, 0.0, null,
        );

        $this->assertNull($result['sellPrice']);
        $this->assertNull($result['marginPercent']);
    }

    public function testAnalyzeComponentsSortedBySavingsDescending(): void
    {
        // Tree with two buildable components at different savings levels
        $tree = [
            'blueprintTypeId' => 100,
            'productTypeId' => 101,
            'productTypeName' => 'Product',
            'quantity' => 1,
            'runs' => 1,
            'outputPerRun' => 1,
            'depth' => 0,
            'activityType' => 'manufacturing',
            'hasCopy' => false,
            'materials' => [
                [
                    'typeId' => 201,
                    'typeName' => 'Cheap Component',
                    'quantity' => 10,
                    'isBuildable' => true,
                    'activityType' => 'manufacturing',
                    'blueprint' => [
                        'blueprintTypeId' => 200, 'productTypeId' => 201, 'productTypeName' => 'Cheap Component',
                        'quantity' => 10, 'runs' => 1, 'outputPerRun' => 10, 'depth' => 1,
                        'activityType' => 'manufacturing', 'hasCopy' => false,
                        'materials' => [
                            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 100, 'isBuildable' => false, 'activityType' => null],
                        ],
                        'structureBonus' => 0.0, 'structureName' => null, 'productCategory' => null,
                    ],
                ],
                [
                    'typeId' => 301,
                    'typeName' => 'Expensive Component',
                    'quantity' => 5,
                    'isBuildable' => true,
                    'activityType' => 'manufacturing',
                    'blueprint' => [
                        'blueprintTypeId' => 300, 'productTypeId' => 301, 'productTypeName' => 'Expensive Component',
                        'quantity' => 5, 'runs' => 1, 'outputPerRun' => 5, 'depth' => 1,
                        'activityType' => 'manufacturing', 'hasCopy' => false,
                        'materials' => [
                            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 1000, 'isBuildable' => false, 'activityType' => null],
                        ],
                        'structureBonus' => 0.0, 'structureName' => null, 'productCategory' => null,
                    ],
                ],
            ],
            'structureBonus' => 0.0, 'structureName' => null, 'productCategory' => null,
        ];

        $this->treeService->method('buildProductionTree')->willReturn($tree);
        $this->inventionService->method('isT2')->willReturn(false);
        $this->typeNameResolver->method('resolve')->willReturnCallback(fn (int $id) => match ($id) {
            101 => 'Product', 201 => 'Cheap Component', 301 => 'Expensive Component', default => "Type #{$id}",
        });

        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            101 => 1000000.0,
            34 => 5.0,
            201 => 100.0,     // Buy: 10 * 100 = 1000
            301 => 10000.0,   // Buy: 5 * 10000 = 50000
        ]);
        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $this->structureMarketService->method('getLowestSellPrices')->willReturn([201 => null, 301 => null]);
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([]);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        $result = $this->service->analyze(
            101, 1, 10,
            self::SOLAR_SYSTEM_ID, self::SELL_STRUCTURE_ID,
            0.0, 0.0, null,
        );

        $this->assertCount(2, $result['components']);
        // Expensive Component should be first (higher savings)
        $this->assertSame(301, $result['components'][0]['typeId']);
        $this->assertSame(201, $result['components'][1]['typeId']);
    }
}
