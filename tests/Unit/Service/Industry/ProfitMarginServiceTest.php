<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\User;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\InventionService;
use App\Service\Industry\ProfitMarginService;
use App\Service\Industry\PublicContractPriceService;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfitMarginService::class)]
class ProfitMarginServiceTest extends TestCase
{
    private IndustryTreeService&MockObject $treeService;
    private JitaMarketService&MockObject $jitaMarketService;
    private StructureMarketService&MockObject $structureMarketService;
    private EsiCostIndexService&MockObject $esiCostIndexService;
    private InventionService&MockObject $inventionService;
    private PublicContractPriceService&MockObject $publicContractPriceService;
    private TypeNameResolver&MockObject $typeNameResolver;
    private CachedStructureRepository&MockObject $cachedStructureRepository;
    private IndustryBlacklistService&MockObject $blacklistService;
    private IndustryStructureConfigRepository&MockObject $structureConfigRepository;
    private IndustryActivityMaterialRepository&MockObject $materialRepository;
    private ProfitMarginService $service;

    // EVE IDs
    private const RIFTER_TYPE_ID = 587;
    private const SABRE_TYPE_ID = 22456;
    private const SELL_STRUCTURE_ID = 1035466617946;
    private const SOLAR_SYSTEM_ID = 30002510;

    protected function setUp(): void
    {
        $this->treeService = $this->createMock(IndustryTreeService::class);
        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->structureMarketService = $this->createMock(StructureMarketService::class);
        $this->esiCostIndexService = $this->createMock(EsiCostIndexService::class);
        $this->inventionService = $this->createMock(InventionService::class);
        $this->publicContractPriceService = $this->createMock(PublicContractPriceService::class);
        $this->typeNameResolver = $this->createMock(TypeNameResolver::class);
        $this->cachedStructureRepository = $this->createMock(CachedStructureRepository::class);
        $this->blacklistService = $this->createMock(IndustryBlacklistService::class);
        $this->structureConfigRepository = $this->createMock(IndustryStructureConfigRepository::class);
        $this->materialRepository = $this->createMock(IndustryActivityMaterialRepository::class);

        $this->service = new ProfitMarginService(
            $this->treeService,
            $this->jitaMarketService,
            $this->structureMarketService,
            $this->esiCostIndexService,
            $this->inventionService,
            $this->publicContractPriceService,
            $this->typeNameResolver,
            $this->cachedStructureRepository,
            $this->blacklistService,
            $this->structureConfigRepository,
            $this->materialRepository,
        );
    }

    // ===========================================
    // Helper: build a simple tree for flat T1 items
    // ===========================================

    /**
     * @param list<array{typeId: int, typeName: string, quantity: int}> $materials
     */
    private function buildFlatTree(int $productTypeId, string $productName, int $runs, int $outputPerRun, array $materials): array
    {
        $materialNodes = [];
        foreach ($materials as $mat) {
            $materialNodes[] = [
                'typeId' => $mat['typeId'],
                'typeName' => $mat['typeName'],
                'quantity' => $mat['quantity'],
                'isBuildable' => false,
                'activityType' => null,
            ];
        }

        return [
            'blueprintTypeId' => $productTypeId - 1,
            'productTypeId' => $productTypeId,
            'productTypeName' => $productName,
            'quantity' => $runs,
            'runs' => $runs,
            'outputPerRun' => $outputPerRun,
            'depth' => 0,
            'activityType' => 'manufacturing',
            'hasCopy' => false,
            'materials' => $materialNodes,
            'structureBonus' => 0.0,
            'structureName' => null,
            'productCategory' => null,
        ];
    }

    private function setupDefaultMocks(array $dailyVolumes = []): void
    {
        $this->blacklistService->method('resolveBlacklistedTypeIds')->willReturn([]);
        $this->typeNameResolver->method('resolve')->willReturnCallback(fn (int $id) => "Type #{$id}");
        $this->cachedStructureRepository->method('findByStructureId')->willReturn(null);
        $this->jitaMarketService->method('getAverageDailyVolumes')->willReturn($dailyVolumes);
        $this->structureConfigRepository->method('findByUser')->willReturn([]);
        $this->publicContractPriceService->method('getLowestUnitPrice')->willReturn(null);
        $this->publicContractPriceService->method('getContractCount')->willReturn(0);
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([]);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(500000.0);
    }

    // ===========================================
    // T1 item: correct margin calculation
    // ===========================================

    public function testAnalyzeT1ItemCorrectMarginCalculation(): void
    {
        $this->setupDefaultMocks();
        $this->inventionService->method('isT2')->willReturn(false);

        // Tree: Rifter needs 250000 Tritanium + 50000 Pyerite
        $tree = $this->buildFlatTree(self::RIFTER_TYPE_ID, 'Rifter', 10, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 250000],
            ['typeId' => 35, 'typeName' => 'Pyerite', 'quantity' => 50000],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        // Material prices (weighted)
        $this->jitaMarketService
            ->method('getWeightedSellPricesWithFallback')
            ->willReturn([
                34 => ['weightedPrice' => 5.50, 'coverage' => 1.0, 'ordersUsed' => 1],
                35 => ['weightedPrice' => 12.00, 'coverage' => 1.0, 'ordersUsed' => 1],
            ]);

        // Job install cost
        $this->esiCostIndexService
            ->method('calculateJobInstallCost')
            ->willReturn(25000.0);

        // Sell prices
        $this->jitaMarketService
            ->method('getWeightedSellPrice')
            ->willReturn(['weightedPrice' => 250000.0, 'coverage' => 1.0, 'ordersUsed' => 3]);

        $this->structureMarketService->method('getLowestSellPrice')->willReturn(240000.0);
        $this->structureMarketService->method('getHighestBuyPrice')->willReturn(200000.0);

        $result = $this->service->analyze(
            self::RIFTER_TYPE_ID,
            10,
            10,
            20,
            self::SELL_STRUCTURE_ID,
            self::SOLAR_SYSTEM_ID,
            null,
            0.036, // broker fee rate
            0.036, // sales tax rate
            null,
        );

        // Material cost: 250000*5.50 + 50000*12.00 = 1375000 + 600000 = 1975000
        $this->assertSame(1975000.0, $result['materialCost']);
        $this->assertSame(self::RIFTER_TYPE_ID, $result['typeId']);
        $this->assertFalse($result['isT2']);
        $this->assertSame(10, $result['runs']);
        $this->assertSame(10, $result['outputQuantity']);
        $this->assertSame(1, $result['outputPerRun']);
        $this->assertSame(0.0, $result['inventionCost']); // T1, no invention
        $this->assertNull($result['invention']); // T1, no invention details

        // Total cost = material + jobInstall + invention + copy
        $expectedTotal = 1975000.0 + 25000.0;
        $this->assertSame($expectedTotal, $result['totalCost']);
        $this->assertSame($expectedTotal / 10, $result['costPerUnit']);

        // Margins
        $this->assertNotNull($result['margins']['jitaSell']);
        $jitaMargin = $result['margins']['jitaSell'];
        // grossRevenue = 250000 * 10 = 2500000
        // fees = 2500000 * (0.036 + 0.036) = 180000
        // netRevenue = 2500000 - 180000 = 2320000
        // profit = 2320000 - 2000000 = 320000
        // margin = (320000 / 2000000) * 100 = 16.0
        $this->assertSame(2500000.0, $jitaMargin['revenue']);
        $this->assertSame(180000.0, $jitaMargin['fees']);
        $this->assertSame(320000.0, $jitaMargin['profit']);
        $this->assertSame(16.0, $jitaMargin['margin']);
    }

    // ===========================================
    // T2 item: includes invention cost
    // ===========================================

    public function testAnalyzeT2ItemIncludesInventionCost(): void
    {
        $this->setupDefaultMocks();
        $this->inventionService->method('isT2')->willReturn(true);

        $tree = $this->buildFlatTree(self::SABRE_TYPE_ID, 'Sabre', 10, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 100000],
        ]);
        $tree['hasCopy'] = true; // T2 has copy activity
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        $this->jitaMarketService
            ->method('getWeightedSellPricesWithFallback')
            ->willReturn([
                34 => ['weightedPrice' => 5.0, 'coverage' => 1.0, 'ordersUsed' => 1],
            ]);

        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(50000.0);

        // Invention cost
        $this->inventionService
            ->method('calculateInventionCost')
            ->willReturn([
                'baseProbability' => 0.30,
                'effectiveProbability' => 0.30,
                'expectedAttempts' => 4,
                'me' => 2,
                'te' => 4,
                'runs' => 10,
                'costPerAttempt' => 300000.0,
                'totalCost' => 1200000.0,
                'costBreakdown' => [
                    'datacores' => 200000.0,
                    'decryptor' => 0.0,
                    'copyCost' => 50000.0,
                    'inventionInstall' => 50000.0,
                ],
                'datacores' => [],
                'decryptorName' => null,
            ]);

        $this->inventionService
            ->method('buildDecryptorOptions')
            ->willReturn([]);

        // Copy cost for blueprints with hasCopy
        $this->inventionService
            ->method('getCopyJobCost')
            ->willReturn(10000.0);

        $this->jitaMarketService
            ->method('getWeightedSellPrice')
            ->willReturn(['weightedPrice' => 300000.0, 'coverage' => 1.0, 'ordersUsed' => 1]);

        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $this->structureMarketService->method('getHighestBuyPrice')->willReturn(null);

        $result = $this->service->analyze(
            self::SABRE_TYPE_ID,
            10,
            2,
            4,
            self::SELL_STRUCTURE_ID,
            self::SOLAR_SYSTEM_ID,
            null,
            0.036,
            0.036,
            null,
        );

        $this->assertTrue($result['isT2']);
        $this->assertSame(1200000.0, $result['inventionCost']);
        $this->assertNotNull($result['invention']);

        // Total = materials + jobInstall + invention + copy
        // = 500000 + 50000 + 1200000 + 10000 = 1760000
        $this->assertSame(1760000.0, $result['totalCost']);
    }

    // ===========================================
    // Fee calculation
    // ===========================================

    public function testFeeCalculationBrokerAndSalesTax(): void
    {
        $this->setupDefaultMocks();
        $this->inventionService->method('isT2')->willReturn(false);

        $tree = $this->buildFlatTree(self::RIFTER_TYPE_ID, 'Rifter', 1, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 10000],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        $this->jitaMarketService
            ->method('getWeightedSellPricesWithFallback')
            ->willReturn([34 => ['weightedPrice' => 5.0, 'coverage' => 1.0, 'ordersUsed' => 1]]);

        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        // Sell at 1000000 ISK
        $this->jitaMarketService
            ->method('getWeightedSellPrice')
            ->willReturn(['weightedPrice' => 1000000.0, 'coverage' => 1.0, 'ordersUsed' => 1]);

        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $this->structureMarketService->method('getHighestBuyPrice')->willReturn(null);

        $result = $this->service->analyze(
            self::RIFTER_TYPE_ID,
            1, 10, 20,
            self::SELL_STRUCTURE_ID, self::SOLAR_SYSTEM_ID, null,
            0.05, // 5% broker fee
            0.08, // 8% sales tax
            null,
        );

        $jitaMargin = $result['margins']['jitaSell'];
        // grossRevenue = 1000000 * 1 = 1000000
        // fees = 1000000 * (0.05 + 0.08) = 130000
        // netRevenue = 870000
        $this->assertSame(1000000.0, $jitaMargin['revenue']);
        $this->assertSame(130000.0, $jitaMargin['fees']);
        // profit = 870000 - 50000 (materials) = 820000
        $this->assertSame(820000.0, $jitaMargin['profit']);
    }

    // ===========================================
    // Margin percent calculation
    // ===========================================

    public function testMarginPercentCalculation(): void
    {
        $this->setupDefaultMocks();
        $this->inventionService->method('isT2')->willReturn(false);

        $tree = $this->buildFlatTree(self::RIFTER_TYPE_ID, 'Rifter', 1, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 100000],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        $this->jitaMarketService
            ->method('getWeightedSellPricesWithFallback')
            ->willReturn([34 => ['weightedPrice' => 10.0, 'coverage' => 1.0, 'ordersUsed' => 1]]);

        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        // Sell at 2000000 ISK (cost is 1000000)
        $this->jitaMarketService
            ->method('getWeightedSellPrice')
            ->willReturn(['weightedPrice' => 2000000.0, 'coverage' => 1.0, 'ordersUsed' => 1]);

        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $this->structureMarketService->method('getHighestBuyPrice')->willReturn(null);

        $result = $this->service->analyze(
            self::RIFTER_TYPE_ID,
            1, 10, 20,
            self::SELL_STRUCTURE_ID, self::SOLAR_SYSTEM_ID, null,
            0.0, // no broker fee
            0.0, // no sales tax
            null,
        );

        $jitaMargin = $result['margins']['jitaSell'];
        // profit = 2000000 - 1000000 = 1000000
        // margin = (1000000 / 1000000) * 100 = 100.0%
        $this->assertSame(100.0, $jitaMargin['margin']);
    }

    // ===========================================
    // Multiple venues with independent margins
    // ===========================================

    public function testMultipleVenuesHaveIndependentMargins(): void
    {
        $this->setupDefaultMocks();
        $this->inventionService->method('isT2')->willReturn(false);

        $tree = $this->buildFlatTree(self::RIFTER_TYPE_ID, 'Rifter', 1, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 10000],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        $this->jitaMarketService
            ->method('getWeightedSellPricesWithFallback')
            ->willReturn([34 => ['weightedPrice' => 5.0, 'coverage' => 1.0, 'ordersUsed' => 1]]);

        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        // Different prices at different venues
        $this->jitaMarketService
            ->method('getWeightedSellPrice')
            ->willReturn(['weightedPrice' => 100000.0, 'coverage' => 1.0, 'ordersUsed' => 1]); // Jita sell

        $this->structureMarketService
            ->method('getLowestSellPrice')
            ->willReturn(95000.0); // Structure sell (lower)

        $this->structureMarketService
            ->method('getHighestBuyPrice')
            ->willReturn(80000.0); // Structure buy (lowest)

        $result = $this->service->analyze(
            self::RIFTER_TYPE_ID,
            1, 0, 0,
            self::SELL_STRUCTURE_ID, self::SOLAR_SYSTEM_ID, null,
            0.0, 0.0,
            null,
        );

        // All three venues should have margins
        $this->assertNotNull($result['margins']['jitaSell']);
        $this->assertNotNull($result['margins']['structureSell']);
        $this->assertNotNull($result['margins']['structureBuy']);

        // Jita sell > structure sell > structure buy revenue
        $this->assertGreaterThan(
            $result['margins']['structureSell']['revenue'],
            $result['margins']['jitaSell']['revenue'],
        );
        $this->assertGreaterThan(
            $result['margins']['structureBuy']['revenue'],
            $result['margins']['structureSell']['revenue'],
        );
    }

    // ===========================================
    // Negative margin
    // ===========================================

    public function testNegativeMarginShowsCorrectly(): void
    {
        $this->setupDefaultMocks();
        $this->inventionService->method('isT2')->willReturn(false);

        // Very expensive materials, low sell price
        $tree = $this->buildFlatTree(self::RIFTER_TYPE_ID, 'Rifter', 1, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 1000000],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        $this->jitaMarketService
            ->method('getWeightedSellPricesWithFallback')
            ->willReturn([34 => ['weightedPrice' => 10.0, 'coverage' => 1.0, 'ordersUsed' => 1]]);

        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        // Sell at 5M but cost is 10M
        $this->jitaMarketService
            ->method('getWeightedSellPrice')
            ->willReturn(['weightedPrice' => 5000000.0, 'coverage' => 1.0, 'ordersUsed' => 1]);

        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $this->structureMarketService->method('getHighestBuyPrice')->willReturn(null);

        $result = $this->service->analyze(
            self::RIFTER_TYPE_ID,
            1, 0, 0,
            self::SELL_STRUCTURE_ID, self::SOLAR_SYSTEM_ID, null,
            0.0, 0.0,
            null,
        );

        $jitaMargin = $result['margins']['jitaSell'];
        // profit = 5000000 - 10000000 = -5000000
        $this->assertLessThan(0, $jitaMargin['profit']);
        $this->assertLessThan(0, $jitaMargin['margin']);
    }

    // ===========================================
    // Missing sell price: venue margin is null
    // ===========================================

    public function testMissingSellPriceReturnsNullMargin(): void
    {
        $this->setupDefaultMocks();
        $this->inventionService->method('isT2')->willReturn(false);

        $tree = $this->buildFlatTree(self::RIFTER_TYPE_ID, 'Rifter', 1, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 1000],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        $this->jitaMarketService
            ->method('getWeightedSellPricesWithFallback')
            ->willReturn([34 => ['weightedPrice' => 5.0, 'coverage' => 1.0, 'ordersUsed' => 1]]);

        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        // No sell prices available
        $this->jitaMarketService->method('getWeightedSellPrice')->willReturn(null);
        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $this->structureMarketService->method('getHighestBuyPrice')->willReturn(null);

        $result = $this->service->analyze(
            self::RIFTER_TYPE_ID,
            1, 0, 0,
            self::SELL_STRUCTURE_ID, self::SOLAR_SYSTEM_ID, null,
            0.036, 0.036,
            null,
        );

        $this->assertNull($result['margins']['jitaSell']);
        $this->assertNull($result['margins']['structureSell']);
        $this->assertNull($result['margins']['structureBuy']);
    }

    // ===========================================
    // Zero sell price also returns null margin
    // ===========================================

    public function testZeroSellPriceReturnsNullMargin(): void
    {
        $this->setupDefaultMocks();
        $this->inventionService->method('isT2')->willReturn(false);

        $tree = $this->buildFlatTree(self::RIFTER_TYPE_ID, 'Rifter', 1, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 1000],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        $this->jitaMarketService
            ->method('getWeightedSellPricesWithFallback')
            ->willReturn([34 => ['weightedPrice' => 5.0, 'coverage' => 1.0, 'ordersUsed' => 1]]);

        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        // Price is 0 (effectively no market)
        $this->jitaMarketService
            ->method('getWeightedSellPrice')
            ->willReturn(['weightedPrice' => 0.0, 'coverage' => 0.0, 'ordersUsed' => 0]);

        $this->structureMarketService->method('getLowestSellPrice')->willReturn(0.0);
        $this->structureMarketService->method('getHighestBuyPrice')->willReturn(0.0);

        $result = $this->service->analyze(
            self::RIFTER_TYPE_ID,
            1, 0, 0,
            self::SELL_STRUCTURE_ID, self::SOLAR_SYSTEM_ID, null,
            0.036, 0.036,
            null,
        );

        // 0.0 price should return null margins (computeMarginForPrice guards against <= 0)
        $this->assertNull($result['margins']['jitaSell']);
        $this->assertNull($result['margins']['structureSell']);
        $this->assertNull($result['margins']['structureBuy']);
    }

    // ===========================================
    // Decryptor options populated for T2, null for T1
    // ===========================================

    public function testDecryptorOptionsForT1IsNull(): void
    {
        $this->setupDefaultMocks();
        $this->inventionService->method('isT2')->willReturn(false);

        $tree = $this->buildFlatTree(self::RIFTER_TYPE_ID, 'Rifter', 1, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 1000],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        $this->jitaMarketService->method('getWeightedSellPricesWithFallback')
            ->willReturn([34 => ['weightedPrice' => 5.0, 'coverage' => 1.0, 'ordersUsed' => 1]]);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);
        $this->jitaMarketService->method('getWeightedSellPrice')->willReturn(null);
        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $this->structureMarketService->method('getHighestBuyPrice')->willReturn(null);

        $result = $this->service->analyze(
            self::RIFTER_TYPE_ID, 1, 0, 0,
            self::SELL_STRUCTURE_ID, self::SOLAR_SYSTEM_ID, null,
            0.036, 0.036, null,
        );

        $this->assertNull($result['invention']);
    }

    public function testDecryptorOptionsPopulatedForT2(): void
    {
        $this->setupDefaultMocks();
        $this->inventionService->method('isT2')->willReturn(true);

        $tree = $this->buildFlatTree(self::SABRE_TYPE_ID, 'Sabre', 1, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 1000],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        $this->jitaMarketService->method('getWeightedSellPricesWithFallback')
            ->willReturn([34 => ['weightedPrice' => 5.0, 'coverage' => 1.0, 'ordersUsed' => 1]]);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);
        $this->jitaMarketService->method('getWeightedSellPrice')->willReturn(null);
        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $this->structureMarketService->method('getHighestBuyPrice')->willReturn(null);

        $this->inventionService
            ->method('calculateInventionCost')
            ->willReturn([
                'baseProbability' => 0.30,
                'effectiveProbability' => 0.30,
                'expectedAttempts' => 4,
                'me' => 2, 'te' => 4, 'runs' => 10,
                'costPerAttempt' => 100000.0,
                'totalCost' => 400000.0,
                'costBreakdown' => ['datacores' => 80000.0, 'decryptor' => 0.0, 'copyCost' => 10000.0, 'inventionInstall' => 10000.0],
                'datacores' => [['typeId' => 20424, 'typeName' => 'Datacore', 'quantity' => 2, 'unitPrice' => 40000.0, 'totalPrice' => 80000.0]],
                'decryptorName' => null,
            ]);

        $this->inventionService
            ->method('buildDecryptorOptions')
            ->willReturn([
                ['decryptorTypeId' => null, 'decryptorName' => 'No Decryptor', 'me' => 2, 'te' => 4, 'runs' => 10, 'probability' => 0.30, 'costPerAttempt' => 100000.0, 'expectedAttempts' => 4, 'totalCost' => 400000.0, 'costBreakdown' => []],
                ['decryptorTypeId' => 34201, 'decryptorName' => 'Accelerant Decryptor', 'me' => 4, 'te' => 14, 'runs' => 11, 'probability' => 0.36, 'costPerAttempt' => 200000.0, 'expectedAttempts' => 3, 'totalCost' => 600000.0, 'costBreakdown' => []],
            ]);

        $this->inventionService->method('getCopyJobCost')->willReturn(0.0);

        $result = $this->service->analyze(
            self::SABRE_TYPE_ID, 1, 2, 4,
            self::SELL_STRUCTURE_ID, self::SOLAR_SYSTEM_ID, null,
            0.036, 0.036, null,
        );

        $this->assertNotNull($result['invention']);
        $this->assertCount(2, $result['invention']['options']);
        $this->assertSame('No Decryptor', $result['invention']['options'][0]['decryptorName']);
        $this->assertArrayHasKey('inventionCost', $result['invention']['options'][0]);
        $this->assertArrayHasKey('totalProductionCost', $result['invention']['options'][0]);
        $this->assertArrayHasKey('bestMargin', $result['invention']['options'][0]);
        $this->assertArrayHasKey('selectedDecryptorTypeId', $result['invention']);
        $this->assertArrayHasKey('selectedDecryptorName', $result['invention']);
        // datacores should be string[] (type names)
        $this->assertIsArray($result['invention']['datacores']);
        if (count($result['invention']['datacores']) > 0) {
            $this->assertIsString($result['invention']['datacores'][0]);
        }
    }

    // ===========================================
    // Daily volume returned
    // ===========================================

    public function testDailyVolumeIsReturned(): void
    {
        $this->setupDefaultMocks([self::RIFTER_TYPE_ID => 42.5]);
        $this->inventionService->method('isT2')->willReturn(false);

        $tree = $this->buildFlatTree(self::RIFTER_TYPE_ID, 'Rifter', 1, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 100],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        $this->jitaMarketService->method('getWeightedSellPricesWithFallback')
            ->willReturn([34 => ['weightedPrice' => 5.0, 'coverage' => 1.0, 'ordersUsed' => 1]]);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);
        $this->jitaMarketService->method('getWeightedSellPrice')->willReturn(null);
        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $this->structureMarketService->method('getHighestBuyPrice')->willReturn(null);

        $result = $this->service->analyze(
            self::RIFTER_TYPE_ID, 1, 0, 0,
            self::SELL_STRUCTURE_ID, self::SOLAR_SYSTEM_ID, null,
            0.0, 0.0, null,
        );

        $this->assertSame(42.5, $result['dailyVolume']);
    }

    // ===========================================
    // Output quantity with outputPerRun > 1
    // ===========================================

    public function testOutputQuantityWithMultiplePerRun(): void
    {
        $this->setupDefaultMocks();
        $this->inventionService->method('isT2')->willReturn(false);

        // Ammunition: 100 per run, 5 runs = 500 output
        $tree = $this->buildFlatTree(1000, 'Ammo', 5, 100, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 500],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        $this->jitaMarketService->method('getWeightedSellPricesWithFallback')
            ->willReturn([34 => ['weightedPrice' => 5.0, 'coverage' => 1.0, 'ordersUsed' => 1]]);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);
        $this->jitaMarketService->method('getWeightedSellPrice')->willReturn(null);
        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $this->structureMarketService->method('getHighestBuyPrice')->willReturn(null);

        $result = $this->service->analyze(
            1000, 5, 0, 0,
            self::SELL_STRUCTURE_ID, self::SOLAR_SYSTEM_ID, null,
            0.0, 0.0, null,
        );

        $this->assertSame(500, $result['outputQuantity']);
        $this->assertSame(100, $result['outputPerRun']);
        // costPerUnit = 2500 / 500 = 5.0
        $this->assertSame(5.0, $result['costPerUnit']);
    }

    // ===========================================
    // No solar system: job install skipped
    // ===========================================

    public function testNoSolarSystemSkipsJobInstallCost(): void
    {
        $this->setupDefaultMocks();
        $this->inventionService->method('isT2')->willReturn(false);

        $tree = $this->buildFlatTree(self::RIFTER_TYPE_ID, 'Rifter', 1, 1, [
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 1000],
        ]);
        $this->treeService->method('buildProductionTree')->willReturn($tree);

        $this->jitaMarketService->method('getWeightedSellPricesWithFallback')
            ->willReturn([34 => ['weightedPrice' => 5.0, 'coverage' => 1.0, 'ordersUsed' => 1]]);

        // calculateJobInstallCost should NOT be called when solarSystemId is null
        $this->esiCostIndexService
            ->expects($this->never())
            ->method('calculateJobInstallCost');

        $this->jitaMarketService->method('getWeightedSellPrice')->willReturn(null);
        $this->structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $this->structureMarketService->method('getHighestBuyPrice')->willReturn(null);

        $result = $this->service->analyze(
            self::RIFTER_TYPE_ID, 1, 0, 0,
            self::SELL_STRUCTURE_ID,
            null, // No solar system
            null,
            0.036, 0.036, null,
        );

        $this->assertSame(0.0, $result['jobInstallCost']);
    }
}
