<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Enum\IndustryActivityType;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\InventionService;
use App\Service\JitaMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventionService::class)]
class InventionServiceTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private IndustryActivityProductRepository&MockObject $activityProductRepository;
    private IndustryActivityMaterialRepository&MockObject $activityMaterialRepository;
    private InvTypeRepository&MockObject $invTypeRepository;
    private JitaMarketService&MockObject $jitaMarketService;
    private EsiCostIndexService&MockObject $esiCostIndexService;
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
        $this->invTypeRepository = $this->createMock(InvTypeRepository::class);
        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->esiCostIndexService = $this->createMock(EsiCostIndexService::class);

        $this->service = new InventionService(
            $this->activityProductRepository,
            $this->activityMaterialRepository,
            $this->invTypeRepository,
            $this->jitaMarketService,
            $this->esiCostIndexService,
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

    /**
     * Set up a standard T2 invention chain for the Sabre (used by most tests).
     */
    private function setupSabreInventionChain(?float $baseProbability = 0.30, int $baseRuns = 10): void
    {
        $this->setupInventionChain(
            self::SABRE_TYPE_ID,
            $baseProbability,
            $baseRuns,
            [self::DATACORE_1_TYPE_ID => 2, self::DATACORE_2_TYPE_ID => 2],
        );
    }

    private function setupInventionChain(int $t2ProductTypeId, ?float $baseProbability, int $baseRuns, array $datacoreQuantitiesByTypeId): void
    {
        $datacoreLines = [];
        foreach ($datacoreQuantitiesByTypeId as $datacoreTypeId => $quantity) {
            $datacoreLines[] = [$datacoreTypeId, $quantity];
        }

        $this->setupInventionChainWithDatacoreLines($t2ProductTypeId, $baseProbability, $baseRuns, $datacoreLines);
    }

    private function setupInventionChainWithDatacoreLines(int $t2ProductTypeId, ?float $baseProbability, int $baseRuns, array $datacoreLines): void
    {
        $t2Manufacturing = $this->createProduct(
            self::SABRE_BLUEPRINT_ID,
            IndustryActivityType::Manufacturing->value,
            $t2ProductTypeId,
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
            ->willReturnCallback(function (int $productTypeId, int $activityId) use ($t2ProductTypeId, $t2Manufacturing, $inventionProduct): ?IndustryActivityProduct {
                if ($productTypeId === $t2ProductTypeId && $activityId === IndustryActivityType::Manufacturing->value) {
                    return $t2Manufacturing;
                }
                if ($productTypeId === self::SABRE_BLUEPRINT_ID && $activityId === IndustryActivityType::Invention->value) {
                    return $inventionProduct;
                }

                return null;
            });

        $datacores = [];
        foreach ($datacoreLines as [$datacoreTypeId, $quantity]) {
            $datacores[] = $this->createMaterial(
                self::THRASHER_BLUEPRINT_ID,
                IndustryActivityType::Invention->value,
                $datacoreTypeId,
                $quantity,
            );
        }

        $this->activityMaterialRepository
            ->method('findByBlueprintAndActivity')
            ->willReturn($datacores);

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

    private const int RIFTER_TYPE_ID = 587;
    private const int RIFTER_BLUEPRINT_ID = 691;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int FULLERIDES_TYPE_ID = 16679;
    private const int FULLERIDES_REACTION_FORMULA_ID = 17959;

    private function givenT2ProductTypeIdsInTheSde(int ...$t2ProductTypeIds): void
    {
        $this->activityProductRepository
            ->method('findT2ProductTypeIds')
            ->willReturnCallback(static fn (array $productTypeIds): array => array_intersect_key(
                array_fill_keys($t2ProductTypeIds, true),
                array_flip($productTypeIds),
            ));
    }

    #[Group('issue-293')]
    public function testIsT2ReturnsTrueForT2Item(): void
    {
        $this->givenT2ProductTypeIdsInTheSde(self::SABRE_TYPE_ID);

        $this->assertTrue($this->service->isT2(self::SABRE_TYPE_ID));
    }

    public static function productsTheSdeDoesNotDeclareT2(): iterable
    {
        yield 'T1 manufactured product' => [self::RIFTER_TYPE_ID];
        yield 'product without manufacturing blueprint' => [self::TRITANIUM_TYPE_ID];
    }

    #[DataProvider('productsTheSdeDoesNotDeclareT2')]
    #[Group('issue-293')]
    public function testIsT2ReturnsFalseForAProductTheSdeDoesNotDeclareT2(int $productTypeId): void
    {
        $this->givenT2ProductTypeIdsInTheSde(self::SABRE_TYPE_ID);

        $this->assertFalse($this->service->isT2($productTypeId));
    }

    #[Group('issue-293')]
    public function testIsT2OfAProductWithAnInventionChainReadsNoInventionMaterial(): void
    {
        $this->setupSabreInventionChain(null, 10);
        $this->givenT2ProductTypeIdsInTheSde(self::SABRE_TYPE_ID);
        $this->activityMaterialRepository->expects($this->never())->method('findByBlueprintAndActivity');

        $this->assertTrue($this->service->isT2(self::SABRE_TYPE_ID));
    }

    #[Group('issue-293')]
    public function testIdentifyT2ProductsKeepsTheManufacturedProductsTheSdeDeclaresT2(): void
    {
        $this->givenT2ProductTypeIdsInTheSde(self::SABRE_TYPE_ID);

        $this->assertSame([self::SABRE_TYPE_ID => true], $this->service->identifyT2Products([
            ['blueprintTypeId' => self::RIFTER_BLUEPRINT_ID, 'productTypeId' => self::RIFTER_TYPE_ID, 'outputPerRun' => 1, 'activityId' => IndustryActivityType::Manufacturing->value],
            ['blueprintTypeId' => self::SABRE_BLUEPRINT_ID, 'productTypeId' => self::SABRE_TYPE_ID, 'outputPerRun' => 3, 'activityId' => IndustryActivityType::Manufacturing->value],
        ]));
    }

    #[Group('issue-293')]
    public function testIdentifyT2ProductsIgnoresReactionCandidates(): void
    {
        $this->activityProductRepository
            ->method('findT2ProductTypeIds')
            ->willReturnCallback(static fn (array $productTypeIds): array => array_fill_keys($productTypeIds, true));

        $this->assertSame([self::SABRE_TYPE_ID => true], $this->service->identifyT2Products([
            ['blueprintTypeId' => self::FULLERIDES_REACTION_FORMULA_ID, 'productTypeId' => self::FULLERIDES_TYPE_ID, 'outputPerRun' => 3000, 'activityId' => IndustryActivityType::Reaction->value],
            ['blueprintTypeId' => self::SABRE_BLUEPRINT_ID, 'productTypeId' => self::SABRE_TYPE_ID, 'outputPerRun' => 3, 'activityId' => IndustryActivityType::Manufacturing->value],
        ]));
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

    private const array PRICED_DATACORE_LINES = [[self::DATACORE_1_TYPE_ID, 3], [self::DATACORE_2_TYPE_ID, 5]];
    private const array PRICED_INVENTION_MARKET_PRICES = [
        self::DATACORE_1_TYPE_ID => 41300.0,
        self::DATACORE_2_TYPE_ID => 67900.0,
        self::ACCELERANT_DECRYPTOR_ID => 1513000.0,
        self::ATTAINMENT_DECRYPTOR_ID => 2087000.0,
        self::AUGMENTATION_DECRYPTOR_ID => 987000.0,
        self::PARITY_DECRYPTOR_ID => 1234000.0,
    ];
    private const float PRICED_INVENTION_INSTALL_COST = 4270.0;

    public static function inventionCostCrossings(): iterable
    {
        yield 'without decryptor, one BPC' => [null, 0.25, 1, 1, [
            'effectiveProbability' => 0.25, 'expectedAttempts' => 4, 'me' => 2, 'te' => 4, 'runs' => 1,
            'decryptorPrice' => 0.0, 'costPerAttempt' => 467670.0, 'totalCost' => 1870680.0, 'decryptorName' => null,
        ]];
        yield 'Parity decryptor, three BPCs' => [self::PARITY_DECRYPTOR_ID, 0.25, 1, 3, [
            'effectiveProbability' => 0.375, 'expectedAttempts' => 8, 'me' => 3, 'te' => 2, 'runs' => 4,
            'decryptorPrice' => 1234000.0, 'costPerAttempt' => 1701670.0, 'totalCost' => 13613360.0, 'decryptorName' => 'Parity Decryptor',
        ]];
        yield 'Augmentation decryptor, three BPCs' => [self::AUGMENTATION_DECRYPTOR_ID, 0.25, 1, 3, [
            'effectiveProbability' => 0.15, 'expectedAttempts' => 20, 'me' => 0, 'te' => 6, 'runs' => 10,
            'decryptorPrice' => 987000.0, 'costPerAttempt' => 1454670.0, 'totalCost' => 29093400.0, 'decryptorName' => 'Augmentation Decryptor',
        ]];
        yield 'Accelerant decryptor, six BPCs' => [self::ACCELERANT_DECRYPTOR_ID, 0.25, 10, 6, [
            'effectiveProbability' => 0.3, 'expectedAttempts' => 20, 'me' => 4, 'te' => 14, 'runs' => 11,
            'decryptorPrice' => 1513000.0, 'costPerAttempt' => 1980670.0, 'totalCost' => 39613400.0, 'decryptorName' => 'Accelerant Decryptor',
        ]];
        yield 'Attainment decryptor on a lower base probability, nine BPCs' => [self::ATTAINMENT_DECRYPTOR_ID, 0.125, 10, 9, [
            'effectiveProbability' => 0.225, 'expectedAttempts' => 40, 'me' => 1, 'te' => 8, 'runs' => 14,
            'decryptorPrice' => 2087000.0, 'costPerAttempt' => 2554670.0, 'totalCost' => 102186800.0, 'decryptorName' => 'Attainment Decryptor',
        ]];
    }

    #[DataProvider('inventionCostCrossings')]
    public function testInventionCostChargesDatacoresDecryptorAndInstallPerAttemptTimesTheExpectedAttempts(
        ?int $decryptorTypeId,
        float $baseProbability,
        int $baseRuns,
        int $desiredSuccesses,
        array $expected,
    ): void {
        $this->setupPricedSabreInvention($baseProbability, $baseRuns);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510, $decryptorTypeId, $desiredSuccesses);

        $this->assertSame([
            'baseProbability' => $baseProbability,
            'effectiveProbability' => $expected['effectiveProbability'],
            'expectedAttempts' => $expected['expectedAttempts'],
            'me' => $expected['me'],
            'te' => $expected['te'],
            'runs' => $expected['runs'],
            'costPerAttempt' => $expected['costPerAttempt'],
            'totalCost' => $expected['totalCost'],
            'costBreakdown' => [
                'datacores' => 463400.0,
                'decryptor' => $expected['decryptorPrice'],
                'copyCost' => 0.0,
                'inventionInstall' => self::PRICED_INVENTION_INSTALL_COST,
            ],
            'datacores' => [
                ['typeId' => self::DATACORE_1_TYPE_ID, 'typeName' => 'Datacore - Mechanical Engineering', 'quantity' => 3, 'unitPrice' => 41300.0, 'totalPrice' => 123900.0],
                ['typeId' => self::DATACORE_2_TYPE_ID, 'typeName' => 'Datacore - Minmatar Starship Engineering', 'quantity' => 5, 'unitPrice' => 67900.0, 'totalPrice' => 339500.0],
            ],
            'decryptorName' => $expected['decryptorName'],
            'missingPriceTypeIds' => [],
            'missingAdjustedPriceTypeIds' => [],
            'unknownReason' => null,
        ], $result);
    }

    #[Group('issue-7')]
    public function testInventionCostChargesTheCopyEstimateOfTheIndustryCoreOnTheManufacturingMaterialsOfTheT1BlueprintForOneRunInTheRequestedSystemWithTheReceivedTax(): void
    {
        $this->setupPricedSabreInvention(0.25, 1, self::PRICED_INVENTION_MARKET_PRICES, $this->knownInstallEstimate(1100.0));

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, self::AMARR_SOLAR_SYSTEM_ID, null, 1, 10.0);

        $this->assertInventionCostOutcome([
            'expectedAttempts' => 4,
            'costPerAttempt' => 468770.0,
            'totalCost' => 1875080.0,
            'costBreakdown' => ['datacores' => 463400.0, 'decryptor' => 0.0, 'copyCost' => 1100.0, 'inventionInstall' => 4270.0],
            'unknownReason' => null,
        ], $result);
        $this->assertSame(
            [
                [self::THRASHER_MANUFACTURING_MATERIALS, 1, self::AMARR_SOLAR_SYSTEM_ID, 'copying', 10.0],
                [self::SABRE_MANUFACTURING_MATERIALS, 1, self::AMARR_SOLAR_SYSTEM_ID, 'invention', 10.0],
            ],
            $this->installEstimateCalls,
        );
    }

    public static function nonIntegerAttemptRatios(): iterable
    {
        yield 'probability 0.34, one BPC' => [0.34, 1, 3, 1403010.0];
        yield 'probability 0.30, five BPCs' => [0.30, 5, 17, 7950390.0];
    }

    #[Group('issue-18')]
    #[DataProvider('nonIntegerAttemptRatios')]
    public function testExpectedAttemptsAreCurrentlyRoundedUpToTheNextWholeAttemptIssue18(
        float $baseProbability,
        int $desiredSuccesses,
        int $expectedAttempts,
        float $totalCost,
    ): void {
        $this->setupPricedSabreInvention($baseProbability, 1);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510, null, $desiredSuccesses);

        $this->assertInventionCostOutcome([
            'expectedAttempts' => $expectedAttempts,
            'costPerAttempt' => 467670.0,
            'totalCost' => $totalCost,
        ], $result);
    }

    private function setupPricedSabreInvention(
        float $baseProbability,
        int $baseRuns,
        array $marketPrices = self::PRICED_INVENTION_MARKET_PRICES,
        ?array $copyEstimate = null,
    ): void {
        $this->setupInventionChainWithDatacoreLines(self::SABRE_TYPE_ID, $baseProbability, $baseRuns, self::PRICED_DATACORE_LINES);
        $this->stubInventionInstallEstimate($this->knownInstallEstimate(self::PRICED_INVENTION_INSTALL_COST), $copyEstimate ?? $this->knownInstallEstimate(0.0));
        $this->stubMarketPricesWithFallback($marketPrices);
    }

    #[Group('issue-124')]
    public function testExpectedCostPerRunIsTheCostPerAttemptOverTheProbabilityTimesTheRunsOfTheInventedBlueprint(): void
    {
        $this->setupPricedSabreInvention(0.25, 10);

        $result = $this->service->expectedCostPerRun(self::SABRE_TYPE_ID, 30002510);

        $this->assertSame(['cost' => 187068.0, 'unknownReason' => null], $result);
    }

    #[Group('issue-124')]
    public function testExpectedCostPerRunIsUnknownWhenTheSdeHasNoInventionProbability(): void
    {
        $this->setupSabreInventionAt(null, self::DATACORE_LINES_OF_THE_SABRE);
        $this->stubMarketPricesWithFallback([self::DATACORE_1_TYPE_ID => 100.0, self::DATACORE_2_TYPE_ID => 200.0]);

        $result = $this->service->expectedCostPerRun(self::SABRE_TYPE_ID, 30002510);

        $this->assertSame(['cost' => null, 'unknownReason' => self::MISSING_INVENTION_PROBABILITY], $result);
    }

    #[Group('issue-124')]
    public function testExpectedCostPerRunIsUnknownWhenADatacoreHasNoPrice(): void
    {
        $this->setupSabreInventionAt(self::INVENTION_PROBABILITY, self::DATACORE_LINES_OF_THE_SABRE);
        $this->stubMarketPricesWithFallback([self::DATACORE_2_TYPE_ID => 200.0]);

        $result = $this->service->expectedCostPerRun(self::SABRE_TYPE_ID, 30002510);

        $this->assertSame(['cost' => null, 'unknownReason' => self::MISSING_MARKET_PRICE], $result);
    }

    public function testCalculateInventionCostReturnsNullForNonT2(): void
    {
        $this->activityProductRepository
            ->method('findBlueprintForProduct')
            ->willReturn(null);

        $result = $this->service->calculateInventionCost(34, 30002510);

        $this->assertNull($result);
    }

    // ===========================================
    // getCopyJobCost() tests
    // ===========================================

    public function testEstimateCopyJobCostIsTheInstallEstimateOfTheIndustryCoreOnTheManufacturingMaterialsOfTheBlueprint(): void
    {
        $this->stubInventionInstallEstimate($this->knownInstallEstimate(0.0), $this->knownInstallEstimate(110000.0));

        $estimate = $this->service->estimateCopyJobCost(self::THRASHER_BLUEPRINT_ID, 10, 30002510, 5.0);

        $this->assertSame($this->knownInstallEstimate(110000.0), $estimate);
        $this->assertSame([[self::THRASHER_MANUFACTURING_MATERIALS, 10, 30002510, 'copying', 5.0]], $this->installEstimateCalls);
    }

    #[Group('issue-331')]
    public function testEstimateCopyJobCostIsUnknownWithTheMissingAdjustedPriceReasonWhenAMaterialHasNoAdjustedPrice(): void
    {
        $this->stubInventionInstallEstimate($this->knownInstallEstimate(0.0), $this->unknownInstallEstimate(self::MISSING_ADJUSTED_PRICE, [self::TRITANIUM_TYPE_ID]));

        $estimate = $this->service->estimateCopyJobCost(self::THRASHER_BLUEPRINT_ID, 1, 30002510);

        $this->assertSame(['cost' => null, 'unknownReason' => self::MISSING_ADJUSTED_PRICE, 'missingAdjustedPriceTypeIds' => [self::TRITANIUM_TYPE_ID]], $estimate);
    }

    #[Group('issue-331')]
    public function testEstimateCopyJobCostIsUnknownWithTheMissingCostIndexReasonWhenNoCostIndex(): void
    {
        $this->stubInventionInstallEstimate($this->knownInstallEstimate(0.0), $this->unknownInstallEstimate(self::MISSING_COST_INDEX));

        $estimate = $this->service->estimateCopyJobCost(self::THRASHER_BLUEPRINT_ID, 1, 30002510);

        $this->assertSame(['cost' => null, 'unknownReason' => self::MISSING_COST_INDEX, 'missingAdjustedPriceTypeIds' => []], $estimate);
    }

    // ===========================================
    // Decryptor modifiers per typeId (issue #70)
    // Reference: SDE dgmTypeAttributes 1112 (probability), 1113 (ME), 1114 (TE), 1124 (max runs)
    // ===========================================

    /**
     * @return iterable<string, array{int, string, float, int, int, int}>
     */
    public static function decryptorModifiersFromSde(): iterable
    {
        yield 'Accelerant' => [34201, 'Accelerant Decryptor', 1.2, 2, 10, 1];
        yield 'Attainment' => [34202, 'Attainment Decryptor', 1.8, -1, 4, 4];
        yield 'Augmentation' => [34203, 'Augmentation Decryptor', 0.6, -2, 2, 9];
        yield 'Parity' => [34204, 'Parity Decryptor', 1.5, 1, -2, 3];
        yield 'Process' => [34205, 'Process Decryptor', 1.1, 3, 6, 0];
        yield 'Symmetry' => [34206, 'Symmetry Decryptor', 1.0, 1, 8, 2];
        yield 'Optimized Attainment' => [34207, 'Optimized Attainment Decryptor', 1.9, 1, -2, 2];
        yield 'Optimized Augmentation' => [34208, 'Optimized Augmentation Decryptor', 0.9, 2, 0, 7];
    }

    #[DataProvider('decryptorModifiersFromSde')]
    public function testInventionCostAppliesSdeModifiersOfEachDecryptorTypeId(
        int $decryptorTypeId,
        string $decryptorName,
        float $probabilityMultiplier,
        int $meModifier,
        int $teModifier,
        int $runModifier,
    ): void {
        $baseProbability = 0.30;
        $baseRuns = 10;
        $this->setupSabreInventionChain($baseProbability, $baseRuns);
        $this->stubZeroCostsExceptDecryptorPrices([]);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510, $decryptorTypeId);

        $this->assertSame($decryptorName, $result['decryptorName']);
        $this->assertSame(InventionService::BASE_INVENTION_ME + $meModifier, $result['me']);
        $this->assertSame(InventionService::BASE_INVENTION_TE + $teModifier, $result['te']);
        $this->assertSame($baseRuns + $runModifier, $result['runs']);
        $this->assertEqualsWithDelta($baseProbability * $probabilityMultiplier, $result['effectiveProbability'], 1e-12);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function decryptorTypeIds(): iterable
    {
        foreach (self::decryptorModifiersFromSde() as $label => $row) {
            yield $label => [$row[0]];
        }
    }

    #[DataProvider('decryptorTypeIds')]
    public function testInventionCostChargesThePriceOfTheDecryptorOwnTypeId(int $decryptorTypeId): void
    {
        $this->setupSabreInventionChain(0.30, 10);
        $decryptorPrices = [
            34201 => 1_100_000.0,
            34202 => 1_200_000.0,
            34203 => 1_300_000.0,
            34204 => 1_400_000.0,
            34205 => 1_500_000.0,
            34206 => 1_600_000.0,
            34207 => 1_700_000.0,
            34208 => 1_800_000.0,
        ];
        $this->stubZeroCostsExceptDecryptorPrices($decryptorPrices);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510, $decryptorTypeId);

        $this->assertSame($decryptorPrices[$decryptorTypeId], $result['costBreakdown']['decryptor']);
        $this->assertSame($decryptorPrices[$decryptorTypeId], $result['costPerAttempt']);
    }

    /**
     * @param array<int, float> $decryptorPrices
     */
    private function stubZeroCostsExceptDecryptorPrices(array $decryptorPrices): void
    {
        $this->jitaMarketService->method('getPricesWithFallback')->willReturnCallback(
            static fn (array $typeIds): array => array_combine(
                $typeIds,
                array_map(static fn (int $typeId): float => $decryptorPrices[$typeId] ?? 0.0, $typeIds),
            ),
        );
        $this->stubInventionInstallEstimate($this->knownInstallEstimate(0.0));
        $this->activityProductRepository->method('findBy')->willReturn([]);
        $this->esiCostIndexService->method('getAdjustedPrice')->willReturn(0.0);
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.0);
    }

    // ===========================================
    // Missing invention probability in the SDE (issue #74)
    // The success probability is business data: when the SDE does not carry it,
    // the invention cost is unknown. It must never become 0 (division by zero)
    // nor a plausible-looking cost.
    // ===========================================

    private const MISSING_INVENTION_PROBABILITY = 'missing_invention_probability';

    /**
     * Datacores 2 x 50 000 + 2 x 80 000 = 260 000 ISK per attempt, decryptors at 500 000 ISK,
     * no cost index (copy and invention install cost 0), so the cost per attempt stays exact.
     */
    private function stubKnownCostsPerAttempt(): void
    {
        $this->jitaMarketService
            ->method('getPricesWithFallback')
            ->willReturn([
                self::DATACORE_1_TYPE_ID => 50000.0,
                self::DATACORE_2_TYPE_ID => 80000.0,
            ] + array_fill_keys(array_column(iterator_to_array(self::decryptorModifiersFromSde()), 0), 500000.0));
        $this->stubInventionInstallEstimate($this->knownInstallEstimate(0.0));
        $this->activityProductRepository->method('findBy')->willReturn([]);
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.0);
    }

    public function testGetInventionDataKeepsMissingProbabilityUnknownInsteadOfZero(): void
    {
        $this->setupSabreInventionChain(null, 10);

        $inventionData = $this->service->getInventionData(self::SABRE_TYPE_ID);

        $this->assertNotNull($inventionData);
        $this->assertNull($inventionData['probability']);
        $this->assertSame(10, $inventionData['baseRuns']);
    }

    public function testCalculateInventionCostWithMissingProbabilityReturnsUnknownCostWithoutDivisionByZero(): void
    {
        $this->setupSabreInventionChain(null, 10);
        $this->stubKnownCostsPerAttempt();

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510);

        $this->assertNotNull($result);
        $this->assertNull($result['baseProbability']);
        $this->assertNull($result['effectiveProbability']);
        $this->assertNull($result['expectedAttempts']);
        $this->assertNull($result['totalCost']);
        $this->assertSame(self::MISSING_INVENTION_PROBABILITY, $result['unknownReason']);
        // Known parts of the partial result are still exposed
        $this->assertSame(260000.0, $result['costPerAttempt']);
        $this->assertSame(260000.0, $result['costBreakdown']['datacores']);
        $this->assertSame(2, $result['me']);
        $this->assertSame(4, $result['te']);
        $this->assertSame(10, $result['runs']);
    }

    public function testCalculateInventionCostWithMissingProbabilityAndDecryptorReturnsUnknownCost(): void
    {
        $this->setupSabreInventionChain(null, 10);
        $this->stubKnownCostsPerAttempt();

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510, self::ACCELERANT_DECRYPTOR_ID);

        $this->assertNotNull($result);
        $this->assertNull($result['effectiveProbability']);
        $this->assertNull($result['expectedAttempts']);
        $this->assertNull($result['totalCost']);
        $this->assertSame(self::MISSING_INVENTION_PROBABILITY, $result['unknownReason']);
        // 260 000 datacores + 500 000 decryptor
        $this->assertSame(760000.0, $result['costPerAttempt']);
        $this->assertSame(11, $result['runs']); // 10 base runs + 1 (Accelerant)
    }

    public function testCalculateInventionCostWithKnownProbabilityHasNoUnknownReason(): void
    {
        $this->setupSabreInventionChain(0.30, 10);
        $this->stubKnownCostsPerAttempt();

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('unknownReason', $result);
        $this->assertNull($result['unknownReason']);
        $this->assertSame(4, $result['expectedAttempts']); // ceil(1 / 0.30)
        $this->assertSame(1040000.0, $result['totalCost']); // 4 attempts x 260 000
    }

    private const int THRASHER_TYPE_ID = 16242;
    private const float COPYING_COST_INDEX = 0.05;
    private const array INVENTION_SDE_TYPE_NAMES = [
        self::SABRE_TYPE_ID => 'Sabre',
        self::SABRE_BLUEPRINT_ID => 'Sabre Blueprint',
        self::THRASHER_TYPE_ID => 'Thrasher',
        self::THRASHER_BLUEPRINT_ID => 'Thrasher Blueprint',
        self::DATACORE_1_TYPE_ID => 'Datacore - Mechanical Engineering',
        self::DATACORE_2_TYPE_ID => 'Datacore - Minmatar Starship Engineering',
    ];

    #[Group('issue-115')]
    public function testDatacoreMissingFromTheSdeHasAnUnknownNameInTheInventionDataWithUnchangedQuantity(): void
    {
        $this->setupInventionChain(self::SABRE_TYPE_ID, 0.30, 10, [self::DATACORE_1_TYPE_ID => 2, self::ROBOTICS_TYPE_ID => 3]);

        $inventionData = $this->inventionServiceOnSdeKnowing(self::INVENTION_SDE_TYPE_NAMES)->getInventionData(self::SABRE_TYPE_ID);

        $this->assertNotNull($inventionData);
        $this->assertSame([
            ['typeId' => self::DATACORE_1_TYPE_ID, 'typeName' => 'Datacore - Mechanical Engineering', 'quantity' => 2],
            ['typeId' => self::ROBOTICS_TYPE_ID, 'typeName' => null, 'quantity' => 3],
        ], $inventionData['materials']);
    }

    #[Group('issue-115')]
    public function testDatacoreMissingFromTheSdeHasAnUnknownNameInTheInventionCostWithUnchangedPrices(): void
    {
        $this->setupInventionChain(self::SABRE_TYPE_ID, 0.30, 10, [self::DATACORE_1_TYPE_ID => 2, self::ROBOTICS_TYPE_ID => 3]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([
            self::DATACORE_1_TYPE_ID => 50000.0,
            self::ROBOTICS_TYPE_ID => 70000.0,
        ]);
        $this->stubInventionInstallEstimate($this->knownInstallEstimate(0.0));
        $this->activityProductRepository->method('findBy')->willReturn([]);
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.0);

        $result = $this->inventionServiceOnSdeKnowing(self::INVENTION_SDE_TYPE_NAMES)->calculateInventionCost(self::SABRE_TYPE_ID, 30002510);

        $this->assertNotNull($result);
        $this->assertSame([
            ['typeId' => self::DATACORE_1_TYPE_ID, 'typeName' => 'Datacore - Mechanical Engineering', 'quantity' => 2, 'unitPrice' => 50000.0, 'totalPrice' => 100000.0],
            ['typeId' => self::ROBOTICS_TYPE_ID, 'typeName' => null, 'quantity' => 3, 'unitPrice' => 70000.0, 'totalPrice' => 210000.0],
        ], $result['datacores']);
        $this->assertSame(310000.0, $result['costPerAttempt']);
        $this->assertSame(1240000.0, $result['totalCost']);
    }

    private function inventionServiceOnSdeKnowing(array $typeNamesByTypeId): InventionService
    {
        $invTypeRepository = $this->sdeTypeRepositoryKnowing($typeNamesByTypeId);

        return new InventionService(
            $this->activityProductRepository,
            $this->activityMaterialRepository,
            $invTypeRepository,
            $this->jitaMarketService,
            $this->esiCostIndexService,
        );
    }

    private const string MISSING_MARKET_PRICE = 'missing_market_price';
    private const int PARITY_DECRYPTOR_ID = 34204;
    private const float INVENTION_PROBABILITY = 0.34;
    private const array DATACORE_LINES_OF_THE_SABRE = [[self::DATACORE_1_TYPE_ID, 2], [self::DATACORE_2_TYPE_ID, 3]];
    private const array DATACORE_PRICES = [self::DATACORE_1_TYPE_ID => 100.0, self::DATACORE_2_TYPE_ID => 200.0];
    private const float ACCELERANT_PRICE = 50000.0;

    private const array SABRE_MANUFACTURING_MATERIALS = [
        ['materialTypeId' => 11399, 'quantity' => 3],
        ['materialTypeId' => 11478, 'quantity' => 7],
    ];
    private const int MORPHITE_TYPE_ID = 11399;
    private const int HYPERSYNAPTIC_FIBERS_TYPE_ID = 11478;
    private const string MISSING_COST_INDEX = 'missing_cost_index';
    private const string MISSING_ADJUSTED_PRICE = 'missing_adjusted_price';

    private array $requestedPriceTypeIdsPerCall = [];
    private array $installEstimateCalls = [];

    #[Group('issue-8')]
    public function testInventionCostWithEveryPriceKnownHasNoMissingPriceTypeIds(): void
    {
        $this->setupSabreInventionAt(self::INVENTION_PROBABILITY, self::DATACORE_LINES_OF_THE_SABRE);
        $this->stubMarketPricesWithFallback(self::DATACORE_PRICES);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510);

        $this->assertInventionCostOutcome([
            'expectedAttempts' => 3,
            'costPerAttempt' => 2300.0,
            'totalCost' => 6900.0,
            'costBreakdown' => ['datacores' => 800.0, 'decryptor' => 0.0, 'copyCost' => 1000.0, 'inventionInstall' => 500.0],
            'missingPriceTypeIds' => [],
            'unknownReason' => null,
        ], $result);
    }

    #[Group('issue-8')]
    public function testInventionCostChargesTheDecryptorPriceReadWithFallback(): void
    {
        $this->setupSabreInventionAt(self::INVENTION_PROBABILITY, self::DATACORE_LINES_OF_THE_SABRE);
        $this->stubMarketPricesWithFallback(self::DATACORE_PRICES + [self::ACCELERANT_DECRYPTOR_ID => self::ACCELERANT_PRICE]);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510, self::ACCELERANT_DECRYPTOR_ID);

        $this->assertInventionCostOutcome([
            'expectedAttempts' => 3,
            'costPerAttempt' => 52300.0,
            'totalCost' => 156900.0,
            'costBreakdown' => ['datacores' => 800.0, 'decryptor' => 50000.0, 'copyCost' => 1000.0, 'inventionInstall' => 500.0],
            'missingPriceTypeIds' => [],
            'unknownReason' => null,
        ], $result);
    }

    #[Group('issue-8')]
    public function testInventionCostIsUnknownWhenTheDecryptorHasNoPrice(): void
    {
        $this->setupSabreInventionAt(self::INVENTION_PROBABILITY, self::DATACORE_LINES_OF_THE_SABRE);
        $this->stubMarketPricesWithFallback(self::DATACORE_PRICES);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510, self::ACCELERANT_DECRYPTOR_ID);

        $this->assertInventionCostOutcome([
            'costPerAttempt' => null,
            'totalCost' => null,
            'costBreakdown' => ['datacores' => 800.0, 'decryptor' => null, 'copyCost' => 1000.0, 'inventionInstall' => 500.0],
            'missingPriceTypeIds' => [self::ACCELERANT_DECRYPTOR_ID],
            'unknownReason' => self::MISSING_MARKET_PRICE,
        ], $result);
    }

    #[Group('issue-8')]
    public function testInventionCostIsUnknownWhenADatacoreHasNoPrice(): void
    {
        $this->setupSabreInventionAt(self::INVENTION_PROBABILITY, self::DATACORE_LINES_OF_THE_SABRE);
        $this->stubMarketPricesWithFallback([self::DATACORE_2_TYPE_ID => 200.0]);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510);

        $this->assertInventionCostOutcome([
            'costPerAttempt' => null,
            'totalCost' => null,
            'costBreakdown' => ['datacores' => null, 'decryptor' => 0.0, 'copyCost' => 1000.0, 'inventionInstall' => 500.0],
            'datacores' => [
                ['typeId' => self::DATACORE_1_TYPE_ID, 'typeName' => 'Datacore - Mechanical Engineering', 'quantity' => 2, 'unitPrice' => null, 'totalPrice' => null],
                ['typeId' => self::DATACORE_2_TYPE_ID, 'typeName' => 'Datacore - Minmatar Starship Engineering', 'quantity' => 3, 'unitPrice' => 200.0, 'totalPrice' => 600.0],
            ],
            'missingPriceTypeIds' => [self::DATACORE_1_TYPE_ID],
            'unknownReason' => self::MISSING_MARKET_PRICE,
        ], $result);
    }

    #[Group('issue-8')]
    public function testMissingInventionProbabilityTakesPrecedenceOverAMissingDatacorePrice(): void
    {
        $this->setupSabreInventionAt(null, self::DATACORE_LINES_OF_THE_SABRE);
        $this->stubMarketPricesWithFallback([self::DATACORE_2_TYPE_ID => 200.0]);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510);

        $this->assertInventionCostOutcome([
            'costPerAttempt' => null,
            'totalCost' => null,
            'missingPriceTypeIds' => [self::DATACORE_1_TYPE_ID],
            'unknownReason' => self::MISSING_INVENTION_PROBABILITY,
        ], $result);
    }

    #[Group('issue-8')]
    public function testInventionCostChargesADecryptorAbsentFromTheSyncedOrderBookButPricedOnDemand(): void
    {
        $this->setupSabreInventionAt(self::INVENTION_PROBABILITY, self::DATACORE_LINES_OF_THE_SABRE);
        $this->jitaMarketService->method('getPrice')->willReturn(null);
        $this->stubMarketPricesWithFallback(self::DATACORE_PRICES + [self::ACCELERANT_DECRYPTOR_ID => self::ACCELERANT_PRICE]);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510, self::ACCELERANT_DECRYPTOR_ID);

        $this->assertInventionCostOutcome([
            'costPerAttempt' => 52300.0,
            'totalCost' => 156900.0,
            'missingPriceTypeIds' => [],
            'unknownReason' => null,
        ], $result);
    }

    #[Group('issue-8')]
    public function testDatacoresAndDecryptorArePricedByASingleRequestWithFallback(): void
    {
        $this->setupSabreInventionAt(self::INVENTION_PROBABILITY, self::DATACORE_LINES_OF_THE_SABRE);
        $this->stubMarketPricesWithFallback(self::DATACORE_PRICES + [self::ACCELERANT_DECRYPTOR_ID => self::ACCELERANT_PRICE]);

        $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510, self::ACCELERANT_DECRYPTOR_ID);

        $this->assertSame(
            [[self::DATACORE_2_TYPE_ID, self::DATACORE_1_TYPE_ID, self::ACCELERANT_DECRYPTOR_ID]],
            array_map(static function (array $typeIds): array {
                $typeIds = array_values(array_unique($typeIds));
                sort($typeIds);

                return $typeIds;
            }, $this->requestedPriceTypeIdsPerCall),
        );
    }

    #[Group('issue-8')]
    public function testDatacoreWithoutPriceListedTwiceIsReportedOnceAndMissingPriceTypeIdsAreSorted(): void
    {
        $this->setupSabreInventionAt(self::INVENTION_PROBABILITY, [
            [self::DATACORE_1_TYPE_ID, 2],
            [self::DATACORE_2_TYPE_ID, 3],
            [self::DATACORE_1_TYPE_ID, 1],
        ]);
        $this->stubMarketPricesWithFallback([self::ACCELERANT_DECRYPTOR_ID => self::ACCELERANT_PRICE]);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510, self::ACCELERANT_DECRYPTOR_ID);

        $this->assertInventionCostOutcome([
            'costPerAttempt' => null,
            'totalCost' => null,
            'missingPriceTypeIds' => [self::DATACORE_2_TYPE_ID, self::DATACORE_1_TYPE_ID],
            'unknownReason' => self::MISSING_MARKET_PRICE,
        ], $result);
    }

    #[Group('issue-8')]
    public function testDatacorePricedAtZeroIsAKnownPrice(): void
    {
        $this->setupSabreInventionAt(self::INVENTION_PROBABILITY, self::DATACORE_LINES_OF_THE_SABRE);
        $this->stubMarketPricesWithFallback([self::DATACORE_1_TYPE_ID => 100.0, self::DATACORE_2_TYPE_ID => 0.0]);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510);

        $this->assertInventionCostOutcome([
            'costPerAttempt' => 1700.0,
            'totalCost' => 5100.0,
            'missingPriceTypeIds' => [],
            'unknownReason' => null,
        ], $result);
    }

    #[Group('issue-8')]
    public function testUnknownDecryptorTypeIdIsIgnoredAndNotPriced(): void
    {
        $unknownDecryptorTypeId = 99999;
        $this->setupSabreInventionAt(self::INVENTION_PROBABILITY, self::DATACORE_LINES_OF_THE_SABRE);
        $this->stubMarketPricesWithFallback(self::DATACORE_PRICES);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510, $unknownDecryptorTypeId);

        $this->assertInventionCostOutcome([
            'decryptorName' => null,
            'expectedAttempts' => 3,
            'runs' => 10,
            'costPerAttempt' => 2300.0,
            'totalCost' => 6900.0,
            'costBreakdown' => ['datacores' => 800.0, 'decryptor' => 0.0, 'copyCost' => 1000.0, 'inventionInstall' => 500.0],
            'missingPriceTypeIds' => [],
            'unknownReason' => null,
        ], $result);
        $this->assertSame([[self::DATACORE_1_TYPE_ID, self::DATACORE_2_TYPE_ID]], $this->requestedPriceTypeIdsPerCall);
    }

    #[Group('issue-7')]
    public function testInventionInstallIsTheEstimateOfTheIndustryCoreAddedToTheCostPerAttempt(): void
    {
        $this->setupSabreInventionAt(self::INVENTION_PROBABILITY, self::DATACORE_LINES_OF_THE_SABRE, $this->knownInstallEstimate(500.0));
        $this->stubMarketPricesWithFallback(self::DATACORE_PRICES);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510);

        $this->assertInventionCostOutcome([
            'expectedAttempts' => 3,
            'costPerAttempt' => 2300.0,
            'totalCost' => 6900.0,
            'costBreakdown' => ['datacores' => 800.0, 'decryptor' => 0.0, 'copyCost' => 1000.0, 'inventionInstall' => 500.0],
            'missingAdjustedPriceTypeIds' => [],
            'unknownReason' => null,
        ], $result);
        $this->assertCount(1, $this->installEstimateCallsFor('invention'));
    }

    #[Group('issue-7')]
    public function testInventionInstallIsEstimatedOnTheManufacturingMaterialsOfTheT2BlueprintForOneRunInTheRequestedSystemWithTheReceivedTax(): void
    {
        $this->setupSabreInventionAt(self::INVENTION_PROBABILITY, self::DATACORE_LINES_OF_THE_SABRE);
        $this->stubMarketPricesWithFallback(self::DATACORE_PRICES);

        $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510, null, 1, 10.0);

        $this->assertSame(
            [[self::SABRE_MANUFACTURING_MATERIALS, 1, 30002510, 'invention', 10.0]],
            $this->installEstimateCallsFor('invention'),
        );
    }

    #[Group('issue-7')]
    public function testInventionCostIsUnknownWhenTheInventionInstallHasNoCostIndex(): void
    {
        $this->setupSabreInventionAt(self::INVENTION_PROBABILITY, self::DATACORE_LINES_OF_THE_SABRE, $this->unknownInstallEstimate(self::MISSING_COST_INDEX));
        $this->stubMarketPricesWithFallback(self::DATACORE_PRICES);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510);

        $this->assertInventionCostOutcome([
            'costPerAttempt' => null,
            'totalCost' => null,
            'costBreakdown' => ['datacores' => 800.0, 'decryptor' => 0.0, 'copyCost' => 1000.0, 'inventionInstall' => null],
            'missingPriceTypeIds' => [],
            'missingAdjustedPriceTypeIds' => [],
            'unknownReason' => self::MISSING_COST_INDEX,
        ], $result);
    }

    #[Group('issue-7')]
    public function testInventionCostIsUnknownWhenAManufacturingMaterialHasNoAdjustedPrice(): void
    {
        $this->setupSabreInventionAt(
            self::INVENTION_PROBABILITY,
            self::DATACORE_LINES_OF_THE_SABRE,
            $this->unknownInstallEstimate(self::MISSING_ADJUSTED_PRICE, [self::MORPHITE_TYPE_ID, self::HYPERSYNAPTIC_FIBERS_TYPE_ID]),
        );
        $this->stubMarketPricesWithFallback(self::DATACORE_PRICES);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510);

        $this->assertInventionCostOutcome([
            'costPerAttempt' => null,
            'totalCost' => null,
            'costBreakdown' => ['datacores' => 800.0, 'decryptor' => 0.0, 'copyCost' => 1000.0, 'inventionInstall' => null],
            'missingPriceTypeIds' => [],
            'missingAdjustedPriceTypeIds' => [self::MORPHITE_TYPE_ID, self::HYPERSYNAPTIC_FIBERS_TYPE_ID],
            'unknownReason' => self::MISSING_ADJUSTED_PRICE,
        ], $result);
    }

    #[Group('issue-7')]
    public function testMissingInventionProbabilityTakesPrecedenceOverAnUnknownInventionInstall(): void
    {
        $this->setupSabreInventionAt(null, self::DATACORE_LINES_OF_THE_SABRE, $this->unknownInstallEstimate(self::MISSING_COST_INDEX));
        $this->stubMarketPricesWithFallback(self::DATACORE_PRICES);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510);

        $this->assertInventionCostOutcome([
            'costPerAttempt' => null,
            'totalCost' => null,
            'unknownReason' => self::MISSING_INVENTION_PROBABILITY,
        ], $result);
    }

    #[Group('issue-7')]
    public function testMissingMarketPriceTakesPrecedenceOverAnUnknownInventionInstall(): void
    {
        $this->setupSabreInventionAt(self::INVENTION_PROBABILITY, self::DATACORE_LINES_OF_THE_SABRE, $this->unknownInstallEstimate(self::MISSING_COST_INDEX));
        $this->stubMarketPricesWithFallback([self::DATACORE_2_TYPE_ID => 200.0]);

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, 30002510);

        $this->assertInventionCostOutcome([
            'costPerAttempt' => null,
            'totalCost' => null,
            'missingPriceTypeIds' => [self::DATACORE_1_TYPE_ID],
            'unknownReason' => self::MISSING_MARKET_PRICE,
        ], $result);
    }

    private function setupSabreInventionAt(?float $baseProbability, array $datacoreLines, ?array $installEstimate = null): void
    {
        $this->setupInventionChainWithDatacoreLines(self::SABRE_TYPE_ID, $baseProbability, 10, $datacoreLines);
        $this->stubInventionInstallEstimate($installEstimate ?? $this->knownInstallEstimate(500.0), $this->knownInstallEstimate(1000.0));
    }

    private function installEstimateCallsFor(string $activity): array
    {
        return array_values(array_filter($this->installEstimateCalls, static fn (array $call): bool => $call[3] === $activity));
    }

    private function knownInstallEstimate(float $cost): array
    {
        return ['cost' => $cost, 'unknownReason' => null, 'missingAdjustedPriceTypeIds' => []];
    }

    private function unknownInstallEstimate(string $unknownReason, array $missingAdjustedPriceTypeIds = []): array
    {
        return ['cost' => null, 'unknownReason' => $unknownReason, 'missingAdjustedPriceTypeIds' => $missingAdjustedPriceTypeIds];
    }

    private function stubInventionInstallEstimate(array $estimate, ?array $copyEstimate = null): void
    {
        $manufacturingMaterialsByBlueprint = [
            self::SABRE_BLUEPRINT_ID => self::SABRE_MANUFACTURING_MATERIALS,
            self::THRASHER_BLUEPRINT_ID => self::THRASHER_MANUFACTURING_MATERIALS,
        ];
        $this->activityMaterialRepository
            ->method('findMaterialsForBlueprints')
            ->willReturnCallback(static function (array $blueprintTypeIds, array $activityIds) use ($manufacturingMaterialsByBlueprint): array {
                if ([IndustryActivityType::Manufacturing->value] !== $activityIds) {
                    return [];
                }

                return array_intersect_key($manufacturingMaterialsByBlueprint, array_flip($blueprintTypeIds));
            });
        $this->esiCostIndexService->method('estimateJobInstallCost')->willReturnCallback(
            function (array $materials, int $runs, int $solarSystemId, string $activity, ?float $facilityTaxPercent) use ($estimate, $copyEstimate): array {
                $this->installEstimateCalls[] = [$materials, $runs, $solarSystemId, $activity, $facilityTaxPercent];

                return 'copying' === $activity ? $copyEstimate ?? $this->knownInstallEstimate(0.0) : $estimate;
            },
        );
    }

    private function stubMarketPricesWithFallback(array $pricesByTypeId): void
    {
        $this->jitaMarketService->method('getPricesWithFallback')->willReturnCallback(
            function (array $typeIds) use ($pricesByTypeId): array {
                $this->requestedPriceTypeIdsPerCall[] = $typeIds;

                return array_combine(
                    $typeIds,
                    array_map(static fn (int $typeId): ?float => $pricesByTypeId[$typeId] ?? null, $typeIds),
                );
            },
        );
    }

    private function assertInventionCostOutcome(array $expected, ?array $actual): void
    {
        $this->assertNotNull($actual);
        $actualOutcome = array_intersect_key($actual, $expected);
        ksort($expected);
        ksort($actualOutcome);

        $this->assertSame($expected, $actualOutcome);
    }

    private const int SABRE_DEFAULT_SOLAR_SYSTEM_ID = 30002510;
    private const int AMARR_SOLAR_SYSTEM_ID = 30002187;
    private const array THRASHER_MANUFACTURING_MATERIALS = [
        ['materialTypeId' => self::TRITANIUM_TYPE_ID, 'quantity' => 50000],
    ];

    #[Group('issue-331')]
    public function testInventionCostIsUnknownWhenTheCopyingCostIndexOfTheSystemIsMissing(): void
    {
        $this->setupPricedSabreInvention(0.25, 1, self::PRICED_INVENTION_MARKET_PRICES, $this->unknownInstallEstimate(self::MISSING_COST_INDEX));

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, self::AMARR_SOLAR_SYSTEM_ID);

        $this->assertInventionCostOutcome([
            'costPerAttempt' => null,
            'totalCost' => null,
            'costBreakdown' => ['datacores' => 463400.0, 'decryptor' => 0.0, 'copyCost' => null, 'inventionInstall' => self::PRICED_INVENTION_INSTALL_COST],
            'unknownReason' => self::MISSING_COST_INDEX,
        ], $result);
    }

    #[Group('issue-331')]
    public function testInventionCostReportsTheMaterialsWithoutAdjustedPriceOfTheCopiedBlueprint(): void
    {
        $this->setupPricedSabreInvention(0.25, 1, self::PRICED_INVENTION_MARKET_PRICES, $this->unknownInstallEstimate(self::MISSING_ADJUSTED_PRICE, [self::TRITANIUM_TYPE_ID]));

        $result = $this->service->calculateInventionCost(self::SABRE_TYPE_ID, self::AMARR_SOLAR_SYSTEM_ID);

        $this->assertInventionCostOutcome([
            'costPerAttempt' => null,
            'totalCost' => null,
            'costBreakdown' => ['datacores' => 463400.0, 'decryptor' => 0.0, 'copyCost' => null, 'inventionInstall' => self::PRICED_INVENTION_INSTALL_COST],
            'missingAdjustedPriceTypeIds' => [self::TRITANIUM_TYPE_ID],
            'unknownReason' => self::MISSING_ADJUSTED_PRICE,
        ], $result);
    }
}
