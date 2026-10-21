<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\InventionService;
use App\Service\Industry\PivotAdvisorService;
use App\Service\JitaMarketService;
use App\Service\TypeNameResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PivotAdvisorService::class)]
class PivotAdvisorServiceTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private IndustryActivityMaterialRepository&MockObject $materialRepository;
    private IndustryActivityProductRepository&MockObject $productRepository;
    private CachedAssetRepository&MockObject $assetRepository;
    private JitaMarketService&MockObject $jitaMarketService;
    private EsiCostIndexService&MockObject $esiCostIndexService;
    private TypeNameResolver&MockObject $typeNameResolver;
    private EntityManagerInterface&MockObject $entityManager;
    private Connection&MockObject $connection;
    private User&MockObject $user;
    private InventionService&MockObject $inventionService;
    private PivotAdvisorService $service;

    private const SOLAR_SYSTEM_ID = 30002510;
    private const BROKER_FEE = 0.036;
    private const SALES_TAX = 0.036;

    // Type IDs for test fixtures
    private const SOURCE_PRODUCT = 11379; // Sabre (source)
    private const SOURCE_BLUEPRINT = 11380;
    private const BUILDABLE_MAT_A = 11399; // Component A (buildable)
    private const BUILDABLE_MAT_B = 11400; // Component B (buildable)
    private const BUILDABLE_MAT_C = 11401; // Component C (buildable)
    private const RAW_MAT = 34; // Tritanium (not buildable)

    private const CANDIDATE_1 = 22456; // Candidate product 1
    private const CANDIDATE_1_BP = 22457;
    private const CANDIDATE_2 = 22458; // Candidate product 2
    private const CANDIDATE_2_BP = 22459;
    private const CANDIDATE_3 = 22460; // Candidate product 3 (shares 1/3 materials)
    private const CANDIDATE_3_BP = 22461;

    protected function setUp(): void
    {
        $this->materialRepository = $this->createMock(IndustryActivityMaterialRepository::class);
        $this->productRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->assetRepository = $this->createMock(CachedAssetRepository::class);
        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->esiCostIndexService = $this->createMock(EsiCostIndexService::class);
        $this->typeNameResolver = $this->createMock(TypeNameResolver::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->user = $this->createMock(User::class);

        $this->inventionService = $this->createMock(InventionService::class);

        $this->entityManager->method('getConnection')->willReturn($this->connection);

        // Default: no T2 products
        $this->inventionService->method('identifyT2Products')->willReturn([]);

        $this->service = new PivotAdvisorService(
            $this->materialRepository,
            $this->productRepository,
            $this->assetRepository,
            $this->jitaMarketService,
            $this->esiCostIndexService,
            $this->typeNameResolver,
            $this->entityManager,
            $this->inventionService,
        );
    }

    public function testAnalyzeWithBuildableMaterialsFindsAndRanksCandidates(): void
    {
        // Source blueprint found
        $sourceBp = $this->createBlueprintProduct(self::SOURCE_BLUEPRINT, self::SOURCE_PRODUCT, 1, 1);
        $this->setupBlueprintLookup($sourceBp);

        // Source materials: 2 buildable + 1 raw
        $this->materialRepository->method('findMaterialsForBlueprints')
            ->willReturnCallback(function (array $bpIds, array $actIds) {
                if (in_array(self::SOURCE_BLUEPRINT, $bpIds, true) && count($bpIds) === 1) {
                    return [
                        self::SOURCE_BLUEPRINT => [
                            ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 100],
                            ['materialTypeId' => self::BUILDABLE_MAT_B, 'quantity' => 200],
                            ['materialTypeId' => self::BUILDABLE_MAT_C, 'quantity' => 50],
                            ['materialTypeId' => self::RAW_MAT, 'quantity' => 1000],
                        ],
                    ];
                }

                // Candidate materials
                $result = [];
                if (in_array(self::CANDIDATE_1_BP, $bpIds, true)) {
                    $result[self::CANDIDATE_1_BP] = [
                        ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 80],
                        ['materialTypeId' => self::BUILDABLE_MAT_B, 'quantity' => 150],
                        ['materialTypeId' => self::RAW_MAT, 'quantity' => 500],
                    ];
                }
                if (in_array(self::CANDIDATE_2_BP, $bpIds, true)) {
                    $result[self::CANDIDATE_2_BP] = [
                        ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 120],
                        ['materialTypeId' => self::BUILDABLE_MAT_B, 'quantity' => 100],
                        ['materialTypeId' => self::RAW_MAT, 'quantity' => 800],
                    ];
                }
                if (in_array(self::CANDIDATE_3_BP, $bpIds, true)) {
                    $result[self::CANDIDATE_3_BP] = [
                        ['materialTypeId' => self::BUILDABLE_MAT_C, 'quantity' => 30],
                        ['materialTypeId' => self::RAW_MAT, 'quantity' => 2000],
                    ];
                }

                return $result;
            });

        // Buildable material check: A, B, C are buildable, RAW_MAT is not
        $this->setupBuildableMaterialCheck();

        // User stock
        $this->assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([
            self::BUILDABLE_MAT_A => 200,
            self::BUILDABLE_MAT_B => 300,
            self::BUILDABLE_MAT_C => 100,
        ]);

        // Reverse lookup returns 3 candidates
        $this->materialRepository->method('findProductsUsingMaterials')->willReturn([
            ['blueprintTypeId' => self::CANDIDATE_1_BP, 'productTypeId' => self::CANDIDATE_1, 'outputPerRun' => 1, 'activityId' => 1],
            ['blueprintTypeId' => self::CANDIDATE_2_BP, 'productTypeId' => self::CANDIDATE_2, 'outputPerRun' => 1, 'activityId' => 1],
            ['blueprintTypeId' => self::CANDIDATE_3_BP, 'productTypeId' => self::CANDIDATE_3, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        // Jita prices
        $this->jitaMarketService->method('getPrices')->willReturn([
            self::SOURCE_PRODUCT => 50000000.0,
            self::BUILDABLE_MAT_A => 100000.0,
            self::BUILDABLE_MAT_B => 50000.0,
            self::BUILDABLE_MAT_C => 200000.0,
            self::RAW_MAT => 5.0,
            self::CANDIDATE_1 => 40000000.0,
            self::CANDIDATE_2 => 60000000.0,
            self::CANDIDATE_3 => 20000000.0,
        ]);

        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::SOURCE_PRODUCT => 50.0,
            self::CANDIDATE_1 => 30.0,
            self::CANDIDATE_2 => 100.0,
            self::CANDIDATE_3 => 5.0,
        ]);

        // Not T2 (no invention)
        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchOne')->willReturn(0);

        // Type names
        $this->typeNameResolver->method('findNames')->willReturn([
            self::SOURCE_PRODUCT => 'Source Ship',
            self::BUILDABLE_MAT_A => 'Component A',
            self::BUILDABLE_MAT_B => 'Component B',
            self::BUILDABLE_MAT_C => 'Component C',
            self::RAW_MAT => 'Tritanium',
            self::CANDIDATE_1 => 'Candidate Alpha',
            self::CANDIDATE_2 => 'Candidate Beta',
            self::CANDIDATE_3 => 'Candidate Gamma',
        ]);

        // Group names
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['type_id' => self::SOURCE_PRODUCT, 'group_name' => 'Destroyer'],
            ['type_id' => self::CANDIDATE_1, 'group_name' => 'Cruiser'],
            ['type_id' => self::CANDIDATE_2, 'group_name' => 'Battleship'],
            ['type_id' => self::CANDIDATE_3, 'group_name' => 'Frigate'],
        ]);

        // Job install cost
        $this->givenAJobInstallCostPerRunOf(50000.0);

        $result = $this->service->analyze(
            self::SOURCE_PRODUCT,
            1,
            self::SOLAR_SYSTEM_ID,
            self::BROKER_FEE,
            self::SALES_TAX,
            $this->user,
        );

        // Verify structure
        $this->assertSame(self::SOURCE_PRODUCT, $result['typeId']);
        $this->assertSame(self::SOURCE_PRODUCT, $result['sourceProduct']['typeId']);
        $this->assertNotEmpty($result['candidates']);
        $this->assertLessThanOrEqual(6, count($result['candidates']));

        // Candidates should be sorted by score descending
        for ($i = 1; $i < count($result['candidates']); $i++) {
            $this->assertGreaterThanOrEqual(
                $result['candidates'][$i]['score'],
                $result['candidates'][$i - 1]['score'],
                'Candidates should be sorted by score descending',
            );
        }

        // Each candidate should have required fields
        foreach ($result['candidates'] as $candidate) {
            $this->assertArrayHasKey('typeId', $candidate);
            $this->assertArrayHasKey('typeName', $candidate);
            $this->assertArrayHasKey('marginPercent', $candidate);
            $this->assertArrayHasKey('coveragePercent', $candidate);
            $this->assertArrayHasKey('missingComponents', $candidate);
            $this->assertArrayHasKey('additionalCost', $candidate);
            $this->assertArrayHasKey('score', $candidate);
        }
    }

    public function testCoverageCalculationIsValueWeighted(): void
    {
        $sourceBp = $this->createBlueprintProduct(self::SOURCE_BLUEPRINT, self::SOURCE_PRODUCT, 1, 1);
        $this->setupBlueprintLookup($sourceBp);

        // Source materials: 2 buildable
        $this->materialRepository->method('findMaterialsForBlueprints')
            ->willReturnCallback(function (array $bpIds) {
                if (in_array(self::SOURCE_BLUEPRINT, $bpIds, true) && count($bpIds) === 1) {
                    return [
                        self::SOURCE_BLUEPRINT => [
                            ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 10],
                            ['materialTypeId' => self::BUILDABLE_MAT_B, 'quantity' => 20],
                        ],
                    ];
                }

                return [
                    self::CANDIDATE_1_BP => [
                        ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 10],
                        ['materialTypeId' => self::BUILDABLE_MAT_B, 'quantity' => 20],
                    ],
                ];
            });

        $this->setupBuildableMaterialCheck();

        // Stock: 5 of A (price 1000 each), 20 of B (price 100 each)
        $this->assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([
            self::BUILDABLE_MAT_A => 5, // covers 5 of 9 needed (after ME10: ceil(10*0.9)=9)
            self::BUILDABLE_MAT_B => 20, // covers all 18 needed (after ME10: ceil(20*0.9)=18)
        ]);

        $this->materialRepository->method('findProductsUsingMaterials')->willReturn([
            ['blueprintTypeId' => self::CANDIDATE_1_BP, 'productTypeId' => self::CANDIDATE_1, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        // A costs 1000, B costs 100
        $this->jitaMarketService->method('getPrices')->willReturn([
            self::SOURCE_PRODUCT => 50000.0,
            self::BUILDABLE_MAT_A => 1000.0,
            self::BUILDABLE_MAT_B => 100.0,
            self::CANDIDATE_1 => 40000.0,
        ]);

        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::SOURCE_PRODUCT => 50.0,
            self::CANDIDATE_1 => 30.0,
        ]);

        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchOne')->willReturn(0);
        $this->typeNameResolver->method('findNames')->willReturn([
            self::SOURCE_PRODUCT => 'Source',
            self::BUILDABLE_MAT_A => 'Mat A',
            self::BUILDABLE_MAT_B => 'Mat B',
            self::CANDIDATE_1 => 'Candidate',
        ]);
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['type_id' => self::SOURCE_PRODUCT, 'group_name' => 'Group'],
            ['type_id' => self::CANDIDATE_1, 'group_name' => 'Group'],
        ]);
        $this->givenAJobInstallCostPerRunOf(0.0);

        $result = $this->service->analyze(
            self::SOURCE_PRODUCT,
            1,
            self::SOLAR_SYSTEM_ID,
            self::BROKER_FEE,
            self::SALES_TAX,
            $this->user,
        );

        $this->assertCount(1, $result['candidates']);
        $candidate = $result['candidates'][0];

        // With ME10: A needs ceil(10*0.9)=9, B needs ceil(20*0.9)=18
        // Coverage numerator: min(5,9)*1000 + min(20,18)*100 = 5000 + 1800 = 6800
        // Coverage denominator: 9*1000 + 18*100 = 9000 + 1800 = 10800
        // Coverage = 6800/10800 * 100 = 62.96%
        $this->assertEqualsWithDelta(62.96, $candidate['coveragePercent'], 0.1);
    }

    public function testMissingComponentsComputation(): void
    {
        $sourceBp = $this->createBlueprintProduct(self::SOURCE_BLUEPRINT, self::SOURCE_PRODUCT, 1, 1);
        $this->setupBlueprintLookup($sourceBp);

        $this->materialRepository->method('findMaterialsForBlueprints')
            ->willReturnCallback(function (array $bpIds) {
                if (in_array(self::SOURCE_BLUEPRINT, $bpIds, true) && count($bpIds) === 1) {
                    return [
                        self::SOURCE_BLUEPRINT => [
                            ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 100],
                            ['materialTypeId' => self::BUILDABLE_MAT_B, 'quantity' => 50],
                        ],
                    ];
                }

                return [
                    self::CANDIDATE_1_BP => [
                        ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 100],
                        ['materialTypeId' => self::BUILDABLE_MAT_B, 'quantity' => 50],
                    ],
                ];
            });

        $this->setupBuildableMaterialCheck();

        // Stock: 50 of A (needs 90 after ME), 0 of B (needs 45 after ME)
        $this->assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([
            self::BUILDABLE_MAT_A => 50,
            // B not in stock at all
        ]);

        $this->materialRepository->method('findProductsUsingMaterials')->willReturn([
            ['blueprintTypeId' => self::CANDIDATE_1_BP, 'productTypeId' => self::CANDIDATE_1, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        $this->jitaMarketService->method('getPrices')->willReturn([
            self::SOURCE_PRODUCT => 50000.0,
            self::BUILDABLE_MAT_A => 1000.0,
            self::BUILDABLE_MAT_B => 2000.0,
            self::CANDIDATE_1 => 40000.0,
        ]);

        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::SOURCE_PRODUCT => 10.0,
            self::CANDIDATE_1 => 10.0,
        ]);

        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchOne')->willReturn(0);
        $this->typeNameResolver->method('findNames')->willReturn([
            self::SOURCE_PRODUCT => 'Source',
            self::BUILDABLE_MAT_A => 'Mat A',
            self::BUILDABLE_MAT_B => 'Mat B',
            self::CANDIDATE_1 => 'Candidate',
        ]);
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['type_id' => self::SOURCE_PRODUCT, 'group_name' => 'Group'],
            ['type_id' => self::CANDIDATE_1, 'group_name' => 'Group'],
        ]);
        $this->givenAJobInstallCostPerRunOf(0.0);

        $result = $this->service->analyze(
            self::SOURCE_PRODUCT,
            1,
            self::SOLAR_SYSTEM_ID,
            self::BROKER_FEE,
            self::SALES_TAX,
            $this->user,
        );

        $candidate = $result['candidates'][0];

        // ME10: A needs ceil(100*0.9)=90, have 50, missing 40, cost = 40*1000 = 40000
        // ME10: B needs ceil(50*0.9)=45, have 0, missing 45, cost = 45*2000 = 90000
        $this->assertCount(2, $candidate['missingComponents']);

        $missingA = null;
        $missingB = null;
        foreach ($candidate['missingComponents'] as $mc) {
            if ($mc['typeId'] === self::BUILDABLE_MAT_A) {
                $missingA = $mc;
            }
            if ($mc['typeId'] === self::BUILDABLE_MAT_B) {
                $missingB = $mc;
            }
        }

        $this->assertNotNull($missingA);
        $this->assertSame(40, $missingA['quantity']);
        $this->assertSame(40000.0, $missingA['cost']);

        $this->assertNotNull($missingB);
        $this->assertSame(45, $missingB['quantity']);
        $this->assertSame(90000.0, $missingB['cost']);

        // Total additional cost = 40000 + 90000 = 130000
        $this->assertSame(130000.0, $candidate['additionalCost']);
    }

    public function testNoCandidatesWhenOnlyRawMaterials(): void
    {
        // Source uses only raw materials (no buildable)
        $sourceBp = $this->createBlueprintProduct(self::SOURCE_BLUEPRINT, self::SOURCE_PRODUCT, 1, 1);

        $this->productRepository->method('findBlueprintForProduct')
            ->willReturnCallback(function (int $typeId, int $activityId) use ($sourceBp) {
                if ($typeId === self::SOURCE_PRODUCT && $activityId === 1) {
                    return $sourceBp;
                }

                return null; // No blueprints for any material = all are raw
            });

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            self::SOURCE_BLUEPRINT => [
                ['materialTypeId' => self::RAW_MAT, 'quantity' => 1000],
            ],
        ]);

        $this->assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([]);

        $this->jitaMarketService->method('getPrices')->willReturn([
            self::SOURCE_PRODUCT => 50000.0,
            self::RAW_MAT => 5.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::SOURCE_PRODUCT => 10.0,
        ]);

        $this->connection->method('fetchOne')->willReturn(0);
        $this->typeNameResolver->method('findNames')->willReturn([
            self::SOURCE_PRODUCT => 'Source',
            self::RAW_MAT => 'Tritanium',
        ]);
        $this->typeNameResolver->method('findName')->willReturn('Source');
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['type_id' => self::SOURCE_PRODUCT, 'group_name' => 'Group'],
        ]);
        $this->givenAJobInstallCostPerRunOf(0.0);

        $result = $this->service->analyze(
            self::SOURCE_PRODUCT,
            1,
            self::SOLAR_SYSTEM_ID,
            self::BROKER_FEE,
            self::SALES_TAX,
            $this->user,
        );

        $this->assertEmpty($result['candidates']);
        $this->assertEmpty($result['matrix']);
        $this->assertSame(self::SOURCE_PRODUCT, $result['sourceProduct']['typeId']);
    }

    public function testFullCoverageMeansZeroAdditionalCost(): void
    {
        $sourceBp = $this->createBlueprintProduct(self::SOURCE_BLUEPRINT, self::SOURCE_PRODUCT, 1, 1);
        $this->setupBlueprintLookup($sourceBp);

        $this->materialRepository->method('findMaterialsForBlueprints')
            ->willReturnCallback(function (array $bpIds) {
                if (in_array(self::SOURCE_BLUEPRINT, $bpIds, true) && count($bpIds) === 1) {
                    return [
                        self::SOURCE_BLUEPRINT => [
                            ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 10],
                        ],
                    ];
                }

                return [
                    self::CANDIDATE_1_BP => [
                        ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 10],
                    ],
                ];
            });

        $this->setupBuildableMaterialCheck();

        // Plenty of stock (more than needed after ME)
        $this->assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([
            self::BUILDABLE_MAT_A => 99999,
        ]);

        $this->materialRepository->method('findProductsUsingMaterials')->willReturn([
            ['blueprintTypeId' => self::CANDIDATE_1_BP, 'productTypeId' => self::CANDIDATE_1, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        $this->jitaMarketService->method('getPrices')->willReturn([
            self::SOURCE_PRODUCT => 50000.0,
            self::BUILDABLE_MAT_A => 1000.0,
            self::CANDIDATE_1 => 40000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::SOURCE_PRODUCT => 10.0,
            self::CANDIDATE_1 => 10.0,
        ]);

        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchOne')->willReturn(0);
        $this->typeNameResolver->method('findNames')->willReturn([
            self::SOURCE_PRODUCT => 'Source',
            self::BUILDABLE_MAT_A => 'Mat A',
            self::CANDIDATE_1 => 'Candidate',
        ]);
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['type_id' => self::SOURCE_PRODUCT, 'group_name' => 'Group'],
            ['type_id' => self::CANDIDATE_1, 'group_name' => 'Group'],
        ]);
        $this->givenAJobInstallCostPerRunOf(0.0);

        $result = $this->service->analyze(
            self::SOURCE_PRODUCT,
            1,
            self::SOLAR_SYSTEM_ID,
            self::BROKER_FEE,
            self::SALES_TAX,
            $this->user,
        );

        $candidate = $result['candidates'][0];

        $this->assertSame(100.0, $candidate['coveragePercent']);
        $this->assertSame(0.0, $candidate['additionalCost']);
        $this->assertEmpty($candidate['missingComponents']);
    }

    public function testMatrixStructureHasCorrectRowsAndColumns(): void
    {
        $sourceBp = $this->createBlueprintProduct(self::SOURCE_BLUEPRINT, self::SOURCE_PRODUCT, 1, 1);
        $this->setupBlueprintLookup($sourceBp);

        $this->materialRepository->method('findMaterialsForBlueprints')
            ->willReturnCallback(function (array $bpIds) {
                if (in_array(self::SOURCE_BLUEPRINT, $bpIds, true) && count($bpIds) === 1) {
                    return [
                        self::SOURCE_BLUEPRINT => [
                            ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 10],
                            ['materialTypeId' => self::BUILDABLE_MAT_B, 'quantity' => 20],
                            ['materialTypeId' => self::RAW_MAT, 'quantity' => 1000],
                        ],
                    ];
                }

                return [
                    self::CANDIDATE_1_BP => [
                        ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 15],
                        ['materialTypeId' => self::RAW_MAT, 'quantity' => 500],
                    ],
                ];
            });

        $this->setupBuildableMaterialCheck();

        $this->assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([
            self::BUILDABLE_MAT_A => 100,
            self::BUILDABLE_MAT_B => 0,
        ]);

        $this->materialRepository->method('findProductsUsingMaterials')->willReturn([
            ['blueprintTypeId' => self::CANDIDATE_1_BP, 'productTypeId' => self::CANDIDATE_1, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        $this->jitaMarketService->method('getPrices')->willReturn([
            self::SOURCE_PRODUCT => 50000.0,
            self::BUILDABLE_MAT_A => 1000.0,
            self::BUILDABLE_MAT_B => 2000.0,
            self::RAW_MAT => 5.0,
            self::CANDIDATE_1 => 40000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::SOURCE_PRODUCT => 10.0,
            self::CANDIDATE_1 => 10.0,
        ]);

        $this->connection->method('fetchFirstColumn')->willReturn([]);
        $this->connection->method('fetchOne')->willReturn(0);
        $this->typeNameResolver->method('findNames')->willReturn([
            self::SOURCE_PRODUCT => 'Source',
            self::BUILDABLE_MAT_A => 'Mat A',
            self::BUILDABLE_MAT_B => 'Mat B',
            self::RAW_MAT => 'Tritanium',
            self::CANDIDATE_1 => 'Candidate',
        ]);
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['type_id' => self::SOURCE_PRODUCT, 'group_name' => 'Destroyer'],
            ['type_id' => self::CANDIDATE_1, 'group_name' => 'Cruiser'],
        ]);
        $this->givenAJobInstallCostPerRunOf(0.0);

        $result = $this->service->analyze(
            self::SOURCE_PRODUCT,
            1,
            self::SOLAR_SYSTEM_ID,
            self::BROKER_FEE,
            self::SALES_TAX,
            $this->user,
        );

        // Matrix rows = buildable materials (A and B, not RAW_MAT)
        $this->assertCount(2, $result['matrix']);

        $matARow = null;
        $matBRow = null;
        foreach ($result['matrix'] as $row) {
            if ($row['typeId'] === self::BUILDABLE_MAT_A) {
                $matARow = $row;
            }
            if ($row['typeId'] === self::BUILDABLE_MAT_B) {
                $matBRow = $row;
            }
        }

        $this->assertNotNull($matARow);
        $this->assertNotNull($matBRow);

        // Matrix columns = [source, candidate1]
        $this->assertSame([self::SOURCE_PRODUCT, self::CANDIDATE_1], $result['matrixProductIds']);

        // Mat A: source uses it (covered), candidate uses it (covered)
        $this->assertSame('covered', $matARow['candidates'][self::SOURCE_PRODUCT]['status']);
        $this->assertSame('covered', $matARow['candidates'][self::CANDIDATE_1]['status']);

        // Mat B: source uses it (none, stock=0), candidate doesn't use it (none, needed=0)
        $this->assertSame('none', $matBRow['candidates'][self::SOURCE_PRODUCT]['status']);
        $this->assertSame('none', $matBRow['candidates'][self::CANDIDATE_1]['status']);
        $this->assertSame(0, $matBRow['candidates'][self::CANDIDATE_1]['needed']);
    }

    private const array PIVOT_SDE_TYPE_NAMES = [
        self::SOURCE_PRODUCT => 'Source Ship',
        self::BUILDABLE_MAT_A => 'Component A',
        self::BUILDABLE_MAT_B => 'Component B',
        self::RAW_MAT => 'Tritanium',
        self::CANDIDATE_1 => 'Candidate Alpha',
        self::CANDIDATE_2 => 'Candidate Beta',
    ];
    private const float JOB_INSTALL_COST_PER_RUN = 1500.0;

    #[Group('issue-115')]
    public function testPivotAnalysisOfTypesKnownToTheSdeKeepsTheirSdeNames(): void
    {
        $this->givenSourceSharingTwoBuildableMaterialsWithTwoCandidates(self::BUILDABLE_MAT_B, self::CANDIDATE_1);

        $result = $this->analyzeOnSdeKnowingPivotTypes();

        $this->assertSame('Source Ship', $result['sourceProduct']['typeName']);
        $this->assertSame(['Component A', 'Component B'], array_column($result['sourceProduct']['keyComponents'], 'typeName'));
        $this->assertSame(['Component A', 'Component B'], array_column($result['matrix'], 'typeName'));
        $this->assertSame(['Candidate Alpha', 'Candidate Beta'], array_column($result['candidates'], 'typeName'));
        $this->assertSame(['Component B'], array_column($result['candidates'][0]['missingComponents'], 'typeName'));
        $this->assertSame(['Component A'], array_column($result['candidates'][1]['missingComponents'], 'typeName'));
    }

    #[Group('issue-115')]
    public function testMaterialMissingFromTheSdeHasAnUnknownNameInThePivotMatrixWithUnchangedQuantitiesAndCosts(): void
    {
        $this->givenSourceSharingTwoBuildableMaterialsWithTwoCandidates(self::ROBOTICS_TYPE_ID, self::CANDIDATE_1);

        $result = $this->analyzeOnSdeKnowingPivotTypes();

        $this->assertSame([
            [
                'typeId' => self::BUILDABLE_MAT_A,
                'typeName' => 'Component A',
                'inStock' => 50,
                'candidates' => [
                    self::SOURCE_PRODUCT => ['needed' => 10, 'status' => 'covered'],
                    self::CANDIDATE_1 => ['needed' => 30, 'status' => 'covered'],
                    self::CANDIDATE_2 => ['needed' => 60, 'status' => 'partial'],
                ],
            ],
            [
                'typeId' => self::ROBOTICS_TYPE_ID,
                'typeName' => null,
                'inStock' => 6,
                'candidates' => [
                    self::SOURCE_PRODUCT => ['needed' => 20, 'status' => 'partial'],
                    self::CANDIDATE_1 => ['needed' => 40, 'status' => 'partial'],
                    self::CANDIDATE_2 => ['needed' => 0, 'status' => 'none'],
                ],
            ],
        ], $result['matrix']);
        $this->assertSame(
            [['typeId' => self::ROBOTICS_TYPE_ID, 'typeName' => null, 'quantity' => 30, 'cost' => 75000.0]],
            $result['candidates'][0]['missingComponents'],
        );
        $this->assertSame(75000.0, $result['candidates'][0]['additionalCost']);
        $this->assertSame(
            [
                ['typeId' => self::BUILDABLE_MAT_A, 'typeName' => 'Component A', 'inStock' => 50],
                ['typeId' => self::ROBOTICS_TYPE_ID, 'typeName' => null, 'inStock' => 6],
            ],
            $result['sourceProduct']['keyComponents'],
        );
        $this->assertSame(1292.0, $result['sourceProduct']['marginPercent']);
    }

    #[Group('issue-115')]
    public function testSourceProductMissingFromTheSdeWithoutBlueprintHasAnUnknownName(): void
    {
        $this->productRepository->method('findBlueprintForProduct')->willReturn(null);

        $result = $this->pivotAdvisorOnSdeKnowing(self::PIVOT_SDE_TYPE_NAMES)->analyze(
            self::ROBOTICS_TYPE_ID,
            1,
            self::SOLAR_SYSTEM_ID,
            self::BROKER_FEE,
            self::SALES_TAX,
            $this->user,
        );

        $this->assertSame([
            'typeId' => self::ROBOTICS_TYPE_ID,
            'sourceProduct' => [
                'typeId' => self::ROBOTICS_TYPE_ID,
                'typeName' => null,
                'groupName' => '',
                'marginPercent' => null,
                'sellPrice' => null,
                'dailyVolume' => 0.0,
                'keyComponents' => [],
            ],
            'matrix' => [],
            'candidates' => [],
            'matrixProductIds' => [self::ROBOTICS_TYPE_ID],
        ], $result);
    }

    #[Group('issue-115')]
    public function testCandidateProductMissingFromTheSdeHasAnUnknownNameWithUnchangedMarginAndRank(): void
    {
        $this->givenSourceSharingTwoBuildableMaterialsWithTwoCandidates(self::BUILDABLE_MAT_B, self::ROBOTICS_TYPE_ID);

        $result = $this->analyzeOnSdeKnowingPivotTypes();

        $this->assertSame([
            [
                'typeId' => self::ROBOTICS_TYPE_ID,
                'typeName' => null,
                'groupName' => 'Cruiser',
                'marginPercent' => 207.41,
                'profitPerUnit' => 250450.0,
                'dailyVolume' => 9.0,
                'coveragePercent' => 35.9,
                'missingComponents' => [['typeId' => self::BUILDABLE_MAT_B, 'typeName' => 'Component B', 'quantity' => 30, 'cost' => 75000.0]],
                'additionalCost' => 75000.0,
                'estimatedProfit' => 250450.0,
                'score' => 7445.56,
                'unknownReason' => null,
                'missingPriceTypeIds' => [],
            ],
            [
                'typeId' => self::CANDIDATE_2,
                'typeName' => 'Candidate Beta',
                'groupName' => 'Battleship',
                'marginPercent' => 115.81,
                'profitPerUnit' => 74700.0,
                'dailyVolume' => 3.0,
                'coveragePercent' => 92.59,
                'missingComponents' => [['typeId' => self::BUILDABLE_MAT_A, 'typeName' => 'Component A', 'quantity' => 4, 'cost' => 4000.0]],
                'additionalCost' => 4000.0,
                'estimatedProfit' => 74700.0,
                'score' => 6456.2,
                'unknownReason' => null,
                'missingPriceTypeIds' => [],
            ],
        ], $result['candidates']);
        $this->assertSame([self::SOURCE_PRODUCT, self::ROBOTICS_TYPE_ID, self::CANDIDATE_2], $result['matrixProductIds']);
    }

    private function givenSourceSharingTwoBuildableMaterialsWithTwoCandidates(int $sharedMaterialTypeId, int $firstCandidateTypeId): void
    {
        $sourceBlueprint = $this->createBlueprintProduct(self::SOURCE_BLUEPRINT, self::SOURCE_PRODUCT, 1, 1);
        $componentBlueprint = $this->createBlueprintProduct(99001, self::BUILDABLE_MAT_A, 1, 1);
        $sharedMaterialBlueprint = $this->createBlueprintProduct(99002, $sharedMaterialTypeId, 1, 1);
        $this->productRepository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $typeId, int $activityId): ?IndustryActivityProduct => match (true) {
                $activityId !== 1 => null,
                $typeId === self::SOURCE_PRODUCT => $sourceBlueprint,
                $typeId === self::BUILDABLE_MAT_A => $componentBlueprint,
                $typeId === $sharedMaterialTypeId => $sharedMaterialBlueprint,
                default => null,
            },
        );

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturnCallback(
            static fn (array $blueprintTypeIds): array => $blueprintTypeIds === [self::SOURCE_BLUEPRINT]
                ? [self::SOURCE_BLUEPRINT => [
                    ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 10],
                    ['materialTypeId' => $sharedMaterialTypeId, 'quantity' => 20],
                    ['materialTypeId' => self::RAW_MAT, 'quantity' => 1000],
                ]]
                : [
                    self::CANDIDATE_1_BP => [
                        ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 30],
                        ['materialTypeId' => $sharedMaterialTypeId, 'quantity' => 40],
                        ['materialTypeId' => self::RAW_MAT, 'quantity' => 500],
                    ],
                    self::CANDIDATE_2_BP => [
                        ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 60],
                        ['materialTypeId' => self::RAW_MAT, 'quantity' => 2000],
                    ],
                ],
        );
        $this->materialRepository->method('findProductsUsingMaterials')->willReturn([
            ['blueprintTypeId' => self::CANDIDATE_1_BP, 'productTypeId' => $firstCandidateTypeId, 'outputPerRun' => 1, 'activityId' => 1],
            ['blueprintTypeId' => self::CANDIDATE_2_BP, 'productTypeId' => self::CANDIDATE_2, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        $this->assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([
            self::BUILDABLE_MAT_A => 50,
            $sharedMaterialTypeId => 6,
        ]);
        $this->jitaMarketService->method('getPrices')->willReturn([
            self::SOURCE_PRODUCT => 900000.0,
            self::BUILDABLE_MAT_A => 1000.0,
            $sharedMaterialTypeId => 2500.0,
            self::RAW_MAT => 5.0,
            $firstCandidateTypeId => 400000.0,
            self::CANDIDATE_2 => 150000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::SOURCE_PRODUCT => 12.0,
            $firstCandidateTypeId => 9.0,
            self::CANDIDATE_2 => 3.0,
        ]);

        $this->connection->method('fetchOne')->willReturn(0);
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['type_id' => self::SOURCE_PRODUCT, 'group_name' => 'Destroyer'],
            ['type_id' => $firstCandidateTypeId, 'group_name' => 'Cruiser'],
            ['type_id' => self::CANDIDATE_2, 'group_name' => 'Battleship'],
        ]);
        $this->givenAJobInstallCostPerRunOf(self::JOB_INSTALL_COST_PER_RUN);
    }

    private function analyzeOnSdeKnowingPivotTypes(): array
    {
        return $this->pivotAdvisorOnSdeKnowing(self::PIVOT_SDE_TYPE_NAMES)->analyze(
            self::SOURCE_PRODUCT,
            1,
            self::SOLAR_SYSTEM_ID,
            self::BROKER_FEE,
            self::SALES_TAX,
            $this->user,
        );
    }

    private function pivotAdvisorOnSdeKnowing(array $typeNamesByTypeId): PivotAdvisorService
    {
        return new PivotAdvisorService(
            $this->materialRepository,
            $this->productRepository,
            $this->assetRepository,
            $this->jitaMarketService,
            $this->esiCostIndexService,
            new TypeNameResolver($this->sdeTypeRepositoryKnowing($typeNamesByTypeId)),
            $this->entityManager,
            $this->inventionService,
        );
    }

    // --- Helper methods ---

    private function createBlueprintProduct(int $bpTypeId, int $productTypeId, int $quantity, int $activityId): IndustryActivityProduct
    {
        $bp = new IndustryActivityProduct();
        $bp->setTypeId($bpTypeId);
        $bp->setProductTypeId($productTypeId);
        $bp->setQuantity($quantity);
        $bp->setActivityId($activityId);

        return $bp;
    }

    /**
     * Setup findBlueprintForProduct to return source blueprint and buildable material blueprints.
     */
    private function setupBlueprintLookup(IndustryActivityProduct $sourceBp): void
    {
        $matABp = $this->createBlueprintProduct(99001, self::BUILDABLE_MAT_A, 1, 1);
        $matBBp = $this->createBlueprintProduct(99002, self::BUILDABLE_MAT_B, 1, 1);
        $matCBp = $this->createBlueprintProduct(99003, self::BUILDABLE_MAT_C, 1, 1);

        $this->productRepository->method('findBlueprintForProduct')
            ->willReturnCallback(function (int $typeId, int $activityId) use ($sourceBp, $matABp, $matBBp, $matCBp) {
                if ($typeId === self::SOURCE_PRODUCT && $activityId === 1) {
                    return $sourceBp;
                }
                if ($typeId === self::BUILDABLE_MAT_A && $activityId === 1) {
                    return $matABp;
                }
                if ($typeId === self::BUILDABLE_MAT_B && $activityId === 1) {
                    return $matBBp;
                }
                if ($typeId === self::BUILDABLE_MAT_C && $activityId === 1) {
                    return $matCBp;
                }

                return null;
            });
    }

    /**
     * Setup the buildable material check: A, B, C are buildable, RAW_MAT is not.
     */
    private function setupBuildableMaterialCheck(): void
    {
        // Already handled via setupBlueprintLookup - materials with blueprints are buildable
    }

    private const int REACTION_CANDIDATE = 22462;
    private const int REACTION_CANDIDATE_FORMULA = 22463;

    public function testCandidateMaterialQuantitiesOfAT1ProductApplyMe10RoundedUpPerMaterial(): void
    {
        $candidate = $this->candidateOf(self::CANDIDATE_1, $this->analyzeT1T2AndReactionCandidates());

        $this->assertSame([self::BUILDABLE_MAT_A => 30], $this->missingQuantitiesOf($candidate));
        $this->assertSame(39000.0, $candidate['additionalCost']);
        $this->assertSame(53758.0, $candidate['profitPerUnit']);
    }

    public function testCandidateMaterialQuantitiesOfAT2ProductApplyMe2RoundedUpPerMaterial(): void
    {
        $candidate = $this->candidateOf(self::CANDIDATE_2, $this->analyzeT1T2AndReactionCandidates());

        $this->assertSame([self::BUILDABLE_MAT_A => 147], $this->missingQuantitiesOf($candidate));
        $this->assertSame(191100.0, $candidate['additionalCost']);
        $this->assertSame(179902.0, $candidate['profitPerUnit']);
    }

    public function testCandidateMaterialQuantitiesOfAReactionKeepTheBaseQuantities(): void
    {
        $candidate = $this->candidateOf(self::REACTION_CANDIDATE, $this->analyzeT1T2AndReactionCandidates());

        $this->assertSame([self::BUILDABLE_MAT_A => 33], $this->missingQuantitiesOf($candidate));
        $this->assertSame(42900.0, $candidate['additionalCost']);
        $this->assertSame(22018.0, $candidate['profitPerUnit']);
    }

    public function testSourceProductMarginAppliesMe10RoundedUpPerMaterial(): void
    {
        $result = $this->analyzeT1T2AndReactionCandidates();

        $this->assertSame(400.0, $result['sourceProduct']['marginPercent']);
    }

    private function analyzeT1T2AndReactionCandidates(): array
    {
        $sourceBlueprint = $this->createBlueprintProduct(self::SOURCE_BLUEPRINT, self::SOURCE_PRODUCT, 1, 1);
        $componentBlueprint = $this->createBlueprintProduct(99001, self::BUILDABLE_MAT_A, 1, 1);
        $this->productRepository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $typeId, int $activityId): ?IndustryActivityProduct => match (true) {
                $activityId !== 1 => null,
                $typeId === self::SOURCE_PRODUCT => $sourceBlueprint,
                $typeId === self::BUILDABLE_MAT_A => $componentBlueprint,
                default => null,
            },
        );
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturnCallback(
            static fn (array $blueprintTypeIds): array => $blueprintTypeIds === [self::SOURCE_BLUEPRINT]
                ? [self::SOURCE_BLUEPRINT => [
                    ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 7],
                    ['materialTypeId' => self::RAW_MAT, 'quantity' => 33],
                ]]
                : [
                    self::CANDIDATE_1_BP => [
                        ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 33],
                        ['materialTypeId' => self::RAW_MAT, 'quantity' => 7],
                    ],
                    self::CANDIDATE_2_BP => [
                        ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 150],
                        ['materialTypeId' => self::RAW_MAT, 'quantity' => 33],
                    ],
                    self::REACTION_CANDIDATE_FORMULA => [
                        ['materialTypeId' => self::BUILDABLE_MAT_A, 'quantity' => 33],
                        ['materialTypeId' => self::RAW_MAT, 'quantity' => 7],
                    ],
                ],
        );
        $this->materialRepository->method('findProductsUsingMaterials')->willReturn([
            ['blueprintTypeId' => self::CANDIDATE_1_BP, 'productTypeId' => self::CANDIDATE_1, 'outputPerRun' => 1, 'activityId' => 1],
            ['blueprintTypeId' => self::CANDIDATE_2_BP, 'productTypeId' => self::CANDIDATE_2, 'outputPerRun' => 1, 'activityId' => 1],
            ['blueprintTypeId' => self::REACTION_CANDIDATE_FORMULA, 'productTypeId' => self::REACTION_CANDIDATE, 'outputPerRun' => 1, 'activityId' => 11],
        ]);
        $this->assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([]);
        $this->jitaMarketService->method('getPrices')->willReturn([
            self::SOURCE_PRODUCT => 50000.0,
            self::BUILDABLE_MAT_A => 1300.0,
            self::RAW_MAT => 6.0,
            self::CANDIDATE_1 => 100000.0,
            self::CANDIDATE_2 => 400000.0,
            self::REACTION_CANDIDATE => 70000.0,
        ]);
        $this->jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::SOURCE_PRODUCT => 12.0,
            self::CANDIDATE_1 => 9.0,
            self::CANDIDATE_2 => 3.0,
            self::REACTION_CANDIDATE => 40.0,
        ]);
        $this->connection->method('fetchOne')->willReturn(0);
        $this->connection->method('fetchAllAssociative')->willReturn([]);
        $this->givenAJobInstallCostPerRunOf(0.0);

        $this->inventionService = $this->createMock(InventionService::class);
        $this->inventionService->method('identifyT2Products')->willReturn([self::CANDIDATE_2 => true]);

        return $this->pivotAdvisorOnSdeKnowing([])->analyze(
            self::SOURCE_PRODUCT,
            1,
            self::SOLAR_SYSTEM_ID,
            self::BROKER_FEE,
            self::SALES_TAX,
            $this->user,
        );
    }

    private function givenAJobInstallCostPerRunOf(float $jobInstallCostPerRun): void
    {
        $this->esiCostIndexService->method('estimateJobInstallCost')->willReturn(
            ['cost' => $jobInstallCostPerRun, 'unknownReason' => null, 'missingAdjustedPriceTypeIds' => []],
        );
    }

    private function candidateOf(int $typeId, array $result): array
    {
        foreach ($result['candidates'] as $candidate) {
            if ($candidate['typeId'] === $typeId) {
                return $candidate;
            }
        }

        $this->fail("Candidate {$typeId} is missing from the pivot analysis");
    }

    private function missingQuantitiesOf(array $candidate): array
    {
        return array_column($candidate['missingComponents'], 'quantity', 'typeId');
    }
}
