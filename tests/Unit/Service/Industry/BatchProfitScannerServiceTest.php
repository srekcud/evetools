<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\BatchProfitScannerService;
use App\Service\Industry\ContractSales\ContractSalesReader;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\FacilityTaxResolver;
use App\Service\Industry\InventionService;
use App\Service\Industry\JobInstallCostEstimator;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Service\Industry\Double\QuotingJitaMarketService;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryBpcPriceRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivitySkillRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(BatchProfitScannerService::class)]
#[AllowMockObjectsWithoutExpectations]
class BatchProfitScannerServiceTest extends TestCase
{
    use ResolvesTypeNamesFromSde;
    use CreatesUserWithId;

    private const array SCAN_SDE_TYPE_NAMES = [
        34 => 'Tritanium',
        35 => 'Pyerite',
        101 => 'Reaction Product',
        200 => 'Raw Material',
        585 => 'Slasher',
        587 => 'Rifter',
        598 => 'Breacher',
        2001 => 'Scourge Heavy Missile',
        11393 => 'Jaguar',
        11399 => 'Morphite',
    ];

    private IndustryActivityProductRepository&MockObject $productRepository;
    private IndustryActivityMaterialRepository&MockObject $materialRepository;
    private JitaMarketService&MockObject $jitaMarketService;
    private StructureMarketService&MockObject $structureMarketService;
    private EsiCostIndexService&MockObject $esiCostIndexService;
    private TypeNameResolver $typeNameResolver;
    private EntityManagerInterface&MockObject $entityManager;
    private Connection&MockObject $connection;
    private CachedStructureRepository&MockObject $cachedStructureRepository;
    private MapSolarSystemRepository&MockObject $solarSystemRepository;
    private InventionService&MockObject $inventionService;
    private IndustryBpcPriceRepository&MockObject $bpcPriceRepository;
    private IndustryActivitySkillRepository&MockObject $activitySkillRepository;
    private CachedCharacterSkillRepository&MockObject $characterSkillRepository;
    private BatchProfitScannerService $service;

    private const SOLAR_SYSTEM_ID = 30002510;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->materialRepository = $this->createMock(IndustryActivityMaterialRepository::class);
        $this->jitaMarketService = $this->createMock(QuotingJitaMarketService::class);
        $this->structureMarketService = $this->createMock(StructureMarketService::class);
        $this->esiCostIndexService = $this->createMock(EsiCostIndexService::class);
        $this->typeNameResolver = new TypeNameResolver($this->sdeTypeRepositoryKnowing(self::SCAN_SDE_TYPE_NAMES));
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->cachedStructureRepository = $this->createMock(CachedStructureRepository::class);
        $this->solarSystemRepository = $this->createMock(MapSolarSystemRepository::class);
        $this->inventionService = $this->createMock(InventionService::class);
        $this->bpcPriceRepository = $this->createMock(IndustryBpcPriceRepository::class);
        $this->activitySkillRepository = $this->createMock(IndustryActivitySkillRepository::class);
        $this->characterSkillRepository = $this->createMock(CachedCharacterSkillRepository::class);

        $this->entityManager->method('getConnection')->willReturn($this->connection);

        // Default: no T2 products, no faction detection, no BPC prices, no skills
        $this->inventionService->method('identifyT2Products')->willReturn([]);
        $this->productRepository->method('findFactionBlueprintIds')->willReturn([]);
        $this->bpcPriceRepository->method('findByUserIndexed')->willReturn([]);
        $this->activitySkillRepository->method('findSkillsForBlueprints')->willReturn([]);

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
            $this->bpcPriceRepository,
            $this->activitySkillRepository,
            $this->characterSkillRepository,
            new JobInstallCostEstimator(),
            new FacilityTaxResolver(new InMemoryUserProductionStructures(), InMemorySystemCostIndices::none()),
            $this->createStub(ContractSalesReader::class),
        );
    }

    public function testScanReturnsEmptyWhenNoProducts(): void
    {
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([]);

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.036, 0.036), 0.0, null, self::SOLAR_SYSTEM_ID);

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
        $this->givenJitaSellPrices([
            34 => 5.0,
            35 => 12.0,
            587 => 2500000.0,
        ]);

        // Daily volumes
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            587 => 15.0,
        ]);

        // Type names

        // Job install cost: EIV from pre-loaded adjusted prices, cost index pre-loaded per system
        // EIV = 1850000, costIndex = 0.01 => jobInstallCost = 1850000 * 0.01 = 18500
        $this->esiCostIndexService->method('getAdjustedPrices')->willReturn([34 => 5.0, 35 => 12.0]);
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.01);

        // exportCostPerM3 = 0 so importCost = 0
        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.036, 0.036), 0.0, null, self::SOLAR_SYSTEM_ID);

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
        $this->assertSame(557875.0, $result[0]['profitPerUnit']);
        $this->assertSame(31.66, $result[0]['marginPercent']);
        $this->assertSame(8368125.0, $result[0]['iskPerDay']);
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
        $this->givenJitaSellPrices([
            34 => 10.0,
            587 => 5000000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 5.0]);
        $this->givenZeroEstimatedItemValue();

        // Filter: min margin 10%
        $result = $this->service->scan('all', 10.0, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.036, 0.036), 0.0, null, self::SOLAR_SYSTEM_ID);

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

        $this->givenJitaSellPrices([34 => 5.0, 587 => 100000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 2.0]);
        $this->givenZeroEstimatedItemValue();

        // Filter: minDailyVolume = 5
        $result = $this->service->scan('all', null, 5.0, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.036, 0.036), 0.0, null, self::SOLAR_SYSTEM_ID);

        // Volume is 2.0, below 5.0 threshold
        $this->assertCount(0, $result);
    }

    #[Group('issue-135')]
    public function testScanPricesJitaThroughTheOnDemandFallbackAndNeverFromTheCacheAlone(): void
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

        $this->jitaMarketService->expects($this->never())->method('getPrices');
        $this->jitaMarketService->expects($this->never())->method('getWeightedSellPrices');
        $this->jitaMarketService->expects($this->atLeastOnce())->method('getWeightedSellQuotesWithFallback')->willReturnCallback(QuotingJitaMarketService::completeQuotesAt([34 => 5.0, 587 => 100000.0]));
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 2.0]);
        $this->givenZeroEstimatedItemValue();

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.036, 0.036), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertCount(1, $result);
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

        $this->givenJitaSellPrices([200 => 10.0, 101 => 50000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([101 => 100.0]);
        $this->givenZeroEstimatedItemValue();

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

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
        $this->givenJitaSellPrices([34 => 5.0, 587 => 5000000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0]);
        $this->givenZeroEstimatedItemValue();

        $exportCostPerM3 = 10.0;
        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), $exportCostPerM3, null, self::SOLAR_SYSTEM_ID);

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

        $this->givenJitaSellPrices([34 => 5.0, 587 => 5000000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0]);
        $this->givenZeroEstimatedItemValue();

        $exportCostPerM3 = 1200.0;
        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), $exportCostPerM3, null, self::SOLAR_SYSTEM_ID);

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

        $this->givenJitaSellPrices([
            34 => 5.0,
            35 => 12.0,
            587 => 50000000.0, // High sell price to ensure positive margin
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0]);
        $this->givenZeroEstimatedItemValue();

        $exportCostPerM3 = 10.0;
        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), $exportCostPerM3, null, self::SOLAR_SYSTEM_ID);

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
        $this->givenJitaSellPrices([34 => 5.0, 587 => 100000.0]);

        // Structure sell price is different (higher) than Jita
        $this->structureMarketService->method('getLowestSellPrices')->willReturn([587 => 3000000.0]);

        // Resolve structure region for daily volumes
        $structure = $this->createMock(\App\Entity\CachedStructure::class);
        $structure->method('getSolarSystemId')->willReturn(30004759);
        $this->cachedStructureRepository->method('findByStructureId')->willReturn($structure);

        $solarSystem = $this->createMock(\App\Entity\Sde\MapSolarSystem::class);
        $solarSystem->method('getRegionId')->willReturn(10000060);
        $this->solarSystemRepository->method('find')->willReturn($solarSystem);

        $this->jitaMarketService->method('getCachedDailyVolumesForRegion')->willReturn([587 => 20.0]);
        $this->givenZeroEstimatedItemValue();

        $result = $this->service->scan('all', null, null, 'structure', $structureId, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertCount(1, $result);
        // Sell price should be from structure (3M), not Jita (100K)
        $this->assertSame(3000000.0, $result[0]['sellPrice']);
        // No export cost when selling at a structure
        $this->assertSame(0.0, $result[0]['exportCost']);
    }

    #[Group('issue-419')]
    public function testStructureVenueReadsTheRegionalVolumesFromTheCacheOnlyAndKeepsTheKnownOnes(): void
    {
        $this->givenOneFrigateScannedForAStructureInRegion(10000060);
        $this->jitaMarketService->expects($this->once())->method('getCachedDailyVolumesForRegion')
            ->with(10000060, [587])
            ->willReturn([587 => 20.0]);

        $result = $this->service->scan('all', null, 5.0, 'structure', 1035466617946, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertCount(1, $result);
        $this->assertSame(20.0, $result[0]['dailyVolume']);
    }

    #[Group('issue-419')]
    public function testStructureVenueWithAnEmptyVolumeCacheLeavesTheVolumeUnknownAndMakesNoEsiCall(): void
    {
        $this->givenOneFrigateScannedForAStructureInRegion(10000060);
        $this->jitaMarketService->method('getCachedDailyVolumesForRegion')->willReturn([]);

        $result = $this->service->scan('all', null, null, 'structure', 1035466617946, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['dailyVolume']);
        $this->assertNull($result[0]['iskPerDay']);
        $this->assertSame('missing_daily_volume', $result[0]['unknownReason']);
    }

    #[Group('issue-419')]
    public function testStructureVenueExcludesTheTypesWithoutVolumeWhenAMinimumDailyVolumeIsSet(): void
    {
        $this->givenOneFrigateScannedForAStructureInRegion(10000060);
        $this->jitaMarketService->method('getCachedDailyVolumesForRegion')->willReturn([]);

        $result = $this->service->scan('all', null, 5.0, 'structure', 1035466617946, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertSame([], $result);
    }

    #[Group('issue-419')]
    public function testStructureVenueWithAnUnknownRegionLeavesTheVolumeUnknownInsteadOfUsingJitaVolumes(): void
    {
        $this->givenOneFrigateScannedForAStructureInRegion(null);
        $this->jitaMarketService->expects($this->never())->method('getCachedDailyVolumes');
        $this->jitaMarketService->expects($this->never())->method('getCachedDailyVolumesForRegion');

        $result = $this->service->scan('all', null, null, 'structure', 1035466617946, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['dailyVolume']);
    }

    private function givenOneFrigateScannedForAStructureInRegion(?int $regionId): void
    {
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
        $this->givenJitaSellPrices([34 => 5.0, 587 => 100000.0]);
        $this->structureMarketService->method('getLowestSellPrices')->willReturn([587 => 3000000.0]);

        $structure = $this->createMock(\App\Entity\CachedStructure::class);
        $structure->method('getSolarSystemId')->willReturn(30004759);
        $this->cachedStructureRepository->method('findByStructureId')->willReturn($structure);
        $solarSystem = null;
        if ($regionId !== null) {
            $solarSystem = $this->createMock(\App\Entity\Sde\MapSolarSystem::class);
            $solarSystem->method('getRegionId')->willReturn($regionId);
        }
        $this->solarSystemRepository->method('find')->willReturn($solarSystem);
        $this->givenZeroEstimatedItemValue();
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
        $this->givenJitaSellPrices([34 => 5.0, 2001 => 500.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([2001 => 5000.0]);
        $this->givenZeroEstimatedItemValue();

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

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

        $this->givenJitaSellPrices([587 => 2500000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0]);
        $this->givenZeroEstimatedItemValue();

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

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
        $this->inventionService->method('expectedCostPerRun')->willReturn(['cost' => 187068.0, 'unknownReason' => null]);

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
            $this->bpcPriceRepository,
            $this->activitySkillRepository,
            $this->characterSkillRepository,
            new JobInstallCostEstimator(),
            new FacilityTaxResolver(new InMemoryUserProductionStructures(), InMemorySystemCostIndices::none()),
            $this->createStub(ContractSalesReader::class),
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

        $this->givenJitaSellPrices([
            34 => 5.0,
            587 => 5000000.0,
            11393 => 80000000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0, 11393 => 5.0]);
        $this->givenZeroEstimatedItemValue();

        // Filter: t2_ships only (categoryId=6, onlyT2=true)
        $result = $this->service->scan('t2_ships', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        // Only Jaguar (T2) should remain, Rifter (T1) filtered out
        $this->assertCount(1, $result);
        $this->assertSame(11393, $result[0]['typeId']);
        $this->assertSame('Jaguar', $result[0]['typeName']);
        $this->assertSame('T2 Ships', $result[0]['categoryLabel']);
        $this->assertSame(2, $result[0]['meUsed']); // T2 = ME 2 (Attainment decryptor default)
    }

    #[Group('issue-124')]
    public function testScanAddsTheExpectedInventionCostPerRunToTheCostOfAT2Product(): void
    {
        $this->givenAScannedJaguarAtAnInventionCost(['cost' => 187068.0, 'unknownReason' => null]);

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertCount(1, $result);
        $this->assertSame(211952.0, $result[0]['profitPerUnit']);
        $this->assertSame(112.71, $result[0]['marginPercent']);
        $this->assertNull($result[0]['unknownReason']);
    }

    #[Group('issue-124')]
    public function testScanAsksTheInventionCostOfTheManufacturingSystemOnlyForAT2Product(): void
    {
        $this->givenAScannedJaguarAtAnInventionCost(['cost' => 187068.0, 'unknownReason' => null]);
        $this->inventionService->expects($this->once())->method('expectedCostPerRun')->with(11393, self::SOLAR_SYSTEM_ID, 0.25);

        $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);
    }

    #[Group('issue-124')]
    public function testScanKeepsTheMarginUnknownWhenTheInventionCostIsUnknown(): void
    {
        $this->givenAScannedJaguarAtAnInventionCost(['cost' => null, 'unknownReason' => 'missing_invention_probability']);

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['marginPercent']);
        $this->assertNull($result[0]['profitPerUnit']);
        $this->assertNull($result[0]['iskPerDay']);
        $this->assertSame('missing_invention_probability', $result[0]['unknownReason']);
    }

    #[Group('issue-124')]
    public function testScanNeverAsksTheInventionCostOfAT1Product(): void
    {
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 586, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],
        ]);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 587, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 28100.0]]
                : [['type_id' => 34, 'volume' => 0.01]]
        );
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([586 => [['materialTypeId' => 34, 'quantity' => 100]]]);
        $this->givenJitaSellPrices([34 => 5.0, 587 => 5000000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0]);
        $this->givenZeroEstimatedItemValue();
        $this->inventionService->expects($this->never())->method('expectedCostPerRun');

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertCount(1, $result);
        $this->assertSame(4999550.0, $result[0]['profitPerUnit']);
    }

    /**
     * @param array{cost: ?float, unknownReason: ?string} $inventionCost
     */
    private function givenAScannedJaguarAtAnInventionCost(array $inventionCost): void
    {
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 11400, 'productTypeId' => 11393, 'outputPerRun' => 1, 'activityId' => 1],
        ]);
        $this->inventionService = $this->createMock(InventionService::class);
        $this->inventionService->method('identifyT2Products')->willReturn([11393 => true]);
        $this->inventionService->method('expectedCostPerRun')->willReturn($inventionCost);
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
            $this->bpcPriceRepository,
            $this->activitySkillRepository,
            $this->characterSkillRepository,
            new JobInstallCostEstimator(),
            new FacilityTaxResolver(new InMemoryUserProductionStructures(), InMemorySystemCostIndices::none()),
            $this->createStub(ContractSalesReader::class),
        );
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 11393, 'group_id' => 324, 'group_name' => 'Assault Frigate', 'category_id' => 6, 'volume' => 28600.0]]
                : [['type_id' => 34, 'volume' => 0.01]]
        );
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([11400 => [['materialTypeId' => 34, 'quantity' => 200]]]);
        $this->givenJitaSellPrices([34 => 5.0, 11393 => 400000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([11393 => 5.0]);
        $this->givenZeroEstimatedItemValue();
    }

    public function testScanIncludesFactionBlueprintFlag(): void
    {
        $this->productRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 586, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],
        ]);
        // Mark blueprint 586 as faction
        $this->productRepository->method('findFactionBlueprintIds')->willReturn([586 => true]);

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
            $this->bpcPriceRepository,
            $this->activitySkillRepository,
            $this->characterSkillRepository,
            new JobInstallCostEstimator(),
            new FacilityTaxResolver(new InMemoryUserProductionStructures(), InMemorySystemCostIndices::none()),
            $this->createStub(ContractSalesReader::class),
        );

        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 587, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 28100.0]]
                : [['type_id' => 34, 'volume' => 0.01]]
        );

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [['materialTypeId' => 34, 'quantity' => 100]],
        ]);

        $this->givenJitaSellPrices([34 => 5.0, 587 => 5000000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0]);
        $this->givenZeroEstimatedItemValue();

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['isFactionBlueprint']);
        $this->assertNull($result[0]['bpcCostPerRun']);
    }

    public function testScanIncludesBpcCostInTotalCost(): void
    {
        $user = $this->userWithId();

        // Setup BPC price mock to return a price for blueprint 586
        $this->bpcPriceRepository = $this->createMock(IndustryBpcPriceRepository::class);
        $this->bpcPriceRepository->method('findByUserIndexed')->willReturn([586 => 500000.0]);

        $this->productRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 586, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],
        ]);
        $this->productRepository->method('findFactionBlueprintIds')->willReturn([586 => true]);

        $this->activitySkillRepository = $this->createMock(IndustryActivitySkillRepository::class);
        $this->activitySkillRepository->method('findSkillsForBlueprints')->willReturn([]);

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
            $this->bpcPriceRepository,
            $this->activitySkillRepository,
            $this->characterSkillRepository,
            new JobInstallCostEstimator(),
            new FacilityTaxResolver(new InMemoryUserProductionStructures(), InMemorySystemCostIndices::none()),
            $this->createStub(ContractSalesReader::class),
        );

        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 587, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 28100.0]]
                : [['type_id' => 34, 'volume' => 0.01]]
        );

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [['materialTypeId' => 34, 'quantity' => 100]],
        ]);

        $this->givenJitaSellPrices([34 => 5.0, 587 => 5000000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0]);
        $this->givenZeroEstimatedItemValue();

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, $user, self::SOLAR_SYSTEM_ID);

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['isFactionBlueprint']);
        $this->assertSame(500000.0, $result[0]['bpcCostPerRun']);

        // Material cost with ME10: ceil(100 * 0.9) = 90 * 5.0 = 450.0
        // BPC cost: 500000.0
        // Total cost = 450 + 500000 = 500450
        // Net sell = 5000000 (no broker/tax), profit = 5000000 - 500450 = 4499550
        $this->assertSame(450.0, $result[0]['materialCost']);
        $this->assertSame(4499550.0, $result[0]['profitPerUnit']);
    }

    public function testScanNonFactionBlueprintHasFalseFactionFlag(): void
    {
        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id')
                ? [['type_id' => 587, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 28100.0]]
                : [['type_id' => 34, 'volume' => 0.01]]
        );

        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => 586, 'productTypeId' => 587, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [['materialTypeId' => 34, 'quantity' => 100]],
        ]);

        $this->givenJitaSellPrices([34 => 5.0, 587 => 5000000.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([587 => 10.0]);
        $this->givenZeroEstimatedItemValue();

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['isFactionBlueprint']);
        $this->assertNull($result[0]['bpcCostPerRun']);
    }

    // ---------------------------------------------------------------------
    // Issue #82: a material without a Jita price makes the product cost unknown
    // (glossary "Coût inconnu", engine spec R10 / decision D5), never free.
    // ---------------------------------------------------------------------

    private const int RIFTER_BLUEPRINT_ID = 586;
    private const int RIFTER_TYPE_ID = 587;
    private const int SLASHER_BLUEPRINT_ID = 584;
    private const int SLASHER_TYPE_ID = 585;
    private const int BREACHER_BLUEPRINT_ID = 597;
    private const int BREACHER_TYPE_ID = 598;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int MORPHITE_TYPE_ID = 11399;
    private const string UNKNOWN_REASON_MISSING_MATERIAL_PRICE = 'missing_material_price';

    public function testProductWithUnpricedMaterialHasUnknownCostAndMargins(): void
    {
        // Slasher: 1000 Tritanium (priced) + 10 Morphite (no Jita price)
        $this->givenFrigates([
            self::SLASHER_BLUEPRINT_ID => [self::SLASHER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 1000, self::MORPHITE_TYPE_ID => 10]],
        ]);
        $this->givenJitaSellPrices([
            self::TRITANIUM_TYPE_ID => 5.0,
            self::MORPHITE_TYPE_ID => null, // JitaMarketService::getPrices() returns null for a type without sell order
            self::SLASHER_TYPE_ID => 5000000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::SLASHER_TYPE_ID => 50.0]);

        $exportCostPerM3 = 1.0;
        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), $exportCostPerM3, null, self::SOLAR_SYSTEM_ID);

        $this->assertCount(1, $result);
        $slasher = $result[0];
        $this->assertSame(self::SLASHER_TYPE_ID, $slasher['typeId']);

        // A sum containing an unknown amount is unknown: no material cost, no margin, no profit
        $this->assertNull($slasher['materialCost']);
        $this->assertNull($slasher['marginPercent']);
        $this->assertNull($slasher['profitPerUnit']);
        $this->assertNull($slasher['iskPerDay']);
        $this->assertSame(self::UNKNOWN_REASON_MISSING_MATERIAL_PRICE, $slasher['unknownReason']);
        $this->assertSame([self::MORPHITE_TYPE_ID], $slasher['missingPriceTypeIds']);

        // Amounts that do not depend on the missing price stay known
        // ME10: ceil(1000 * 0.9) = 900 Tritanium + ceil(10 * 0.9) = 9 Morphite, 0.01 m3 each => 9.09 m3 * 1.0
        $this->assertSame(9.09, $slasher['importCost']);
        // Frigate packaged volume 2500 m3 * 1.0
        $this->assertSame(2500.0, $slasher['exportCost']);
        $this->assertSame(5000000.0, $slasher['sellPrice']);
        $this->assertSame(50.0, $slasher['dailyVolume']);
    }

    public function testProductWhoseOnlyMaterialIsUnpricedIsReportedAsUnknownInsteadOfDropped(): void
    {
        // Today the cost sums to 0 and the "totalCost <= 0" guard drops the product without a word
        $this->givenFrigates([
            self::SLASHER_BLUEPRINT_ID => [self::SLASHER_TYPE_ID, [self::MORPHITE_TYPE_ID => 10]],
        ]);
        $this->givenJitaSellPrices([
            self::MORPHITE_TYPE_ID => null,
            self::SLASHER_TYPE_ID => 5000000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::SLASHER_TYPE_ID => 50.0]);

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertCount(1, $result);
        $this->assertSame(self::SLASHER_TYPE_ID, $result[0]['typeId']);
        $this->assertNull($result[0]['materialCost']);
        $this->assertNull($result[0]['marginPercent']);
        $this->assertSame(self::UNKNOWN_REASON_MISSING_MATERIAL_PRICE, $result[0]['unknownReason']);
        $this->assertSame([self::MORPHITE_TYPE_ID], $result[0]['missingPriceTypeIds']);
    }

    public function testMinMarginFilterExcludesProductWithUnknownCost(): void
    {
        $this->givenRifterFullyPricedAndSlasherWithUnpricedMorphite();

        $result = $this->service->scan('all', 10.0, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        // An unknown margin never satisfies a minimum margin: only the fully priced Rifter remains
        $this->assertSame([self::RIFTER_TYPE_ID], array_column($result, 'typeId'));
    }

    public function testProductWithUnknownCostIsRankedAfterEveryPricedProduct(): void
    {
        // Rifter: profitable. Breacher: loss-making. Slasher: unknown cost (would look free today).
        $this->givenFrigates([
            self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 100, self::PYERITE_TYPE_ID => 10]],
            self::BREACHER_BLUEPRINT_ID => [self::BREACHER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 1000]],
            self::SLASHER_BLUEPRINT_ID => [self::SLASHER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 100, self::MORPHITE_TYPE_ID => 10]],
        ]);
        $this->givenJitaSellPrices([
            self::TRITANIUM_TYPE_ID => 5.0,
            self::PYERITE_TYPE_ID => 10.0,
            self::MORPHITE_TYPE_ID => null,
            self::RIFTER_TYPE_ID => 1000.0,
            self::BREACHER_TYPE_ID => 1000.0,
            self::SLASHER_TYPE_ID => 2000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::RIFTER_TYPE_ID => 10.0,
            self::BREACHER_TYPE_ID => 10.0,
            self::SLASHER_TYPE_ID => 50.0,
        ]);

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        // Rifter iskPerDay = 4600, Breacher iskPerDay = (1000 - 900 * 5) * 10 = -35000, Slasher unknown: last
        $this->assertSame(
            [self::RIFTER_TYPE_ID, self::BREACHER_TYPE_ID, self::SLASHER_TYPE_ID],
            array_column($result, 'typeId'),
        );
    }

    public function testFullyPricedProductReportsNoUnknownCost(): void
    {
        $this->givenRifterFullyPricedAndSlasherWithUnpricedMorphite();

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);
        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $result);

        $this->assertArrayHasKey('unknownReason', $rifter);
        $this->assertNull($rifter['unknownReason']);
        $this->assertArrayHasKey('missingPriceTypeIds', $rifter);
        $this->assertSame([], $rifter['missingPriceTypeIds']);
    }

    public function testFullyPricedProductKeepsItsCostAndMarginsWhenAnotherProductLacksAPrice(): void
    {
        // Guard: the fix must not change a product whose materials are all priced
        $this->givenRifterFullyPricedAndSlasherWithUnpricedMorphite();

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);
        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $result);

        // ME10: ceil(100 * 0.9) = 90 Tritanium * 5 + ceil(10 * 0.9) = 9 Pyerite * 10 = 540
        // No fees, no job cost, no transport: profit = 1000 - 540 = 460, margin = 460 / 540 = 85.19 %
        $this->assertSame(540.0, $rifter['materialCost']);
        $this->assertSame(460.0, $rifter['profitPerUnit']);
        $this->assertSame(85.19, $rifter['marginPercent']);
        $this->assertSame(4600.0, $rifter['iskPerDay']);
        $this->assertSame(1000.0, $rifter['sellPrice']);
    }

    public function testFullyPricedProductPassesTheMinMarginFilter(): void
    {
        // Guard: the minimum margin filter still keeps a priced product above the threshold
        $this->givenRifterFullyPricedAndSlasherWithUnpricedMorphite();

        $result = $this->service->scan('all', 85.0, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertContains(self::RIFTER_TYPE_ID, array_column($result, 'typeId'));
        $this->assertSame(85.19, $this->rowOf(self::RIFTER_TYPE_ID, $result)['marginPercent']);
    }

    private const string UNKNOWN_REASON_MISSING_DAILY_VOLUME = 'missing_daily_volume';

    #[Group('issue-420')]
    public function testProductWithoutCachedDailyVolumeHasUnknownVolumeAndIskPerDayNextToItsKnownMargins(): void
    {
        $this->givenRifterWithKnownVolumeAndSlasherWithoutCachedVolume();

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);
        $slasher = $this->rowOf(self::SLASHER_TYPE_ID, $result);

        $this->assertNull($slasher['dailyVolume']);
        $this->assertNull($slasher['iskPerDay']);
        $this->assertSame(self::UNKNOWN_REASON_MISSING_DAILY_VOLUME, $slasher['unknownReason']);
        $this->assertSame(1460.0, $slasher['profitPerUnit']);
        $this->assertSame(270.37, $slasher['marginPercent']);
    }

    #[Group('issue-420')]
    public function testMinDailyVolumeFilterExcludesProductWithUnknownDailyVolume(): void
    {
        $this->givenRifterWithKnownVolumeAndSlasherWithoutCachedVolume();

        $result = $this->service->scan('all', null, 5.0, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertSame([self::RIFTER_TYPE_ID], array_column($result, 'typeId'));
    }

    #[Group('issue-420')]
    public function testProductWithoutCachedDailyVolumeStaysListedWhenNoMinDailyVolumeIsAsked(): void
    {
        $this->givenRifterWithKnownVolumeAndSlasherWithoutCachedVolume();

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertEqualsCanonicalizing([self::RIFTER_TYPE_ID, self::SLASHER_TYPE_ID], array_column($result, 'typeId'));
    }

    #[Group('issue-420')]
    public function testProductWithAKnownZeroDailyVolumeKeepsItAsZeroWithoutUnknownReason(): void
    {
        $this->givenFrigates([
            self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 100, self::PYERITE_TYPE_ID => 10]],
        ]);
        $this->givenJitaSellPrices([
            self::TRITANIUM_TYPE_ID => 5.0,
            self::PYERITE_TYPE_ID => 10.0,
            self::RIFTER_TYPE_ID => 1000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 0.0]);

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);
        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $result);

        $this->assertSame(0.0, $rifter['dailyVolume']);
        $this->assertSame(0.0, $rifter['iskPerDay']);
        $this->assertNull($rifter['unknownReason']);
    }

    private function givenRifterWithKnownVolumeAndSlasherWithoutCachedVolume(): void
    {
        $this->givenFrigates([
            self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 100, self::PYERITE_TYPE_ID => 10]],
            self::SLASHER_BLUEPRINT_ID => [self::SLASHER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 100, self::PYERITE_TYPE_ID => 10]],
        ]);
        $this->givenJitaSellPrices([
            self::TRITANIUM_TYPE_ID => 5.0,
            self::PYERITE_TYPE_ID => 10.0,
            self::RIFTER_TYPE_ID => 1000.0,
            self::SLASHER_TYPE_ID => 2000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 10.0]);
    }

    // ---------------------------------------------------------------------
    // Issue #22: a product outside the Jita sync has no sale price; it stays in the
    // scan as unknown instead of disappearing from the ranking.
    // ---------------------------------------------------------------------

    private const string UNKNOWN_REASON_MISSING_SALE_PRICE = 'missing_sale_price';

    public function testProductWithoutJitaSalePriceIsReportedAsUnknownInsteadOfDropped(): void
    {
        $this->givenFrigates([
            self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 100, self::PYERITE_TYPE_ID => 10]],
        ]);
        $this->givenJitaSellPrices([
            self::TRITANIUM_TYPE_ID => 5.0,
            self::PYERITE_TYPE_ID => 10.0,
            self::RIFTER_TYPE_ID => null, // product outside the background Jita sync
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 10.0]);

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertCount(1, $result);
        $rifter = $result[0];
        $this->assertSame(self::RIFTER_TYPE_ID, $rifter['typeId']);

        // No sale price, so no profit and no margin, never a plausible 0
        $this->assertNull($rifter['sellPrice']);
        $this->assertNull($rifter['profitPerUnit']);
        $this->assertNull($rifter['marginPercent']);
        $this->assertNull($rifter['iskPerDay']);
        $this->assertSame(self::UNKNOWN_REASON_MISSING_SALE_PRICE, $rifter['unknownReason']);

        // The material cost does not depend on the sale price and stays known:
        // ME10: ceil(100 * 0.9) = 90 Tritanium * 5 + ceil(10 * 0.9) = 9 Pyerite * 10 = 540
        $this->assertSame(540.0, $rifter['materialCost']);
        $this->assertSame([], $rifter['missingPriceTypeIds']);
        $this->assertSame(10.0, $rifter['dailyVolume']);
    }

    public function testProductWithoutSalePriceIsRankedAfterEveryPricedProduct(): void
    {
        // Rifter: profitable. Breacher: loss-making. Slasher: no Jita sale price.
        $this->givenFrigates([
            self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 100, self::PYERITE_TYPE_ID => 10]],
            self::BREACHER_BLUEPRINT_ID => [self::BREACHER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 1000]],
            self::SLASHER_BLUEPRINT_ID => [self::SLASHER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 100]],
        ]);
        $this->givenJitaSellPrices([
            self::TRITANIUM_TYPE_ID => 5.0,
            self::PYERITE_TYPE_ID => 10.0,
            self::RIFTER_TYPE_ID => 1000.0,
            self::BREACHER_TYPE_ID => 1000.0,
            self::SLASHER_TYPE_ID => null,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::RIFTER_TYPE_ID => 10.0,
            self::BREACHER_TYPE_ID => 10.0,
            self::SLASHER_TYPE_ID => 50.0,
        ]);

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        // Rifter iskPerDay = 4600, Breacher iskPerDay = (1000 - 900 * 5) * 10 = -35000, Slasher unknown: last
        $this->assertSame(
            [self::RIFTER_TYPE_ID, self::BREACHER_TYPE_ID, self::SLASHER_TYPE_ID],
            array_column($result, 'typeId'),
        );
    }

    public function testMinMarginFilterExcludesProductWithoutSalePrice(): void
    {
        $this->givenFrigates([
            self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 100, self::PYERITE_TYPE_ID => 10]],
            self::SLASHER_BLUEPRINT_ID => [self::SLASHER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 100]],
        ]);
        $this->givenJitaSellPrices([
            self::TRITANIUM_TYPE_ID => 5.0,
            self::PYERITE_TYPE_ID => 10.0,
            self::RIFTER_TYPE_ID => 1000.0,
            self::SLASHER_TYPE_ID => null,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::RIFTER_TYPE_ID => 10.0,
            self::SLASHER_TYPE_ID => 50.0,
        ]);

        $withoutFilter = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);
        $withMinMargin = $this->service->scan('all', 10.0, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        // Without filter the unpriced Slasher is listed; an unknown margin never satisfies a minimum margin
        $this->assertSame([self::RIFTER_TYPE_ID, self::SLASHER_TYPE_ID], array_column($withoutFilter, 'typeId'));
        $this->assertSame([self::RIFTER_TYPE_ID], array_column($withMinMargin, 'typeId'));
    }

    private const int ROBOTICS_BLUEPRINT_TYPE_ID = 9849;

    #[Group('issue-115')]
    public function testScannedProductsKnownToTheSdeKeepTheirSdeNames(): void
    {
        $this->givenRifterFullyPricedAndSlasherWithUnpricedMorphite();

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertSame(['Rifter', 'Slasher'], array_column($result, 'typeName'));
    }

    #[Group('issue-115')]
    public function testScannedProductMissingFromTheSdeHasAnUnknownNameAndStaysListedWithUnchangedProfit(): void
    {
        $this->givenFrigates([
            self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 100, self::PYERITE_TYPE_ID => 10]],
            self::ROBOTICS_BLUEPRINT_TYPE_ID => [self::ROBOTICS_TYPE_ID, [self::TRITANIUM_TYPE_ID => 300, self::PYERITE_TYPE_ID => 40]],
        ]);
        $this->givenJitaSellPrices([
            self::TRITANIUM_TYPE_ID => 5.0,
            self::PYERITE_TYPE_ID => 10.0,
            self::RIFTER_TYPE_ID => 1000.0,
            self::ROBOTICS_TYPE_ID => 3000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::RIFTER_TYPE_ID => 10.0,
            self::ROBOTICS_TYPE_ID => 7.0,
        ]);

        $result = $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertSame([self::ROBOTICS_TYPE_ID, self::RIFTER_TYPE_ID], array_column($result, 'typeId'));
        $this->assertSame([null, 'Rifter'], array_column($result, 'typeName'));
        $robotics = $this->rowOf(self::ROBOTICS_TYPE_ID, $result);
        $this->assertSame(1710.0, $robotics['materialCost']);
        $this->assertSame(1290.0, $robotics['profitPerUnit']);
        $this->assertSame(75.44, $robotics['marginPercent']);
        $this->assertSame(9030.0, $robotics['iskPerDay']);
        $this->assertSame(3000.0, $robotics['sellPrice']);
    }

    private function givenRifterFullyPricedAndSlasherWithUnpricedMorphite(): void
    {
        $this->givenFrigates([
            self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 100, self::PYERITE_TYPE_ID => 10]],
            self::SLASHER_BLUEPRINT_ID => [self::SLASHER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 100, self::MORPHITE_TYPE_ID => 10]],
        ]);
        $this->givenJitaSellPrices([
            self::TRITANIUM_TYPE_ID => 5.0,
            self::PYERITE_TYPE_ID => 10.0,
            self::MORPHITE_TYPE_ID => null,
            self::RIFTER_TYPE_ID => 1000.0,
            self::SLASHER_TYPE_ID => 2000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::RIFTER_TYPE_ID => 10.0,
            self::SLASHER_TYPE_ID => 50.0,
        ]);
    }

    /**
     * T1 frigates (group 25), manufacturing, 1 unit per run; every material is 0.01 m3.
     *
     * @param array<int, array{0: int, 1: array<int, int>, 2?: int}> $frigatesByBlueprint blueprintTypeId => [productTypeId, [materialTypeId => base quantity], output per run]
     */
    private function givenFrigates(array $frigatesByBlueprint): void
    {
        $products = [];
        $materialsByBlueprint = [];
        $productMetadata = [];
        $materialVolumes = [];
        foreach ($frigatesByBlueprint as $blueprintTypeId => $frigate) {
            [$productTypeId, $baseQuantities] = $frigate;
            $products[] = ['blueprintTypeId' => $blueprintTypeId, 'productTypeId' => $productTypeId, 'outputPerRun' => $frigate[2] ?? 1, 'activityId' => 1];
            $productMetadata[] = ['type_id' => $productTypeId, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 27289.0];
            foreach ($baseQuantities as $materialTypeId => $quantity) {
                $materialsByBlueprint[$blueprintTypeId][] = ['materialTypeId' => $materialTypeId, 'quantity' => $quantity];
                $materialVolumes[$materialTypeId] = ['type_id' => $materialTypeId, 'volume' => 0.01];
            }
        }

        $this->productRepository->method('findAllManufacturableProducts')->willReturn($products);
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn($materialsByBlueprint);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql): array => str_contains($sql, 'group_id') ? $productMetadata : array_values($materialVolumes)
        );
        $this->givenZeroEstimatedItemValue();
    }

    private function givenJitaSellPrices(array $unitPrices): void
    {
        $this->jitaMarketService->method('getWeightedSellQuotesWithFallback')->willReturnCallback(QuotingJitaMarketService::completeQuotesAt($unitPrices));
    }

    private function givenJitaSellBooks(array $sellBooks): void
    {
        $this->jitaMarketService->method('getWeightedSellQuotesWithFallback')->willReturnCallback(QuotingJitaMarketService::weighedFrom($sellBooks));
    }

    private function givenZeroEstimatedItemValue(): void
    {
        $this->esiCostIndexService->method('getAdjustedPrices')->willReturnCallback(
            static fn (array $typeIds): array => array_fill_keys($typeIds, 0.0),
        );
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.0);
    }

    /**
     * @param list<array<string, mixed>> $result
     * @return array<string, mixed>
     */
    private function rowOf(int $typeId, array $result): array
    {
        foreach ($result as $row) {
            if ($row['typeId'] === $typeId) {
                return $row;
            }
        }

        $this->fail("Type {$typeId} is missing from the scan result");
    }

    private const int JAGUAR_BLUEPRINT_ID = 11400;
    private const int JAGUAR_TYPE_ID = 11393;
    private const int REACTION_FORMULA_ID = 100;
    private const int REACTION_PRODUCT_TYPE_ID = 101;
    private const int MEXALLON_TYPE_ID = 36;
    private const int NOCXIUM_TYPE_ID = 38;
    private const int RAW_MATERIAL_TYPE_ID = 200;
    private const int SECOND_RAW_MATERIAL_TYPE_ID = 201;
    private const float EXPORT_COST_PER_M3 = 1.0;

    public function testMaterialQuantitiesOfAT1ProductApplyMe10RoundedUpPerMaterial(): void
    {
        $result = $this->scanT1T2AndReactionProducts();

        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $result);
        $this->assertSame(10, $rifter['meUsed']);
        $this->assertSame(42527.0, $rifter['materialCost']);
        $this->assertSame(1816.75, $rifter['importCost']);
    }

    public function testMaterialQuantitiesOfAT2ProductApplyMe2RoundedUpPerMaterial(): void
    {
        $result = $this->scanT1T2AndReactionProducts();

        $jaguar = $this->rowOf(self::JAGUAR_TYPE_ID, $result);
        $this->assertSame(2, $jaguar['meUsed']);
        $this->assertSame(561300.0, $jaguar['materialCost']);
        $this->assertSame(159.75, $jaguar['importCost']);
    }

    public function testMaterialQuantitiesOfAReactionKeepTheBaseQuantities(): void
    {
        $result = $this->scanT1T2AndReactionProducts();

        $reactionProduct = $this->rowOf(self::REACTION_PRODUCT_TYPE_ID, $result);
        $this->assertSame(0, $reactionProduct['meUsed']);
        $this->assertSame(4130.0, $reactionProduct['materialCost']);
        $this->assertSame(133.75, $reactionProduct['importCost']);
    }

    private function scanT1T2AndReactionProducts(): array
    {
        $this->inventionService = $this->createMock(InventionService::class);
        $this->inventionService->method('identifyT2Products')->willReturn([self::JAGUAR_TYPE_ID => true]);
        $this->inventionService->method('expectedCostPerRun')->willReturn(['cost' => 187068.0, 'unknownReason' => null]);
        $scanner = new BatchProfitScannerService(
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
            $this->bpcPriceRepository,
            $this->activitySkillRepository,
            $this->characterSkillRepository,
            new JobInstallCostEstimator(),
            new FacilityTaxResolver(new InMemoryUserProductionStructures(), InMemorySystemCostIndices::none()),
            $this->createStub(ContractSalesReader::class),
        );

        $this->productRepository->method('findAllManufacturableProducts')->willReturn([
            ['blueprintTypeId' => self::RIFTER_BLUEPRINT_ID, 'productTypeId' => self::RIFTER_TYPE_ID, 'outputPerRun' => 1, 'activityId' => 1],
            ['blueprintTypeId' => self::JAGUAR_BLUEPRINT_ID, 'productTypeId' => self::JAGUAR_TYPE_ID, 'outputPerRun' => 1, 'activityId' => 1],
            ['blueprintTypeId' => self::REACTION_FORMULA_ID, 'productTypeId' => self::REACTION_PRODUCT_TYPE_ID, 'outputPerRun' => 1, 'activityId' => 11],
        ]);
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            self::RIFTER_BLUEPRINT_ID => [
                ['materialTypeId' => self::TRITANIUM_TYPE_ID, 'quantity' => 33],
                ['materialTypeId' => self::PYERITE_TYPE_ID, 'quantity' => 7],
                ['materialTypeId' => self::MEXALLON_TYPE_ID, 'quantity' => 1000],
            ],
            self::JAGUAR_BLUEPRINT_ID => [
                ['materialTypeId' => self::MORPHITE_TYPE_ID, 'quantity' => 33],
                ['materialTypeId' => self::NOCXIUM_TYPE_ID, 'quantity' => 150],
            ],
            self::REACTION_FORMULA_ID => [
                ['materialTypeId' => self::RAW_MATERIAL_TYPE_ID, 'quantity' => 33],
                ['materialTypeId' => self::SECOND_RAW_MATERIAL_TYPE_ID, 'quantity' => 7],
            ],
        ]);
        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'group_id')
                ? [
                    ['type_id' => self::RIFTER_TYPE_ID, 'group_id' => 25, 'group_name' => 'Frigate', 'category_id' => 6, 'volume' => 27289.0],
                    ['type_id' => self::JAGUAR_TYPE_ID, 'group_id' => 324, 'group_name' => 'Assault Frigate', 'category_id' => 6, 'volume' => 28600.0],
                    ['type_id' => self::REACTION_PRODUCT_TYPE_ID, 'group_id' => 436, 'group_name' => 'Simple Reactions', 'category_id' => 17, 'volume' => 1.0],
                ]
                : [
                    ['type_id' => self::TRITANIUM_TYPE_ID, 'volume' => 0.5],
                    ['type_id' => self::PYERITE_TYPE_ID, 'volume' => 0.25],
                    ['type_id' => self::MEXALLON_TYPE_ID, 'volume' => 2.0],
                    ['type_id' => self::MORPHITE_TYPE_ID, 'volume' => 1.5],
                    ['type_id' => self::NOCXIUM_TYPE_ID, 'volume' => 0.75],
                    ['type_id' => self::RAW_MATERIAL_TYPE_ID, 'volume' => 4.0],
                    ['type_id' => self::SECOND_RAW_MATERIAL_TYPE_ID, 'volume' => 0.25],
                ]
        );
        $this->givenJitaSellPrices([
            self::TRITANIUM_TYPE_ID => 5.0,
            self::PYERITE_TYPE_ID => 11.0,
            self::MEXALLON_TYPE_ID => 47.0,
            self::MORPHITE_TYPE_ID => 13000.0,
            self::NOCXIUM_TYPE_ID => 900.0,
            self::RAW_MATERIAL_TYPE_ID => 70.0,
            self::SECOND_RAW_MATERIAL_TYPE_ID => 260.0,
            self::RIFTER_TYPE_ID => 900000.0,
            self::JAGUAR_TYPE_ID => 3000000.0,
            self::REACTION_PRODUCT_TYPE_ID => 60000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::RIFTER_TYPE_ID => 20.0,
            self::JAGUAR_TYPE_ID => 4.0,
            self::REACTION_PRODUCT_TYPE_ID => 300.0,
        ]);
        $this->givenZeroEstimatedItemValue();

        return $scanner->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.036, 0.036), self::EXPORT_COST_PER_M3, null, self::SOLAR_SYSTEM_ID);
    }

    private const array TRITANIUM_SELL_BOOK = [[5.0, 100], [6.0, 100], [7.0, 100]];
    private const array RIFTER_SELL_BOOK = [[10.0, 100], [12.0, 100]];
    private const array SLASHER_SELL_BOOK = [[40.0, 500]];
    private const int BASE_QUANTITY_NEEDING_150_AFTER_ME10 = 166;
    private const int BASE_QUANTITY_NEEDING_300_AFTER_ME10 = 333;
    private const int BASE_QUANTITY_NEEDING_400_AFTER_ME10 = 444;
    private const int BASE_QUANTITY_NEEDING_100_AFTER_ME10 = 111;
    private const array JITA_COMPLETE_MATERIALS_SOURCE = ['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => null];

    #[Group('issue-538')]
    public function testScanPricesMaterialsAndTheJitaSaleLikeProfitMarginsOnTheSameSellBook(): void
    {
        $this->givenFrigates([self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => self::BASE_QUANTITY_NEEDING_150_AFTER_ME10], 150]]);
        $this->givenJitaSellBooks([self::TRITANIUM_TYPE_ID => self::TRITANIUM_SELL_BOOK, self::RIFTER_TYPE_ID => self::RIFTER_SELL_BOOK]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 10.0]);

        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $this->scanOnJitaWithoutFeesNorTransport());

        $this->assertSame(800.0, $rifter['materialCost']);
        $this->assertSame(10.67, $rifter['sellPrice']);
        $this->assertSame(5.33, $rifter['profitPerUnit']);
        $this->assertSame(100.0, $rifter['marginPercent']);
        $this->assertSame([], $rifter['missingPriceTypeIds']);
        $this->assertNull($rifter['unknownReason']);
    }

    #[Group('issue-538')]
    public function testMaterialNeededIn300UnitsIsPricedAtTheWeightedPriceAndTurnsTheMarginNegative(): void
    {
        $this->givenFrigates([self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => self::BASE_QUANTITY_NEEDING_300_AFTER_ME10], 150]]);
        $this->givenJitaSellBooks([self::TRITANIUM_TYPE_ID => self::TRITANIUM_SELL_BOOK, self::RIFTER_TYPE_ID => self::RIFTER_SELL_BOOK]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 10.0]);

        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $this->scanOnJitaWithoutFeesNorTransport());

        $this->assertSame(1800.0, $rifter['materialCost']);
        $this->assertSame(-1.33, $rifter['profitPerUnit']);
        $this->assertSame(-11.11, $rifter['marginPercent']);
    }

    #[Group('issue-538')]
    public function testMaterialNeededIn400UnitsIsAPartialPriceKeptWithItsLowestCoverage(): void
    {
        $this->givenFrigates([self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => self::BASE_QUANTITY_NEEDING_400_AFTER_ME10], 100]]);
        $this->givenJitaSellBooks([self::TRITANIUM_TYPE_ID => self::TRITANIUM_SELL_BOOK, self::RIFTER_TYPE_ID => self::RIFTER_SELL_BOOK]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 10.0]);

        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $this->scanOnJitaWithoutFeesNorTransport());

        $this->assertSame(2400.0, $rifter['materialCost']);
        $this->assertSame([], $rifter['missingPriceTypeIds']);
        $this->assertNull($rifter['unknownReason']);
        $this->assertArrayHasKey('priceSources', $rifter);
        $this->assertSame(['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => 75.0], $rifter['priceSources']['materials']);
    }

    #[Group('issue-538')]
    public function testTheLowestCoverageOfTheMaterialsIsReportedAsTheMaterialsCoverage(): void
    {
        $this->givenFrigates([self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [
            self::TRITANIUM_TYPE_ID => self::BASE_QUANTITY_NEEDING_400_AFTER_ME10,
            self::PYERITE_TYPE_ID => self::BASE_QUANTITY_NEEDING_300_AFTER_ME10,
        ], 100]]);
        $this->givenJitaSellBooks([
            self::TRITANIUM_TYPE_ID => self::TRITANIUM_SELL_BOOK,
            self::PYERITE_TYPE_ID => [[11.0, 150]],
            self::RIFTER_TYPE_ID => self::RIFTER_SELL_BOOK,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 10.0]);

        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $this->scanOnJitaWithoutFeesNorTransport());

        $this->assertSame(2400.0 + 3300.0, $rifter['materialCost']);
        $this->assertArrayHasKey('priceSources', $rifter);
        $this->assertSame(50.0, $rifter['priceSources']['materials']['coveragePercent']);
    }

    #[Group('issue-538')]
    public function testProductSoldBy100UnitsPerRunIsSoldAtTheBestOrderWithoutPartialCoverage(): void
    {
        $this->givenFrigates([self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => self::BASE_QUANTITY_NEEDING_100_AFTER_ME10], 100]]);
        $this->givenJitaSellBooks([self::TRITANIUM_TYPE_ID => self::TRITANIUM_SELL_BOOK, self::RIFTER_TYPE_ID => self::RIFTER_SELL_BOOK]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 10.0]);

        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $this->scanOnJitaWithoutFeesNorTransport());

        $this->assertSame(10.0, $rifter['sellPrice']);
        $this->assertArrayHasKey('priceSources', $rifter);
        $this->assertSame(['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => null], $rifter['priceSources']['sale']);
    }

    #[Group('issue-538')]
    public function testProductSoldBy300UnitsPerRunIsAPartialSalePriceKeptWithItsCoverage(): void
    {
        $this->givenFrigates([self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => self::BASE_QUANTITY_NEEDING_100_AFTER_ME10], 300]]);
        $this->givenJitaSellBooks([self::TRITANIUM_TYPE_ID => self::TRITANIUM_SELL_BOOK, self::RIFTER_TYPE_ID => self::RIFTER_SELL_BOOK]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 10.0]);

        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $this->scanOnJitaWithoutFeesNorTransport());

        $this->assertSame(11.0, $rifter['sellPrice']);
        $this->assertNull($rifter['unknownReason']);
        $this->assertArrayHasKey('priceSources', $rifter);
        $this->assertSame(['market' => 'jita', 'side' => 'sell', 'method' => 'weighted', 'coveragePercent' => 66.7], $rifter['priceSources']['sale']);
    }

    #[Group('issue-538')]
    public function testMaterialWithoutSellBookIsAMissingPriceInsteadOfAnotherPrice(): void
    {
        $this->givenFrigates([self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 10], 100]]);
        $this->givenJitaSellBooks([self::RIFTER_TYPE_ID => self::RIFTER_SELL_BOOK]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 10.0]);

        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $this->scanOnJitaWithoutFeesNorTransport());

        $this->assertNull($rifter['materialCost']);
        $this->assertNull($rifter['marginPercent']);
        $this->assertSame(10.0, $rifter['sellPrice']);
        $this->assertSame(self::UNKNOWN_REASON_MISSING_MATERIAL_PRICE, $rifter['unknownReason']);
        $this->assertSame([self::TRITANIUM_TYPE_ID], $rifter['missingPriceTypeIds']);
    }

    #[Group('issue-538')]
    public function testMaterialQuoteWithoutCoverageIsAMissingPriceInsteadOfACompletePrice(): void
    {
        $this->givenFrigates([self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 10], 100]]);
        $this->jitaMarketService->method('getWeightedSellQuotesWithFallback')->willReturnCallback(
            static fn (array $requests): array => array_map(
                static fn (array $request): array => $request['typeId'] === self::TRITANIUM_TYPE_ID
                    ? ['weightedPrice' => 5.0, 'ordersUsed' => 1]
                    : ['weightedPrice' => 10.0, 'coverage' => 1.0, 'ordersUsed' => 1],
                $requests,
            ),
        );
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 10.0]);

        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $this->scanOnJitaWithoutFeesNorTransport());

        $this->assertNull($rifter['materialCost']);
        $this->assertSame(self::UNKNOWN_REASON_MISSING_MATERIAL_PRICE, $rifter['unknownReason']);
        $this->assertSame([self::TRITANIUM_TYPE_ID], $rifter['missingPriceTypeIds']);
    }

    #[Group('issue-538')]
    public function testMaterialQuoteWithZeroCoverageIsAMissingPrice(): void
    {
        $this->givenFrigates([self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 10], 100]]);
        $this->jitaMarketService->method('getWeightedSellQuotesWithFallback')->willReturnCallback(
            static fn (array $requests): array => array_map(
                static fn (array $request): array => ['weightedPrice' => 5.0, 'coverage' => $request['typeId'] === self::TRITANIUM_TYPE_ID ? 0.0 : 1.0, 'ordersUsed' => 1],
                $requests,
            ),
        );
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 10.0]);

        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $this->scanOnJitaWithoutFeesNorTransport());

        $this->assertNull($rifter['materialCost']);
        $this->assertSame(self::UNKNOWN_REASON_MISSING_MATERIAL_PRICE, $rifter['unknownReason']);
        $this->assertSame([self::TRITANIUM_TYPE_ID], $rifter['missingPriceTypeIds']);
    }

    #[Group('issue-538')]
    public function testSaleQuoteWithoutCoverageLeavesTheSalePriceUnknown(): void
    {
        $this->givenFrigates([self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 10], 100]]);
        $this->jitaMarketService->method('getWeightedSellQuotesWithFallback')->willReturnCallback(
            static fn (array $requests): array => array_map(
                static fn (array $request): array => $request['typeId'] === self::RIFTER_TYPE_ID
                    ? ['weightedPrice' => 10.0, 'ordersUsed' => 1]
                    : ['weightedPrice' => 5.0, 'coverage' => 1.0, 'ordersUsed' => 1],
                $requests,
            ),
        );
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 10.0]);

        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $this->scanOnJitaWithoutFeesNorTransport());

        $this->assertNull($rifter['sellPrice']);
        $this->assertNull($rifter['marginPercent']);
        $this->assertSame(self::UNKNOWN_REASON_MISSING_SALE_PRICE, $rifter['unknownReason']);
    }

    #[Group('issue-538')]
    public function testTwoProductsConsumingTheSameMaterialInDifferentQuantitiesGetDifferentUnitPricesFromASingleBookRead(): void
    {
        $this->givenFrigates([
            self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => self::BASE_QUANTITY_NEEDING_150_AFTER_ME10], 150],
            self::SLASHER_BLUEPRINT_ID => [self::SLASHER_TYPE_ID, [self::TRITANIUM_TYPE_ID => self::BASE_QUANTITY_NEEDING_300_AFTER_ME10], 20],
        ]);
        $this->jitaMarketService->expects($this->once())->method('getWeightedSellQuotesWithFallback')->willReturnCallback(
            QuotingJitaMarketService::weighedFrom([
                self::TRITANIUM_TYPE_ID => self::TRITANIUM_SELL_BOOK,
                self::RIFTER_TYPE_ID => self::RIFTER_SELL_BOOK,
                self::SLASHER_TYPE_ID => self::SLASHER_SELL_BOOK,
            ]),
        );
        $this->jitaMarketService->expects($this->never())->method('getWeightedSellPrices');
        $this->jitaMarketService->expects($this->never())->method('getPricesWithFallback');
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 10.0, self::SLASHER_TYPE_ID => 30.0]);

        $result = $this->scanOnJitaWithoutFeesNorTransport();

        $this->assertSame(800.0, $this->rowOf(self::RIFTER_TYPE_ID, $result)['materialCost']);
        $this->assertSame(1800.0, $this->rowOf(self::SLASHER_TYPE_ID, $result)['materialCost']);
        $this->assertSame(40.0, $this->rowOf(self::SLASHER_TYPE_ID, $result)['sellPrice']);
    }

    #[Group('issue-538')]
    public function testEveryRowExposesTheAssumptionsOfTheScanNextToItsPriceSources(): void
    {
        $this->givenFrigates([self::RIFTER_BLUEPRINT_ID => [self::RIFTER_TYPE_ID, [self::TRITANIUM_TYPE_ID => 10], 1]]);
        $this->givenJitaSellPrices([self::TRITANIUM_TYPE_ID => 5.0, self::RIFTER_TYPE_ID => 100.0]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([self::RIFTER_TYPE_ID => 10.0]);

        $rifter = $this->rowOf(self::RIFTER_TYPE_ID, $this->scanOnJitaWithoutFeesNorTransport());

        $this->assertArrayHasKey('priceSources', $rifter);
        $this->assertSame(self::JITA_COMPLETE_MATERIALS_SOURCE, $rifter['priceSources']['materials']);
        $this->assertArrayHasKey('assumptions', $rifter['priceSources']);
        $this->assertSame(1, $rifter['priceSources']['assumptions']['runs']);
        $this->assertSame($rifter['meUsed'], $rifter['priceSources']['assumptions']['materialEfficiency']);
        $this->assertSame(10, $rifter['priceSources']['assumptions']['materialEfficiency']);
    }

    #[Group('issue-538')]
    public function testStructureSaleStaysAtTheLowestSellOrderOfTheStructureWhileMaterialsStayWeightedOnJita(): void
    {
        $this->givenOneFrigateScannedForAStructureInRegion(10000060);
        $this->jitaMarketService->method('getCachedDailyVolumesForRegion')->willReturn([587 => 20.0]);

        $result = $this->service->scan('all', null, null, 'structure', 1035466617946, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);

        $this->assertSame(3000000.0, $result[0]['sellPrice']);
        $this->assertArrayHasKey('priceSources', $result[0]);
        $this->assertSame(['market' => 'structure', 'side' => 'sell', 'method' => 'lowest', 'coveragePercent' => null], $result[0]['priceSources']['sale']);
        $this->assertSame(self::JITA_COMPLETE_MATERIALS_SOURCE, $result[0]['priceSources']['materials']);
    }

    private function scanOnJitaWithoutFeesNorTransport(): array
    {
        return $this->service->scan('all', null, null, 'jita', null, self::SOLAR_SYSTEM_ID, new SalesFees(0.0, 0.0), 0.0, null, self::SOLAR_SYSTEM_ID);
    }
}
