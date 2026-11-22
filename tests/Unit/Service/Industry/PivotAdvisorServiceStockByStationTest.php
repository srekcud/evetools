<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\FacilityTaxResolver;
use App\Service\Industry\InventionService;
use App\Service\Industry\PivotAdvisorService;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\JitaMarketService;
use App\Service\TypeNameResolver;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Service\Industry\Double\SellQuoteCallbacks;
use App\Tests\Unit\Support\StubsPersonalStockReader;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PivotAdvisorService::class)]
class PivotAdvisorServiceStockByStationTest extends TestCase
{
    use StubsPersonalStockReader;

    private const int SOLAR_SYSTEM_ID = 30002510;
    private const string USER_ID = '0192f3a4-7b1c-7d2e-8f3a-4b5c6d7e8f90';
    private const float BROKER_FEE = 0.036;
    private const float SALES_TAX = 0.036;

    private const int SOURCE_PRODUCT = 11379;
    private const int SOURCE_BLUEPRINT = 11380;
    private const int ROBOTICS = 11399;
    private const int CAPACITOR_BATTERY = 11400;
    private const int CONSTRUCTION_BLOCK = 11401;
    private const int RAW_MATERIAL = 34;
    private const int CANDIDATE_ALPHA = 22456;
    private const int CANDIDATE_ALPHA_BLUEPRINT = 22457;
    private const int CANDIDATE_BETA = 22458;
    private const int CANDIDATE_BETA_BLUEPRINT = 22459;

    private const int JITA_STATION_ID = 60003760;
    private const int ISHTAR_STATION_ID = 60011866;
    private const int RENS_STATION_ID = 60004588;

    public function testMatrixRowAndKeyComponentOfAComponentStoredInTwoStationsCarryTheSameStockByStation(): void
    {
        $result = $this->analyzeWithStock($this->stockOfRoboticsInTwoStationsAndBatteriesInOne());

        $expectedRoboticsStations = [
            [
                'locationId' => self::JITA_STATION_ID,
                'locationName' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
                'systemName' => 'Jita',
                'quantity' => 40,
            ],
            [
                'locationId' => self::ISHTAR_STATION_ID,
                'locationName' => 'Ishtar VII - Moon 2 - Sisters of EVE Bureau',
                'systemName' => 'Ishtar',
                'quantity' => 15,
            ],
        ];

        $matrixRow = $this->matrixRowOf(self::ROBOTICS, $result);
        $keyComponent = $this->keyComponentOf(self::ROBOTICS, $result);
        $this->assertSame(55, $matrixRow['inStock']);
        $this->assertSame(55, $keyComponent['inStock']);
        $this->assertArrayHasKey('stockByStation', $matrixRow);
        $this->assertArrayHasKey('stockByStation', $keyComponent);
        $this->assertSame($expectedRoboticsStations, $matrixRow['stockByStation']);
        $this->assertSame($expectedRoboticsStations, $keyComponent['stockByStation']);
    }

    public function testStockByStationOfEachComponentIsItsOwnAndNotSharedWithTheOtherComponents(): void
    {
        $result = $this->analyzeWithStock($this->stockOfRoboticsInTwoStationsAndBatteriesInOne());

        $expectedBatteryStations = [
            [
                'locationId' => self::RENS_STATION_ID,
                'locationName' => 'Rens VI - Moon 8 - Brutor Tribe Treasury',
                'systemName' => null,
                'quantity' => 7,
            ],
        ];

        $this->assertSame(7, $this->matrixRowOf(self::CAPACITOR_BATTERY, $result)['inStock']);
        $this->assertArrayHasKey('stockByStation', $this->matrixRowOf(self::CAPACITOR_BATTERY, $result));
        $this->assertSame($expectedBatteryStations, $this->matrixRowOf(self::CAPACITOR_BATTERY, $result)['stockByStation']);
        $this->assertArrayHasKey('stockByStation', $this->keyComponentOf(self::CAPACITOR_BATTERY, $result));
        $this->assertSame($expectedBatteryStations, $this->keyComponentOf(self::CAPACITOR_BATTERY, $result)['stockByStation']);
    }

    public function testAComponentWithoutStockHasZeroInStockAndAnEmptyStockByStation(): void
    {
        $result = $this->analyzeWithStock($this->stockOfRoboticsInTwoStationsAndBatteriesInOne());

        $matrixRow = $this->matrixRowOf(self::CONSTRUCTION_BLOCK, $result);
        $keyComponent = $this->keyComponentOf(self::CONSTRUCTION_BLOCK, $result);
        $this->assertSame(0, $matrixRow['inStock']);
        $this->assertSame(0, $keyComponent['inStock']);
        $this->assertArrayHasKey('stockByStation', $matrixRow);
        $this->assertArrayHasKey('stockByStation', $keyComponent);
        $this->assertSame([], $matrixRow['stockByStation']);
        $this->assertSame([], $keyComponent['stockByStation']);
    }

    public function testAStationWithoutSystemNameKeepsANullSystemNameInStockByStation(): void
    {
        $result = $this->analyzeWithStock($this->stockOfRoboticsInTwoStationsAndBatteriesInOne());

        $matrixRow = $this->matrixRowOf(self::CAPACITOR_BATTERY, $result);
        $keyComponent = $this->keyComponentOf(self::CAPACITOR_BATTERY, $result);
        $this->assertArrayHasKey('stockByStation', $matrixRow);
        $this->assertArrayHasKey('stockByStation', $keyComponent);
        $this->assertCount(1, $matrixRow['stockByStation']);
        $this->assertCount(1, $keyComponent['stockByStation']);
        $this->assertArrayHasKey('systemName', $matrixRow['stockByStation'][0]);
        $this->assertArrayHasKey('systemName', $keyComponent['stockByStation'][0]);
        $this->assertNull($matrixRow['stockByStation'][0]['systemName']);
        $this->assertNull($keyComponent['stockByStation'][0]['systemName']);
    }

    public function testSplittingTheSameTotalStockOverTwoStationsDoesNotChangeTheCoverage(): void
    {
        $stockInTwoStations = $this->analyzeWithStock($this->stockOfRoboticsInTwoStationsAndBatteriesInOne());
        $stockInOneStation = $this->analyzeWithStock($this->stockOfRoboticsInJitaOnlyAndBatteriesInOne());

        $roboticsInTwoStations = $this->matrixRowOf(self::ROBOTICS, $stockInTwoStations);
        $roboticsInOneStation = $this->matrixRowOf(self::ROBOTICS, $stockInOneStation);
        $this->assertArrayHasKey('stockByStation', $roboticsInTwoStations);
        $this->assertArrayHasKey('stockByStation', $roboticsInOneStation);
        $this->assertCount(2, $roboticsInTwoStations['stockByStation']);
        $this->assertCount(1, $roboticsInOneStation['stockByStation']);
        $this->assertSame(55, $roboticsInTwoStations['inStock']);
        $this->assertSame(55, $roboticsInOneStation['inStock']);
        $this->assertSame(
            ['needed' => 54, 'status' => 'covered'],
            $roboticsInTwoStations['candidates'][self::SOURCE_PRODUCT],
        );
        $this->assertSame($roboticsInOneStation['candidates'], $roboticsInTwoStations['candidates']);
        $this->assertSame(
            array_column($stockInOneStation['matrix'], 'candidates', 'typeId'),
            array_column($stockInTwoStations['matrix'], 'candidates', 'typeId'),
        );
        $this->assertSame(
            array_column($stockInOneStation['candidates'], 'coveragePercent', 'typeId'),
            array_column($stockInTwoStations['candidates'], 'coveragePercent', 'typeId'),
        );
    }

    public function testCoverageFollowsTheTotalQuantityEvenWhenTheStationBreakdownSumsToLess(): void
    {
        $stock = $this->stockOfRoboticsInJitaOnlyAndBatteriesInOne();
        $stock['locations'][self::ROBOTICS] = ['total' => 40, 'locations' => [$this->jitaLocationWith(40)]];

        $result = $this->analyzeWithStock($stock);

        $robotics = $this->matrixRowOf(self::ROBOTICS, $result);
        $this->assertSame(55, $robotics['inStock']);
        $this->assertSame(55, $this->keyComponentOf(self::ROBOTICS, $result)['inStock']);
        $this->assertSame(['needed' => 54, 'status' => 'covered'], $robotics['candidates'][self::SOURCE_PRODUCT]);
        $this->assertSame([40], array_column($robotics['stockByStation'], 'quantity'));
    }

    public function testASourceProductWithOnlyRawMaterialsHasNoKeyComponentAndNoMatrix(): void
    {
        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([self::RAW_MATERIAL => 900]);
        $assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([
            self::RAW_MATERIAL => ['total' => 900, 'locations' => [$this->jitaLocationWith(900)]],
        ]);
        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findMaterialsForBlueprints')->willReturn([
            self::SOURCE_BLUEPRINT => [['materialTypeId' => self::RAW_MATERIAL, 'quantity' => 1000]],
        ]);
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $sourceBlueprint = $this->blueprintProduct(self::SOURCE_BLUEPRINT, self::SOURCE_PRODUCT);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $typeId, int $activityId): ?IndustryActivityProduct => $typeId === self::SOURCE_PRODUCT && $activityId === 1 ? $sourceBlueprint : null,
        );

        $result = $this->analyzeOn($assetRepository, $materialRepository, $productRepository);

        $this->assertSame([], $result['sourceProduct']['keyComponents']);
        $this->assertSame([], $result['matrix']);
        $this->assertSame([], $result['candidates']);
    }

    /**
     * @return array{quantities: array<int, int>, locations: array<int, array{total: int, locations: list<array<string, mixed>>}>}
     */
    private function stockOfRoboticsInTwoStationsAndBatteriesInOne(): array
    {
        return [
            'quantities' => [self::ROBOTICS => 55, self::CAPACITOR_BATTERY => 7],
            'locations' => [
                self::ROBOTICS => ['total' => 55, 'locations' => [
                    $this->jitaLocationWith(40),
                    [
                        'locationId' => self::ISHTAR_STATION_ID,
                        'locationName' => 'Ishtar VII - Moon 2 - Sisters of EVE Bureau',
                        'systemName' => 'Ishtar',
                        'quantity' => 15,
                    ],
                ]],
                self::CAPACITOR_BATTERY => ['total' => 7, 'locations' => [$this->rensLocationWith(7)]],
            ],
        ];
    }

    /**
     * @return array{quantities: array<int, int>, locations: array<int, array{total: int, locations: list<array<string, mixed>>}>}
     */
    private function stockOfRoboticsInJitaOnlyAndBatteriesInOne(): array
    {
        return [
            'quantities' => [self::ROBOTICS => 55, self::CAPACITOR_BATTERY => 7],
            'locations' => [
                self::ROBOTICS => ['total' => 55, 'locations' => [$this->jitaLocationWith(55)]],
                self::CAPACITOR_BATTERY => ['total' => 7, 'locations' => [$this->rensLocationWith(7)]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jitaLocationWith(int $quantity): array
    {
        return [
            'locationId' => self::JITA_STATION_ID,
            'locationName' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
            'systemName' => 'Jita',
            'quantity' => $quantity,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rensLocationWith(int $quantity): array
    {
        return [
            'locationId' => self::RENS_STATION_ID,
            'locationName' => 'Rens VI - Moon 8 - Brutor Tribe Treasury',
            'systemName' => null,
            'quantity' => $quantity,
        ];
    }

    /**
     * @param array{quantities: array<int, int>, locations: array<int, array{total: int, locations: list<array<string, mixed>>}>} $stock
     */
    private function analyzeWithStock(array $stock): array
    {
        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('getAggregatedQuantitiesByUser')->willReturn($stock['quantities']);
        $assetRepository->method('getQuantitiesByUserWithLocations')->willReturn($stock['locations']);

        $sourceBlueprint = $this->blueprintProduct(self::SOURCE_BLUEPRINT, self::SOURCE_PRODUCT);
        $roboticsBlueprint = $this->blueprintProduct(99001, self::ROBOTICS);
        $batteryBlueprint = $this->blueprintProduct(99002, self::CAPACITOR_BATTERY);
        $blockBlueprint = $this->blueprintProduct(99003, self::CONSTRUCTION_BLOCK);
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $typeId, int $activityId): ?IndustryActivityProduct => match (true) {
                $activityId !== 1 => null,
                $typeId === self::SOURCE_PRODUCT => $sourceBlueprint,
                $typeId === self::ROBOTICS => $roboticsBlueprint,
                $typeId === self::CAPACITOR_BATTERY => $batteryBlueprint,
                $typeId === self::CONSTRUCTION_BLOCK => $blockBlueprint,
                default => null,
            },
        );

        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findMaterialsForBlueprints')->willReturnCallback(
            static fn (array $blueprintTypeIds): array => $blueprintTypeIds === [self::SOURCE_BLUEPRINT]
                ? [self::SOURCE_BLUEPRINT => [
                    ['materialTypeId' => self::ROBOTICS, 'quantity' => 60],
                    ['materialTypeId' => self::CAPACITOR_BATTERY, 'quantity' => 20],
                    ['materialTypeId' => self::CONSTRUCTION_BLOCK, 'quantity' => 30],
                    ['materialTypeId' => self::RAW_MATERIAL, 'quantity' => 1000],
                ]]
                : [
                    self::CANDIDATE_ALPHA_BLUEPRINT => [
                        ['materialTypeId' => self::ROBOTICS, 'quantity' => 70],
                        ['materialTypeId' => self::CAPACITOR_BATTERY, 'quantity' => 40],
                        ['materialTypeId' => self::RAW_MATERIAL, 'quantity' => 500],
                    ],
                    self::CANDIDATE_BETA_BLUEPRINT => [
                        ['materialTypeId' => self::ROBOTICS, 'quantity' => 80],
                        ['materialTypeId' => self::CONSTRUCTION_BLOCK, 'quantity' => 10],
                        ['materialTypeId' => self::RAW_MATERIAL, 'quantity' => 2000],
                    ],
                ],
        );
        $materialRepository->method('findProductsUsingMaterials')->willReturn([
            ['blueprintTypeId' => self::CANDIDATE_ALPHA_BLUEPRINT, 'productTypeId' => self::CANDIDATE_ALPHA, 'outputPerRun' => 1, 'activityId' => 1],
            ['blueprintTypeId' => self::CANDIDATE_BETA_BLUEPRINT, 'productTypeId' => self::CANDIDATE_BETA, 'outputPerRun' => 1, 'activityId' => 1],
        ]);

        return $this->analyzeOn($assetRepository, $materialRepository, $productRepository);
    }

    private function analyzeOn(
        CachedAssetRepository $assetRepository,
        IndustryActivityMaterialRepository $materialRepository,
        IndustryActivityProductRepository $productRepository,
    ): array {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::fromString(self::USER_ID));

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getWeightedSellQuotesWithFallback')->willReturnCallback(SellQuoteCallbacks::completeQuotesAt([
            self::SOURCE_PRODUCT => 900000.0,
            self::ROBOTICS => 1000.0,
            self::CAPACITOR_BATTERY => 2500.0,
            self::CONSTRUCTION_BLOCK => 1800.0,
            self::RAW_MATERIAL => 5.0,
            self::CANDIDATE_ALPHA => 400000.0,
            self::CANDIDATE_BETA => 150000.0,
        ]));
        $jitaMarketService->method('getCachedDailyVolumes')->willReturn([
            self::SOURCE_PRODUCT => 12.0,
            self::CANDIDATE_ALPHA => 9.0,
            self::CANDIDATE_BETA => 3.0,
        ]);

        $esiCostIndexService = $this->createStub(EsiCostIndexService::class);
        $esiCostIndexService->method('estimateJobInstallCost')->willReturn(
            ['cost' => 1500.0, 'unknownReason' => null, 'missingAdjustedPriceTypeIds' => []],
        );

        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('findNames')->willReturn([
            self::SOURCE_PRODUCT => 'Source Ship',
            self::ROBOTICS => 'Robotics',
            self::CAPACITOR_BATTERY => 'Capacitor Battery',
            self::CONSTRUCTION_BLOCK => 'Construction Block',
            self::RAW_MATERIAL => 'Tritanium',
            self::CANDIDATE_ALPHA => 'Candidate Alpha',
            self::CANDIDATE_BETA => 'Candidate Beta',
        ]);
        $typeNameResolver->method('findName')->willReturn('Source Ship');

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['type_id' => self::SOURCE_PRODUCT, 'group_name' => 'Destroyer'],
            ['type_id' => self::CANDIDATE_ALPHA, 'group_name' => 'Cruiser'],
            ['type_id' => self::CANDIDATE_BETA, 'group_name' => 'Battleship'],
        ]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $inventionService = $this->createStub(InventionService::class);
        $inventionService->method('identifyT2Products')->willReturn([]);

        $service = new PivotAdvisorService(
            $materialRepository,
            $productRepository,
            $this->personalStockReaderOn($assetRepository),
            $jitaMarketService,
            $esiCostIndexService,
            $typeNameResolver,
            $entityManager,
            $inventionService,
            new FacilityTaxResolver(new InMemoryUserProductionStructures(), InMemorySystemCostIndices::none()),
        );

        return $service->analyze(
            self::SOURCE_PRODUCT,
            1,
            self::SOLAR_SYSTEM_ID,
            new SalesFees(self::BROKER_FEE, self::SALES_TAX),
            $user,
            self::SOLAR_SYSTEM_ID,
        );
    }

    private function blueprintProduct(int $blueprintTypeId, int $productTypeId): IndustryActivityProduct
    {
        $blueprint = new IndustryActivityProduct();
        $blueprint->setTypeId($blueprintTypeId);
        $blueprint->setProductTypeId($productTypeId);
        $blueprint->setQuantity(1);
        $blueprint->setActivityId(1);

        return $blueprint;
    }

    private function matrixRowOf(int $materialTypeId, array $result): array
    {
        foreach ($result['matrix'] as $row) {
            if ($row['typeId'] === $materialTypeId) {
                return $row;
            }
        }

        $this->fail('No matrix row for the material ' . $materialTypeId);
    }

    private function keyComponentOf(int $materialTypeId, array $result): array
    {
        foreach ($result['sourceProduct']['keyComponents'] as $keyComponent) {
            if ($keyComponent['typeId'] === $materialTypeId) {
                return $keyComponent;
            }
        }

        $this->fail('No key component for the material ' . $materialTypeId);
    }
}
