<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Enum\IndustryActivityType;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\InventionService;
use App\Service\JitaMarketService;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventionService::class)]
class InventionServiceTest extends TestCase
{
    private IndustryActivityProductRepository&MockObject $activityProductRepository;
    private IndustryActivityMaterialRepository&MockObject $activityMaterialRepository;
    private IndustryActivityRepository&MockObject $activityRepository;
    private InvTypeRepository&MockObject $invTypeRepository;
    private JitaMarketService&MockObject $jitaMarketService;
    private EsiCostIndexService&MockObject $esiCostIndexService;
    private TypeNameResolver&MockObject $typeNameResolver;
    private InventionService $service;

    // EVE type IDs for realistic test data
    private const SABRE_TYPE_ID = 22456;           // T2 Sabre (Interdictor)
    private const SABRE_BLUEPRINT_ID = 22457;       // T2 Sabre Blueprint
    private const THRASHER_BLUEPRINT_ID = 16238;    // T1 Thrasher Blueprint (invents into Sabre)
    private const DATACORE_1_TYPE_ID = 20424;       // Datacore - Mechanical Engineering
    private const DATACORE_2_TYPE_ID = 20172;       // Datacore - Minmatar Starship Engineering
    private const ACCELERANT_DECRYPTOR_ID = 34201;
    private const ATTAINMENT_DECRYPTOR_ID = 34202;
    private const AUGMENTATION_DECRYPTOR_ID = 34203;

    protected function setUp(): void
    {
        $this->activityProductRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->activityMaterialRepository = $this->createMock(IndustryActivityMaterialRepository::class);
        $this->activityRepository = $this->createMock(IndustryActivityRepository::class);
        $this->invTypeRepository = $this->createMock(InvTypeRepository::class);
        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->esiCostIndexService = $this->createMock(EsiCostIndexService::class);
        $this->typeNameResolver = $this->createMock(TypeNameResolver::class);

        $this->service = new InventionService(
            $this->activityProductRepository,
            $this->activityMaterialRepository,
            $this->activityRepository,
            $this->invTypeRepository,
            $this->jitaMarketService,
            $this->esiCostIndexService,
            $this->typeNameResolver,
        );
    }

    // ===========================================
    // Helper methods
    // ===========================================

    private function createProduct(int $blueprintTypeId, int $activityId, int $productTypeId, int $quantity, ?float $probability = null): IndustryActivityProduct
    {
        $product = new IndustryActivityProduct();
        $product->setTypeId($blueprintTypeId);
        $product->setActivityId($activityId);
        $product->setProductTypeId($productTypeId);
        $product->setQuantity($quantity);
        if ($probability !== null) {
            $product->setProbability($probability);
        }

        return $product;
    }

    private function createMaterial(int $blueprintTypeId, int $activityId, int $materialTypeId, int $quantity): IndustryActivityMaterial
    {
        $material = new IndustryActivityMaterial();
        $material->setTypeId($blueprintTypeId);
        $material->setActivityId($activityId);
        $material->setMaterialTypeId($materialTypeId);
        $material->setQuantity($quantity);

        return $material;
    }

    private function createActivity(int $blueprintTypeId, int $activityId, int $time): IndustryActivity
    {
        $activity = new IndustryActivity();
        $activity->setTypeId($blueprintTypeId);
        $activity->setActivityId($activityId);
        $activity->setTime($time);

        return $activity;
    }

    /**
     * Set up a standard T2 invention chain for the Sabre (used by most tests).
     */
    private function setupSabreInventionChain(float $baseProbability = 0.30, int $baseRuns = 10): void
    {
        // Step 1: T2 Blueprint manufactures the Sabre
        $t2Manufacturing = $this->createProduct(
            self::SABRE_BLUEPRINT_ID,
            IndustryActivityType::Manufacturing->value,
            self::SABRE_TYPE_ID,
            1,
        );

        // Step 2: T1 Blueprint invents into T2 Blueprint
        $inventionProduct = $this->createProduct(
            self::THRASHER_BLUEPRINT_ID,
            IndustryActivityType::Invention->value,
            self::SABRE_BLUEPRINT_ID,
            $baseRuns,
            $baseProbability,
        );

        $this->activityProductRepository
            ->method('findBlueprintForProduct')
            ->willReturnCallback(function (int $productTypeId, int $activityId) use ($t2Manufacturing, $inventionProduct): ?IndustryActivityProduct {
                if ($productTypeId === self::SABRE_TYPE_ID && $activityId === IndustryActivityType::Manufacturing->value) {
                    return $t2Manufacturing;
                }
                if ($productTypeId === self::SABRE_BLUEPRINT_ID && $activityId === IndustryActivityType::Invention->value) {
                    return $inventionProduct;
                }

                return null;
            });

        // Invention materials: 2 datacores
        $datacore1 = $this->createMaterial(
            self::THRASHER_BLUEPRINT_ID,
            IndustryActivityType::Invention->value,
            self::DATACORE_1_TYPE_ID,
            2,
        );
        $datacore2 = $this->createMaterial(
            self::THRASHER_BLUEPRINT_ID,
            IndustryActivityType::Invention->value,
            self::DATACORE_2_TYPE_ID,
            2,
        );

        $this->activityMaterialRepository
            ->method('findByBlueprintAndActivity')
            ->willReturn([$datacore1, $datacore2]);

        // Invention activity time
        $inventionActivity = $this->createActivity(
            self::THRASHER_BLUEPRINT_ID,
            IndustryActivityType::Invention->value,
            102000, // ~28 hours
        );
        $this->activityRepository->method('findOneBy')->willReturn($inventionActivity);

        // Type names for datacores
        $dc1Type = $this->createMock(InvType::class);
        $dc1Type->method('getTypeName')->willReturn('Datacore - Mechanical Engineering');
        $dc2Type = $this->createMock(InvType::class);
        $dc2Type->method('getTypeName')->willReturn('Datacore - Minmatar Starship Engineering');

        $this->invTypeRepository
            ->method('findByTypeIds')
            ->willReturn([
                self::DATACORE_1_TYPE_ID => $dc1Type,
                self::DATACORE_2_TYPE_ID => $dc2Type,
            ]);
    }

    // ===========================================
    // isT2() tests
    // ===========================================

    public function testIsT2ReturnsTrueForT2Item(): void
    {
        $this->setupSabreInventionChain();

        $this->assertTrue($this->service->isT2(self::SABRE_TYPE_ID));
    }

    public function testIsT2ReturnsFalseForT1Item(): void
    {
        // T1 item: manufacturing blueprint exists but no invention path
        $t1Manufacturing = $this->createProduct(586, IndustryActivityType::Manufacturing->value, 587, 1);

        $this->activityProductRepository
            ->method('findBlueprintForProduct')
            ->willReturnCallback(function (int $productTypeId, int $activityId) use ($t1Manufacturing): ?IndustryActivityProduct {
                if ($productTypeId === 587 && $activityId === IndustryActivityType::Manufacturing->value) {
                    return $t1Manufacturing;
                }
                // No invention product for blueprint 586
                return null;
            });

        $this->assertFalse($this->service->isT2(587));
    }

    public function testIsT2ReturnsFalseForNonManufacturedItem(): void
    {
        // Item with no manufacturing blueprint at all
        $this->activityProductRepository
            ->method('findBlueprintForProduct')
            ->willReturn(null);

        $this->assertFalse($this->service->isT2(34)); // Tritanium
    }

    // ===========================================
    // getInventionData() tests
    // ===========================================

    public function testGetInventionDataReturnsCorrectChain(): void
    {
        $this->setupSabreInventionChain(0.30, 10);

        $data = $this->service->getInventionData(self::SABRE_TYPE_ID);

        $this->assertNotNull($data);
        $this->assertSame(self::THRASHER_BLUEPRINT_ID, $data['t1BlueprintTypeId']);
        $this->assertSame(self::SABRE_BLUEPRINT_ID, $data['t2BlueprintTypeId']);
        $this->assertSame(0.30, $data['probability']);
        $this->assertSame(10, $data['baseRuns']);
        $this->assertCount(2, $data['materials']);
        $this->assertSame(self::DATACORE_1_TYPE_ID, $data['materials'][0]['typeId']);
        $this->assertSame('Datacore - Mechanical Engineering', $data['materials'][0]['typeName']);
        $this->assertSame(2, $data['materials'][0]['quantity']);
        $this->assertSame(102000, $data['inventionTime']);
    }

    public function testGetInventionDataReturnsNullForNonT2(): void
    {
        $this->activityProductRepository
            ->method('findBlueprintForProduct')
            ->willReturn(null);

        $this->assertNull($this->service->getInventionData(34));
    }

    // ===========================================
    // calculateInventionCost() tests
    // ===========================================

    public function testCalculateInventionCostWithoutDecryptor(): void
    {
        $this->setupSabreInventionChain(0.30, 10);

        // Jita prices for datacores
        $this->jitaMarketService
            ->method('getPricesWithFallback')
            ->willReturn([
                self::DATACORE_1_TYPE_ID => 50000.0,
                self::DATACORE_2_TYPE_ID => 80000.0,
            ]);

        // No decryptor price needed
        $this->jitaMarketService
            ->method('getPrice')
            ->willReturn(null);

        // EIV for copy cost: T2 blueprint manufacturing output
        $t2ManufacturingOutput = $this->createProduct(
            self::SABRE_BLUEPRINT_ID,
            IndustryActivityType::Manufacturing->value,
            self::SABRE_TYPE_ID,
            1,
        );
        $this->activityProductRepository
            ->method('findBy')
            ->willReturn([$t2ManufacturingOutput]);

        $this->esiCostIndexService->method('getAdjustedPrice')->willReturn(1000000.0);
        $this->esiCostIndexService
            ->method('getCostIndex')
            ->willReturnCallback(function (int $solarSystemId, string $activity): ?float {
                return match ($activity) {
                    'copying' => 0.01,
                    'invention' => 0.02,
                    default => null,
                };
            });

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510);

        $this->assertNotNull($result);
        $this->assertSame(0.30, $result['baseProbability']);
        $this->assertSame(0.30, $result['effectiveProbability']);
        $this->assertSame(4, $result['expectedAttempts']); // ceil(1 / 0.30) = 4
        $this->assertSame(2, $result['me']); // BASE_INVENTION_ME = 2
        $this->assertSame(4, $result['te']); // BASE_INVENTION_TE = 4
        $this->assertSame(10, $result['runs']); // baseRuns
        $this->assertNull($result['decryptorName']);

        // Verify cost breakdown
        // Datacores: 2*50000 + 2*80000 = 260000
        $this->assertSame(260000.0, $result['costBreakdown']['datacores']);
        $this->assertSame(0.0, $result['costBreakdown']['decryptor']);
    }

    public function testCalculateInventionCostWithAccelerantDecryptor(): void
    {
        $this->setupSabreInventionChain(0.30, 10);

        $this->jitaMarketService
            ->method('getPricesWithFallback')
            ->willReturn([
                self::DATACORE_1_TYPE_ID => 50000.0,
                self::DATACORE_2_TYPE_ID => 80000.0,
            ]);

        // Accelerant decryptor price
        $this->jitaMarketService
            ->method('getPrice')
            ->with(self::ACCELERANT_DECRYPTOR_ID)
            ->willReturn(500000.0);

        $t2ManufacturingOutput = $this->createProduct(
            self::SABRE_BLUEPRINT_ID,
            IndustryActivityType::Manufacturing->value,
            self::SABRE_TYPE_ID,
            1,
        );
        $this->activityProductRepository
            ->method('findBy')
            ->willReturn([$t2ManufacturingOutput]);

        $this->esiCostIndexService->method('getAdjustedPrice')->willReturn(1000000.0);
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.01);

        $result = $this->service->calculateInventionCost(
            self::SABRE_TYPE_ID,
            30002510,
            self::ACCELERANT_DECRYPTOR_ID,
        );

        $this->assertNotNull($result);

        // Accelerant: probabilityMultiplier=1.2, meModifier=2, teModifier=10, runModifier=1
        $this->assertSame(0.36, $result['effectiveProbability']); // 0.30 * 1.2
        $this->assertSame(3, $result['expectedAttempts']); // ceil(1 / 0.36) = 3
        $this->assertSame(4, $result['me']); // 2 + 2
        $this->assertSame(14, $result['te']); // 4 + 10
        $this->assertSame(11, $result['runs']); // 10 + 1
        $this->assertSame('Accelerant Decryptor', $result['decryptorName']);
        $this->assertSame(500000.0, $result['costBreakdown']['decryptor']);
    }

    public function testCalculateInventionCostReturnsNullForNonT2(): void
    {
        $this->activityProductRepository
            ->method('findBlueprintForProduct')
            ->willReturn(null);

        $result = $this->service->calculateInventionCost(34, 30002510);

        $this->assertNull($result);
    }

    public function testCalculateInventionCostMultipleDesiredSuccesses(): void
    {
        $this->setupSabreInventionChain(0.30, 10);

        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            self::DATACORE_1_TYPE_ID => 10000.0,
            self::DATACORE_2_TYPE_ID => 10000.0,
        ]);
        $this->jitaMarketService->method('getPrice')->willReturn(null);

        $this->activityProductRepository->method('findBy')->willReturn([]);
        $this->esiCostIndexService->method('getAdjustedPrice')->willReturn(0.0);
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.01);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510, null, 5);

        $this->assertNotNull($result);
        // ceil(5 / 0.30) = 17 expected attempts
        $this->assertSame(17, $result['expectedAttempts']);
    }

    // ===========================================
    // getCopyJobCost() tests
    // ===========================================

    public function testGetCopyJobCostCalculatesCorrectly(): void
    {
        // EIV: adjusted_price * quantity for manufacturing outputs
        $manufacturingOutput = $this->createProduct(
            self::THRASHER_BLUEPRINT_ID,
            IndustryActivityType::Manufacturing->value,
            587, // Thrasher
            1,
        );
        $this->activityProductRepository
            ->method('findBy')
            ->willReturn([$manufacturingOutput]);

        $this->esiCostIndexService
            ->method('getAdjustedPrice')
            ->with(587)
            ->willReturn(500000.0);

        $this->esiCostIndexService
            ->method('getCostIndex')
            ->with(30002510, 'copying')
            ->willReturn(0.05);

        // 0.02 * 500000 * 3 runs * 0.05 * 1.0 (no tax) = 1500.0
        $cost = $this->service->getCopyJobCost(self::THRASHER_BLUEPRINT_ID, 3, 30002510);

        $this->assertEqualsWithDelta(1500.0, $cost, 0.01);
    }

    public function testGetCopyJobCostWithFacilityTax(): void
    {
        $manufacturingOutput = $this->createProduct(
            self::THRASHER_BLUEPRINT_ID,
            IndustryActivityType::Manufacturing->value,
            587,
            1,
        );
        $this->activityProductRepository->method('findBy')->willReturn([$manufacturingOutput]);
        $this->esiCostIndexService->method('getAdjustedPrice')->willReturn(500000.0);
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.05);

        // With 10% facility tax
        // 0.02 * 500000 * 1 * 0.05 * 1.10 = 550.0
        $cost = $this->service->getCopyJobCost(self::THRASHER_BLUEPRINT_ID, 1, 30002510, 10.0);

        $this->assertEqualsWithDelta(550.0, $cost, 0.01);
    }

    public function testGetCopyJobCostReturnsZeroWhenNoCostIndex(): void
    {
        $this->activityProductRepository->method('findBy')->willReturn([]);
        $this->esiCostIndexService->method('getAdjustedPrice')->willReturn(null);
        $this->esiCostIndexService->method('getCostIndex')->willReturn(null);

        $cost = $this->service->getCopyJobCost(self::THRASHER_BLUEPRINT_ID, 1, 30002510);

        $this->assertSame(0.0, $cost);
    }

    // ===========================================
    // buildDecryptorOptions() tests
    // ===========================================

    public function testBuildDecryptorOptionsReturnsAllOptions(): void
    {
        $this->setupSabreInventionChain(0.30, 10);

        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            self::DATACORE_1_TYPE_ID => 10000.0,
            self::DATACORE_2_TYPE_ID => 10000.0,
        ]);
        $this->jitaMarketService->method('getPrice')->willReturn(100000.0);

        $this->activityProductRepository->method('findBy')->willReturn([]);
        $this->esiCostIndexService->method('getAdjustedPrice')->willReturn(0.0);
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.01);

        $options = $this->service->buildDecryptorOptions(self::SABRE_TYPE_ID, 30002510);

        // 1 "No decryptor" + 8 decryptors = 9 total
        $this->assertCount(9, $options);

        // First is "No Decryptor"
        $this->assertNull($options[0]['decryptorTypeId']);
        $this->assertSame('No Decryptor', $options[0]['decryptorName']);
        $this->assertSame(2, $options[0]['me']); // BASE_INVENTION_ME
        $this->assertSame(4, $options[0]['te']); // BASE_INVENTION_TE
        $this->assertSame(10, $options[0]['runs']); // baseRuns

        // Verify Accelerant Decryptor option is present
        $accelerant = null;
        foreach ($options as $option) {
            if ($option['decryptorTypeId'] === self::ACCELERANT_DECRYPTOR_ID) {
                $accelerant = $option;
                break;
            }
        }
        $this->assertNotNull($accelerant);
        $this->assertSame(4, $accelerant['me']); // 2 + 2
        $this->assertSame(14, $accelerant['te']); // 4 + 10
        $this->assertSame(11, $accelerant['runs']); // 10 + 1
    }

    public function testBuildDecryptorOptionsMEModifiers(): void
    {
        $this->setupSabreInventionChain(0.30, 10);

        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            self::DATACORE_1_TYPE_ID => 0.0,
            self::DATACORE_2_TYPE_ID => 0.0,
        ]);
        $this->jitaMarketService->method('getPrice')->willReturn(0.0);
        $this->activityProductRepository->method('findBy')->willReturn([]);
        $this->esiCostIndexService->method('getAdjustedPrice')->willReturn(0.0);
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.0);

        $options = $this->service->buildDecryptorOptions(self::SABRE_TYPE_ID, 30002510);

        // Build a lookup by decryptorTypeId
        $optionMap = [];
        foreach ($options as $opt) {
            $optionMap[$opt['decryptorTypeId'] ?? 'none'] = $opt;
        }

        // Attainment: meModifier=-1 => ME=1, teModifier=4 => TE=8, runModifier=4 => runs=14
        $attainment = $optionMap[self::ATTAINMENT_DECRYPTOR_ID];
        $this->assertSame(1, $attainment['me']);
        $this->assertSame(8, $attainment['te']);
        $this->assertSame(14, $attainment['runs']);

        // Augmentation: meModifier=-2 => ME=0, teModifier=2 => TE=6, runModifier=9 => runs=19
        $augmentation = $optionMap[self::AUGMENTATION_DECRYPTOR_ID];
        $this->assertSame(0, $augmentation['me']);
        $this->assertSame(6, $augmentation['te']);
        $this->assertSame(19, $augmentation['runs']);
    }

    // ===========================================
    // getDecryptorOptions() returns static data
    // ===========================================

    public function testGetDecryptorOptionsReturnsAllEightDecryptors(): void
    {
        $options = $this->service->getDecryptorOptions();

        $this->assertCount(8, $options);
        $this->assertArrayHasKey(self::ACCELERANT_DECRYPTOR_ID, $options);
        $this->assertSame('Accelerant Decryptor', $options[self::ACCELERANT_DECRYPTOR_ID]['name']);
        $this->assertSame(1.2, $options[self::ACCELERANT_DECRYPTOR_ID]['probabilityMultiplier']);
    }
}
