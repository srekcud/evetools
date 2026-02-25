<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\BatchProfitScannerService;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\InventionService;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(BatchProfitScannerService::class)]
#[AllowMockObjectsWithoutExpectations]
class BatchProfitScannerServiceTest extends TestCase
{
    private IndustryActivityProductRepository&MockObject $productRepository;
    private IndustryActivityMaterialRepository&MockObject $materialRepository;
    private JitaMarketService&MockObject $jitaMarketService;
    private StructureMarketService&MockObject $structureMarketService;
    private EsiCostIndexService&MockObject $esiCostIndexService;
    private TypeNameResolver&MockObject $typeNameResolver;
    private EntityManagerInterface&MockObject $entityManager;
    private Connection&MockObject $connection;
    private CachedStructureRepository&MockObject $cachedStructureRepository;
    private MapSolarSystemRepository&MockObject $solarSystemRepository;
    private InventionService&MockObject $inventionService;
    private BatchProfitScannerService $service;

    private const SOLAR_SYSTEM_ID = 30002510;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->materialRepository = $this->createMock(IndustryActivityMaterialRepository::class);
        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->structureMarketService = $this->createMock(StructureMarketService::class);
        $this->esiCostIndexService = $this->createMock(EsiCostIndexService::class);
        $this->typeNameResolver = $this->createMock(TypeNameResolver::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->cachedStructureRepository = $this->createMock(CachedStructureRepository::class);
        $this->solarSystemRepository = $this->createMock(MapSolarSystemRepository::class);
        $this->inventionService = $this->createMock(InventionService::class);

        $this->entityManager->method('getConnection')->willReturn($this->connection);

        // Default: no T2 products
        $this->inventionService->method('identifyT2Products')->willReturn([]);

        $this->service = new BatchProfitScannerService(
            $this->productRepository,
            $this->materialRepository,
            $this->jitaMarketService,
            $this->structureMarketService,
            $this->esiCostIndexService,
            $this->typeNameResolver,
            $this->entityManager,
            $this->cachedStructureRepository,
            $this->solarSystemRepository,
            $this->inventionService,
        );
    }

    public function testScanReturnsEmptyWhenNoProducts(): void
    {
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([]);

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, 0.036, 0.036, 0.0, null);

        $this->assertSame([], $result);
    }

    public function testScanCalculatesProfitCorrectly(): void
    {
        // 1 product: Rifter (type 587), blueprint 586, manufacturing, 1 output per run
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 586, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        // Not T2 (no invention path)
        $this->connection->method('fetchFirstColumn')->willReturn([]);

        // Type metadata + material volumes (two calls to fetchAllAssociative)
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 587, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 28100.0]]
                : [['type_id' => 34, 'volume' => 0.01], ['type_id' => 35, 'volume' => 0.01]]
        );

        // Materials: 250000 Tritanium, 50000 Pyerite
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [
                ['materialTypeId' => 34, 'quantity' => 250000],
                ['materialTypeId' => 35, 'quantity' => 50000],
            ],
        ]);

        // Jita prices: Trit=5.0, Pyr=12.0, Rifter=2500000.0
        $this->jitaMarketService->method('getPrices')->willReturn([
            34 => 5.0,
            35 => 12.0,
            587 => 2500000.0,
        ]);

        // Daily volumes
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            587 => 15.0,
        ]);

        // Type names
        $this->typeNameResolver->method('resolveMany')->willReturn([
            34 => 'Tritanium',
            35 => 'Pyerite',
            587 => 'Rifter',
        ]);

        // Job install cost (EIV calculated from ME0 materials)
        $this->esiCostIndexService->method('calculateEiv')->willReturn(1850000.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(25000.0);

        // exportCostPerM3 = 0 so importCost = 0
        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, 0.036, 0.036, 0.0, null);

        $this->assertCount(1, $result);
        $this->assertSame(587, $result[0]['typeId']);
        $this->assertSame('Rifter', $result[0]['typeName']);
        $this->assertSame('T1 Ships', $result[0]['categoryLabel']);
        $this->assertSame(10, $result[0]['meUsed']); // T1 = ME 10
        $this->assertSame('manufacturing', $result[0]['activityType']);
        $this->assertArrayHasKey('importCost', $result[0]);
        $this->assertSame(0.0, $result[0]['importCost']); // exportCostPerM3 = 0

        // ME 10: Trit = ceil(250000 * 0.9) = 225000 * 5 = 1125000
        //         Pyr  = ceil(50000 * 0.9) = 45000 * 12 = 540000
        // Material cost = 1665000, job = 25000, total = 1690000
        // Sell = 2500000, fees = 2500000 * 0.072 = 180000, net = 2320000
        // Profit/unit = (2320000 - 1690000) / 1 = 630000
        // Margin = 630000 / 1690000 * 100 = ~37.28%
        $this->assertGreaterThan(30.0, $result[0]['marginPercent']);
        $this->assertGreaterThan(0.0, $result[0]['profitPerUnit']);
        $this->assertSame(15.0, $result[0]['dailyVolume']);
        $this->assertGreaterThan(0.0, $result[0]['iskPerDay']);
    }

    public function testScanFiltersNegativeMargin(): void
    {
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 586, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 587, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 28100.0]]
                : [['type_id' => 34, 'volume' => 0.01]]
        );

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [
                ['materialTypeId' => 34, 'quantity' => 1000000],
            ],
        ]);

        // Expensive materials, cheap sell price
        $this->jitaMarketService->method('getPrices')->willReturn([
            34 => 10.0,
            587 => 5000000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 5.0]);
        $this->typeNameResolver->method('resolveMany')->willReturn([34 => 'Tritanium', 587 => 'Rifter']);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        // Filter: min margin 10%
        $result = $this->service->scan('all', 10.0, null, 'jita', null, self::SOLAR_SYSTEM_ID, 0.036, 0.036, 0.0, null);

        // Should filter out items below 10% margin (or include if above)
        // With ME10: mat cost = ceil(1000000*0.9) * 10 = 9000000, sell = 5000000
        // This is a loss, so the product gets filtered by zero/negative margin
        $this->assertCount(0, $result);
    }

    public function testScanFiltersMinDailyVolume(): void
    {
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 586, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 587, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 28100.0]]
                : [['type_id' => 34, 'volume' => 0.01]]
        );

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [['materialTypeId' => 34, 'quantity' => 100]],
        ]);

        $this->jitaMarketService->method('getPrices')->willReturn([34 => 5.0, 587 => 100000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 2.0]);
        $this->typeNameResolver->method('resolveMany')->willReturn([34 => 'Tritanium', 587 => 'Rifter']);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        // Filter: minDailyVolume = 5
        $result = $this->service->scan('all', null, 5.0, 'jita', null, self::SOLAR_SYSTEM_ID, 0.036, 0.036, 0.0, null);

        // Volume is 2.0, below 5.0 threshold
        $this->assertCount(0, $result);
    }

    public function testScanReactionUsesNoME(): void
    {
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 100, 'productTypeId' => 101, 'outputPerRun' => 1, 'activityId' => 11],
        ]);

        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 101, 'group_id' => 436, 'group_name' => 'Simple Reactions', 'category_id' => 17, 'volume' => 1.0]]
                : [['type_id' => 200, 'volume' => 0.1]]
        );

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            100 => [['materialTypeId' => 200, 'quantity' => 1000]],
        ]);

        $this->jitaMarketService->method('getPrices')->willReturn([200 => 10.0, 101 => 50000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([101 => 100.0]);
        $this->typeNameResolver->method('resolveMany')->willReturn([200 => 'Raw Material', 101 => 'Reaction Product']);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, 0.0, 0.0, 0.0, null);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['meUsed']); // Reaction = ME 0
        $this->assertSame('reaction', $result[0]['activityType']);
        $this->assertSame('Reaction', $result[0]['categoryLabel']);

        // No ME: material cost = 1000 * 10 = 10000, sell = 50000
        $this->assertSame(10000.0, $result[0]['materialCost']);
    }

    public function testCategoriesConstantHasExpectedKeys(): void
    {
        $expectedKeys = ['all', 't1_ships', 't2_ships', 'capitals', 't1_modules', 't2_modules', 'ammo_charges', 'drones', 'rigs', 'components', 'reactions'];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, BatchProfitScannerService::CATEGORIES, "Missing category key: {$key}");
        }
    }

    public function testScanSkipsProductsWithNoSellPrice(): void
    {
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 586, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 587, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 28100.0]]
                : [['type_id' => 34, 'volume' => 0.01]]
        );

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [['materialTypeId' => 34, 'quantity' => 100]],
        ]);

        // No sell price for the product
        $this->jitaMarketService->method('getPrices')->willReturn([34 => 5.0, 587 => null]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0]);
        $this->typeNameResolver->method('resolveMany')->willReturn([34 => 'Tritanium', 587 => 'Rifter']);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, 0.0, 0.0, 0.0, null);

        $this->assertCount(0, $result);
    }

    public function testScanIncludesExportCost(): void
    {
        // Product with known volume (28100 m3) and export cost per m3 = 10.0
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 586, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        $this->connection->method('fetchFirstColumn')->willReturn([]);
        // Tritanium volume = 0.01 m3
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 587, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 28100.0]]
                : [['type_id' => 34, 'volume' => 0.01]]
        );

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [['materialTypeId' => 34, 'quantity' => 100]],
        ]);

        // Cheap materials, expensive product to ensure positive margin even with export cost
        $this->jitaMarketService->method('getPrices')->willReturn([34 => 5.0, 587 => 5000000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0]);
        $this->typeNameResolver->method('resolveMany')->willReturn([34 => 'Tritanium', 587 => 'Rifter']);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        $exportCostPerM3 = 10.0;
        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, 0.0, 0.0, $exportCostPerM3, null);

        $this->assertCount(1, $result);

        // Frigates (group 25, category 6) use packaged volume 2500 m3 instead of assembled 28100 m3
        // Export cost = packaged volume (2500) * exportCostPerM3 (10.0) = 25000.0
        $expectedExportCost = 25000.0;
        $this->assertSame($expectedExportCost, $result[0]['exportCost']);

        // Import cost: ME10 => ceil(100 * 0.9) = 90 units of Tritanium at 0.01 m3 = 0.9 m3 * 10.0 = 9.0
        $this->assertSame(9.0, $result[0]['importCost']);

        // Material cost: ME10 => ceil(100 * 0.9) = 90 * 5.0 = 450.0
        // Total cost = 450.0 (materials) + 0.0 (job) + 9.0 (import) + 25000.0 (export) = 25459.0
        // Net sell = 5000000.0 (no broker/sales tax), profit = 5000000 - 25459 = 4974541.0
        $this->assertSame(450.0, $result[0]['materialCost']);
        $this->assertSame(4974541.0, $result[0]['profitPerUnit']);
    }

    public function testExportCostUsesPackagedVolumeForShips(): void
    {
        // Frigate (group 25, category 6) with assembled volume 28100 m3
        // Should use packaged volume 2500 m3 for export cost
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 586, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 587, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 28100.0]]
                : [['type_id' => 34, 'volume' => 0.01]]
        );

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [['materialTypeId' => 34, 'quantity' => 100]],
        ]);

        $this->jitaMarketService->method('getPrices')->willReturn([34 => 5.0, 587 => 5000000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0]);
        $this->typeNameResolver->method('resolveMany')->willReturn([34 => 'Tritanium', 587 => 'Rifter']);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        $exportCostPerM3 = 1200.0;
        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, 0.0, 0.0, $exportCostPerM3, null);

        $this->assertCount(1, $result);

        // Packaged volume for Frigate (group 25) = 2500 m3
        // Export cost = 2500 * 1200 = 3,000,000 (NOT 28100 * 1200 = 33,720,000)
        $this->assertSame(3000000.0, $result[0]['exportCost']);
    }

    public function testScanIncludesImportCost(): void
    {
        // Rifter with 2 materials, non-zero exportCostPerM3 to test import cost
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 586, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 587, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 28100.0]]
                : [['type_id' => 34, 'volume' => 0.01], ['type_id' => 35, 'volume' => 0.01]]
        );

        // Materials: 250000 Tritanium, 50000 Pyerite
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [
                ['materialTypeId' => 34, 'quantity' => 250000],
                ['materialTypeId' => 35, 'quantity' => 50000],
            ],
        ]);

        $this->jitaMarketService->method('getPrices')->willReturn([
            34 => 5.0,
            35 => 12.0,
            587 => 50000000.0, // High sell price to ensure positive margin
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0]);
        $this->typeNameResolver->method('resolveMany')->willReturn([
            34 => 'Tritanium',
            35 => 'Pyerite',
            587 => 'Rifter',
        ]);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        $exportCostPerM3 = 10.0;
        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, 0.0, 0.0, $exportCostPerM3, null);

        $this->assertCount(1, $result);

        // ME10: Trit = ceil(250000 * 0.9) = 225000, Pyr = ceil(50000 * 0.9) = 45000
        // Material volume: 225000 * 0.01 + 45000 * 0.01 = 2250 + 450 = 2700 m3
        // Import cost = 2700 * 10.0 = 27000.0
        $this->assertSame(27000.0, $result[0]['importCost']);
    }

    public function testScanUsesStructureSellPriceWhenVenueIsStructure(): void
    {
        $structureId = 1035466617946;

        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 586, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 587, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 28100.0]]
                : [['type_id' => 34, 'volume' => 0.01]]
        );

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [['materialTypeId' => 34, 'quantity' => 100]],
        ]);

        // Jita prices for materials only (product sell price comes from structure)
        $this->jitaMarketService->method('getPrices')->willReturn([34 => 5.0, 587 => 100000.0]);

        // Structure sell price is different (higher) than Jita
        $this->structureMarketService->method('getLowestSellPrices')->willReturn([587 => 3000000.0]);

        // Resolve structure region for daily volumes
        $structure = $this->createMock(\App\Entity\CachedStructure::class);
        $structure->method('getSolarSystemId')->willReturn(30004759);
        $this->cachedStructureRepository->method('findByStructureId')->willReturn($structure);

        $solarSystem = $this->createMock(\App\Entity\Sde\MapSolarSystem::class);
        $solarSystem->method('getRegionId')->willReturn(10000060);
        $this->solarSystemRepository->method('find')->willReturn($solarSystem);

        $this->jitaMarketService->method('getAverageDailyVolumesForRegion')->willReturn([587 => 20.0]);
        $this->typeNameResolver->method('resolveMany')->willReturn([34 => 'Tritanium', 587 => 'Rifter']);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        $result = $this->service->scan('all', null, null, 'structure', $structureId, self::SOLAR_SYSTEM_ID, 0.0, 0.0, 0.0, null);

        $this->assertCount(1, $result);
        // Sell price should be from structure (3M), not Jita (100K)
        $this->assertSame(3000000.0, $result[0]['sellPrice']);
        // No export cost when selling at a structure
        $this->assertSame(0.0, $result[0]['exportCost']);
    }

    public function testScanHandlesMultipleOutputPerRun(): void
    {
        // Ammo blueprint: 100 units per run (e.g., Scourge Heavy Missile)
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 2000, 'productTypeId' => 2001, 'outputPerRun' => 100, 'activityId' => 1],
        ]);

        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 2001, 'group_id' => 384, 'group_name' => 'Heavy Missile', 'category_id' => 8, 'volume' => 0.03]]
                : [['type_id' => 34, 'volume' => 0.01]]
        );

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            2000 => [['materialTypeId' => 34, 'quantity' => 5000]],
        ]);

        // Material cost per run (ME10): ceil(5000 * 0.9) = 4500 * 5.0 = 22500 ISK
        // 100 units per run, unit sell price = 500 ISK, revenue per run = 50000 ISK
        $this->jitaMarketService->method('getPrices')->willReturn([34 => 5.0, 2001 => 500.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([2001 => 5000.0]);
        $this->typeNameResolver->method('resolveMany')->willReturn([34 => 'Tritanium', 2001 => 'Scourge Heavy Missile']);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, 0.0, 0.0, 0.0, null);

        $this->assertCount(1, $result);
        $this->assertSame('Scourge Heavy Missile', $result[0]['typeName']);
        $this->assertSame('Ammo & Charges', $result[0]['categoryLabel']);

        // profitPerUnit = (sellPrice * outputPerRun - totalCost) / outputPerRun
        // totalCost = 22500 (materials), sellPrice = 500 per unit, outputPerRun = 100
        // net profit per unit = (500 * 100 - 22500) / 100 = 27500 / 100 = 275.0
        $this->assertSame(275.0, $result[0]['profitPerUnit']);

        // marginPercent = (netProfit * outputPerRun / totalCost) * 100
        // = (275 * 100 / 22500) * 100 = 122.22%
        $this->assertGreaterThan(100.0, $result[0]['marginPercent']);

        // iskPerDay = profitPerUnit * dailyVolume = 275 * 5000 = 1375000
        $this->assertSame(1375000.0, $result[0]['iskPerDay']);
    }

    public function testScanHandlesEmptyMaterialList(): void
    {
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 586, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 587, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 28100.0]]
                : []
        );

        // Empty material list for this blueprint
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [],
        ]);

        $this->jitaMarketService->method('getPrices')->willReturn([587 => 2500000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0]);
        $this->typeNameResolver->method('resolveMany')->willReturn([587 => 'Rifter']);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, 0.0, 0.0, 0.0, null);

        // totalCost = 0 (no materials, no job cost, no transport), product is skipped
        // because the code checks $totalCost <= 0.0
        $this->assertCount(0, $result);
    }

    public function testScanFiltersByCategoryT2Ships(): void
    {
        // Two products: a T1 frigate and a T2 frigate
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 586, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],  // Rifter (T1)
            ['blueprintTypeId' => 11400, 'productTypeId' => 11393, 'outputPerRun' => 1, 'activityId' => 1], // Jaguar (T2)
        ]);

        // Mark Jaguar as T2 via invention service
        $this->inventionService = $this->createMock(InventionService::class);
        $this->inventionService->method('identifyT2Products')->willReturn([11393 => true]);

        // Rebuild service with the new invention mock
        $this->service = new BatchProfitScannerService(
            $this->productRepository,
            $this->materialRepository,
            $this->jitaMarketService,
            $this->structureMarketService,
            $this->esiCostIndexService,
            $this->typeNameResolver,
            $this->entityManager,
            $this->cachedStructureRepository,
            $this->solarSystemRepository,
            $this->inventionService,
        );

        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [
                    ['type_id' => 587, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 28100.0],
                    ['type_id' => 11393, 'group_id' => 324, 'group_name' => 'Assault Frigate', 'category_id' => 6, 'volume' => 28600.0],
                ]
                : [['type_id' => 34, 'volume' => 0.01]]
        );

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [['materialTypeId' => 34, 'quantity' => 100]],
            11400 => [['materialTypeId' => 34, 'quantity' => 200]],
        ]);

        $this->jitaMarketService->method('getPrices')->willReturn([
            34 => 5.0,
            587 => 5000000.0,
            11393 => 80000000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0, 11393 => 5.0]);
        $this->typeNameResolver->method('resolveMany')->willReturn([
            34 => 'Tritanium',
            587 => 'Rifter',
            11393 => 'Jaguar',
        ]);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);

        // Filter: t2_ships only (categoryId=6, onlyT2=true)
        $result = $this->service->scan('t2_ships', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, 0.0, 0.0, 0.0, null);

        // Only Jaguar (T2) should remain, Rifter (T1) filtered out
        $this->assertCount(1, $result);
        $this->assertSame(11393, $result[0]['typeId']);
        $this->assertSame('Jaguar', $result[0]['typeName']);
        $this->assertSame('T2 Ships', $result[0]['categoryLabel']);
        $this->assertSame(2, $result[0]['meUsed']); // T2 = ME 2 (Attainment decryptor default)
    }
}
