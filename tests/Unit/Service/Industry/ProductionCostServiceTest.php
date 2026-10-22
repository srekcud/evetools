<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Constant\EveConstants;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\IndustryUserSettings;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\FacilityTaxRateResolver;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryShoppingListBuilder;
use App\Service\Industry\ProductionCostService;
use App\Service\Industry\ProjectStepSolarSystemResolver;
use App\Service\JitaMarketService;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProductionCostService::class)]
class ProductionCostServiceTest extends TestCase
{
    use ResolvesTypeNamesFromSde;
    use CreatesUserWithId;

    private const int RIFTER_TYPE_ID = 587;
    private const int RIFTER_BLUEPRINT_TYPE_ID = 691;
    private const int PYERITE_TYPE_ID = 35;
    private const string ROBOTICS_FAILURE_REASON = 'No blueprint or reaction found for product type ID 9848';
    private const float NPC_STATION_FACILITY_TAX_PERCENT = 0.25;
    private const int STEP_A_PRODUCT_TYPE_ID = 587;
    private const int STEP_B_PRODUCT_TYPE_ID = 11399;
    private const int TYPE_MISSING_FROM_SDE_ID = 123456;
    private const string TYPE_MISSING_FROM_SDE_REASON = 'Types missing from the SDE: 123456';

    private JitaMarketService&MockObject $jitaMarketService;
    private EsiCostIndexService&MockObject $esiCostIndexService;
    private IndustryShoppingListBuilder&MockObject $shoppingListBuilder;
    private IndustryCalculationService&MockObject $calculationService;
    private IndustryUserSettingsRepository&MockObject $userSettingsRepository;
    private IndustryActivityMaterialRepository&MockObject $materialRepository;
    private IndustryActivityProductRepository&MockObject $productRepository;
    private MapSolarSystemRepository&MockObject $solarSystemRepository;
    private ProductionCostService $service;

    protected function setUp(): void
    {
        $this->jitaMarketService = $this->createMock(JitaMarketService::class);
        $this->esiCostIndexService = $this->createMock(EsiCostIndexService::class);
        $this->shoppingListBuilder = $this->createMock(IndustryShoppingListBuilder::class);
        $this->calculationService = $this->createMock(IndustryCalculationService::class);
        $this->userSettingsRepository = $this->createMock(IndustryUserSettingsRepository::class);
        $this->materialRepository = $this->createMock(IndustryActivityMaterialRepository::class);
        $this->productRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->solarSystemRepository = $this->createMock(MapSolarSystemRepository::class);

        $this->service = new ProductionCostService(
            $this->jitaMarketService,
            $this->esiCostIndexService,
            $this->shoppingListBuilder,
            $this->calculationService,
            new ProjectStepSolarSystemResolver($this->userSettingsRepository),
            $this->materialRepository,
            $this->productRepository,
            $this->solarSystemRepository,
            $this->facilityTaxRateResolver(),
        );
    }

    // ===========================================
    // Helper methods
    // ===========================================

    private function createProject(int $productTypeId, int $runs, ?float $bpoCost = null): IndustryProject&MockObject
    {
        $user = $this->userWithId();
        $project = $this->createMock(IndustryProject::class);
        $project->method('getProductTypeId')->willReturn($productTypeId);
        $project->method('getRuns')->willReturn($runs);
        $project->method('getUser')->willReturn($user);
        $project->method('getBpoCost')->willReturn($bpoCost);

        return $project;
    }

    private function createStep(
        string $activityType,
        int $productTypeId,
        int $runs,
        ?IndustryStructureConfig $structureConfig = null,
        int $blueprintTypeId = 0,
        bool $purchased = false,
        bool $inStock = false,
    ): IndustryProjectStep&MockObject {
        $step = $this->createMock(IndustryProjectStep::class);
        $step->method('getActivityType')->willReturn($activityType);
        $step->method('getProductTypeId')->willReturn($productTypeId);
        $step->method('getRuns')->willReturn($runs);
        $step->method('getId')->willReturn(Uuid::v4());
        $step->method('getStructureConfig')->willReturn($structureConfig);
        $step->method('getBlueprintTypeId')->willReturn($blueprintTypeId);
        $step->method('isPurchased')->willReturn($purchased);
        $step->method('isInStock')->willReturn($inStock);

        return $step;
    }

    private function facilityTaxRateResolver(): FacilityTaxRateResolver
    {
        return new FacilityTaxRateResolver(new InMemoryUserProductionStructures());
    }

    private function installCostOf(float $cost): array
    {
        return ['cost' => $cost, 'unknownReason' => null, 'missingAdjustedPriceTypeIds' => []];
    }

    private function unknownInstallCost(string $reason, array $missingAdjustedPriceTypeIds = []): array
    {
        return ['cost' => null, 'unknownReason' => $reason, 'missingAdjustedPriceTypeIds' => $missingAdjustedPriceTypeIds];
    }

    private function recordInstallCostEstimates(callable $estimate): \ArrayObject
    {
        $calls = new \ArrayObject();
        $this->esiCostIndexService->method('estimateJobInstallCost')->willReturnCallback(
            function (array $materials, int $runs, int $solarSystemId, string $activity, ?float $facilityTaxRate) use ($calls, $estimate): array {
                $calls->append([$materials, $runs, $solarSystemId, $activity, $facilityTaxRate]);

                return $estimate($materials);
            },
        );

        return $calls;
    }

    private function createStructureConfig(int $solarSystemId, ?float $facilityTaxRate = null): IndustryStructureConfig
    {
        $config = $this->createMock(IndustryStructureConfig::class);
        $config->method('getSolarSystemId')->willReturn($solarSystemId);
        $config->method('getFacilityTaxRate')->willReturn($facilityTaxRate);

        return $config;
    }

    // ===========================================
    // estimateMaterialCost() tests
    // ===========================================

    public function testEstimateMaterialCostReturnsCorrectTotal(): void
    {
        $project = $this->createProject(587, 10); // Rifter x10

        // Shopping list returns raw materials
        $this->shoppingListBuilder
            ->method('getShoppingList')
            ->willReturn([
                'materials' => [
                    ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 250000],
                    ['typeId' => 35, 'typeName' => 'Pyerite', 'quantity' => 50000],
                    ['typeId' => 36, 'typeName' => 'Mexallon', 'quantity' => 10000],
                ],
                'failedProducts' => [],
            ]);

        $this->jitaMarketService
            ->method('getPricesWithFallback')
            ->willReturn([
                34 => 5.50,
                35 => 12.00,
                36 => 45.00,
            ]);

        $result = $this->service->estimateMaterialCost($project);

        // 250000 * 5.50 + 50000 * 12.00 + 10000 * 45.00
        // = 1375000 + 600000 + 450000 = 2425000
        $this->assertSame(2425000.0, $result['total']);
        $this->assertCount(3, $result['items']);
        $this->assertSame(34, $result['items'][0]['typeId']);
        $this->assertSame(5.50, $result['items'][0]['unitPrice']);
        $this->assertSame(1375000.0, $result['items'][0]['totalPrice']);
    }

    public function testEstimateMaterialCostHandlesMissingPricesGracefully(): void
    {
        $project = $this->createProject(587, 1);

        $this->shoppingListBuilder
            ->method('getShoppingList')
            ->willReturn([
                'materials' => [
                    ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 1000],
                    ['typeId' => 99999, 'typeName' => 'Unknown Material', 'quantity' => 500],
                ],
                'failedProducts' => [],
            ]);

        // typeId 99999 has no price (returns null, getPricesWithFallback returns 0.0 as default)
        $this->jitaMarketService
            ->method('getPricesWithFallback')
            ->willReturn([
                34 => 5.50,
                99999 => 0.0,
            ]);

        $result = $this->service->estimateMaterialCost($project);

        $this->assertSame(5500.0, $result['total']);
        $this->assertSame(0.0, $result['items'][1]['unitPrice']);
        $this->assertSame(0.0, $result['items'][1]['totalPrice']);
    }

    public function testEstimateMaterialCostEmptyShoppingList(): void
    {
        $project = $this->createProject(587, 1);

        $this->shoppingListBuilder->method('getShoppingList')->willReturn(['materials' => [], 'failedProducts' => []]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([]);

        $result = $this->service->estimateMaterialCost($project);

        $this->assertSame(0.0, $result['total']);
        $this->assertCount(0, $result['items']);
    }

    #[Group('issue-119')]
    public function testEstimateMaterialCostOfAProjectWithAFailedProductIsUnknownAndCarriesTheFailedProduct(): void
    {
        $project = $this->createProject(self::RIFTER_TYPE_ID, 2);
        $this->stubShoppingList(
            [['typeId' => self::PYERITE_TYPE_ID, 'typeName' => 'Pyerite', 'quantity' => 1200]],
            [['typeId' => self::ROBOTICS_TYPE_ID, 'reason' => self::ROBOTICS_FAILURE_REASON]],
        );

        $result = $this->service->estimateMaterialCost($project);

        self::assertNull($result['total']);
        self::assertSame([
            ['typeId' => self::PYERITE_TYPE_ID, 'typeName' => 'Pyerite', 'quantity' => 1200, 'unitPrice' => 7.25, 'totalPrice' => 8700.0],
        ], $result['items']);
        self::assertSame([
            ['typeId' => self::ROBOTICS_TYPE_ID, 'reason' => self::ROBOTICS_FAILURE_REASON],
        ], $result['failedProducts']);
    }

    #[Group('issue-119')]
    public function testEstimateMaterialCostOfAProjectWhoseOnlyProductFailedIsUnknownWithoutMaterials(): void
    {
        $project = $this->createProject(self::TYPE_MISSING_FROM_SDE_ID, 2);
        $this->stubShoppingList(
            [],
            [['typeId' => self::TYPE_MISSING_FROM_SDE_ID, 'reason' => self::TYPE_MISSING_FROM_SDE_REASON]],
        );

        $result = $this->service->estimateMaterialCost($project);

        self::assertNull($result['total']);
        self::assertSame([], $result['items']);
        self::assertSame([
            ['typeId' => self::TYPE_MISSING_FROM_SDE_ID, 'reason' => self::TYPE_MISSING_FROM_SDE_REASON],
        ], $result['failedProducts']);
    }

    #[Group('issue-119')]
    public function testEstimateMaterialCostWithoutFailedProductIsPricedAndCarriesNoFailedProduct(): void
    {
        $project = $this->createProject(self::RIFTER_TYPE_ID, 2);
        $this->stubShoppingList(
            [['typeId' => self::PYERITE_TYPE_ID, 'typeName' => 'Pyerite', 'quantity' => 1200]],
            [],
        );

        $result = $this->service->estimateMaterialCost($project);

        self::assertSame(8700.0, $result['total']);
        self::assertSame([
            ['typeId' => self::PYERITE_TYPE_ID, 'typeName' => 'Pyerite', 'quantity' => 1200, 'unitPrice' => 7.25, 'totalPrice' => 8700.0],
        ], $result['items']);
        self::assertSame([], $result['failedProducts']);
    }

    // ===========================================
    // estimateJobInstallCosts() tests
    // ===========================================

    public function testEstimateJobInstallCostsCalculatesForManufacturingSteps(): void
    {
        $config = $this->createStructureConfig(30002510, 10.0);
        $step1 = $this->createStep('manufacturing', 587, 10, $config, 586);
        $step2 = $this->createStep('manufacturing', 11399, 5, $config, 11398);
        $copyStep = $this->createStep('copy', 586, 10, $config, 585); // skipped

        $project = $this->createProject(587, 10);
        $project->method('getSteps')->willReturn(new ArrayCollection([$step1, $step2, $copyStep]));

        // ME0 materials for EIV calculation
        $this->materialRepository->method('findMaterialsForBlueprints')
            ->willReturnCallback(function (array $bpIds) {
                $bpId = $bpIds[0];

                return match ($bpId) {
                    586 => [586 => [['materialTypeId' => 34, 'quantity' => 100000]]],
                    11398 => [11398 => [['materialTypeId' => 34, 'quantity' => 20000]]],
                    default => [],
                };
            });

        $calls = $this->recordInstallCostEstimates(fn (array $materials): array => $this->installCostOf(
            $materials[0]['quantity'] === 100000 ? 150000.0 : 30000.0,
        ));

        $this->esiCostIndexService
            ->method('getCostIndex')
            ->willReturn(0.05);

        $this->calculationService
            ->method('resolveTypeName')
            ->willReturnCallback(fn (int $typeId) => "Type #{$typeId}");

        $solarSystem = $this->createMock(MapSolarSystem::class);
        $solarSystem->method('getSolarSystemName')->willReturn('1DQ1-A');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturn($solarSystem);

        $this->userSettingsRepository->method('findOneBy')->willReturn(null);

        $result = $this->service->estimateJobInstallCosts($project);

        $this->assertSame(180000.0, $result['total']); // 150000 + 30000
        $this->assertCount(2, $result['steps']); // copy step excluded
        $this->assertSame(587, $result['steps'][0]['productTypeId']);
        $this->assertSame(150000.0, $result['steps'][0]['installCost']);
        $this->assertSame(0.05, $result['steps'][0]['costIndex']);
        $this->assertSame([
            [[['materialTypeId' => 34, 'quantity' => 100000]], 10, 30002510, 'manufacturing', 10.0],
            [[['materialTypeId' => 34, 'quantity' => 20000]], 5, 30002510, 'manufacturing', 10.0],
        ], $calls->getArrayCopy());
    }

    public function testEstimateJobInstallCostsIncludesReactionSteps(): void
    {
        $config = $this->createStructureConfig(30002510);
        $reactionStep = $this->createStep('reaction', 30003, 20, $config, 30002);

        $project = $this->createProject(30003, 20);
        $project->method('getSteps')->willReturn(new ArrayCollection([$reactionStep]));

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            30002 => [['materialTypeId' => 200, 'quantity' => 1000]],
        ]);
        $calls = $this->recordInstallCostEstimates(fn (array $materials): array => $this->installCostOf(5000.0));
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.02);
        $this->calculationService->method('resolveTypeName')->willReturn('RCF');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturn(null);
        $this->userSettingsRepository->method('findOneBy')->willReturn(null);

        $result = $this->service->estimateJobInstallCosts($project);

        $this->assertSame(5000.0, $result['total']);
        $this->assertCount(1, $result['steps']);
        $this->assertSame(30003, $result['steps'][0]['productTypeId']);
        $this->assertSame([
            [[['materialTypeId' => 200, 'quantity' => 1000]], 20, 30002510, 'reaction', null],
        ], $calls->getArrayCopy());
    }

    public function testEstimateJobInstallCostsFallsBackToJitaSolarSystem(): void
    {
        // Step with no structure config
        $step = $this->createStep('manufacturing', 587, 1, null, 586);

        $project = $this->createProject(587, 1);
        $project->method('getSteps')->willReturn(new ArrayCollection([$step]));

        $this->userSettingsRepository->method('findOneBy')->willReturn(null);

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [['materialTypeId' => 34, 'quantity' => 100000]],
        ]);

        $this->esiCostIndexService
            ->expects($this->once())
            ->method('estimateJobInstallCost')
            ->with(
                [['materialTypeId' => 34, 'quantity' => 100000]],
                1,
                EveConstants::JITA_SOLAR_SYSTEM_ID,
                'manufacturing',
                self::NPC_STATION_FACILITY_TAX_PERCENT,
            )
            ->willReturn($this->installCostOf(1000.0));

        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.01);
        $this->calculationService->method('resolveTypeName')->willReturn('Rifter');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturn(null);

        $result = $this->service->estimateJobInstallCosts($project);

        $this->assertSame(1000.0, $result['total']);
        $this->assertSame(EveConstants::JITA_SOLAR_SYSTEM_ID, $result['steps'][0]['solarSystemId']);
    }

    // ===========================================
    // estimateTotalCost() tests
    // ===========================================

    public function testEstimateTotalCostSumsAllCosts(): void
    {
        $config = $this->createStructureConfig(30002510);
        $step = $this->createStep('manufacturing', 587, 10, $config, 586);

        $project = $this->createProject(587, 10, 50000.0);
        $project->method('getSteps')->willReturn(new ArrayCollection([$step]));

        // Material cost
        $this->shoppingListBuilder->method('getShoppingList')->willReturn([
            'materials' => [
                ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 100000],
            ],
            'failedProducts' => [],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 5.0]);

        // Job install cost
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [['materialTypeId' => 34, 'quantity' => 100000]],
        ]);
        $this->esiCostIndexService->method('estimateJobInstallCost')->willReturn($this->installCostOf(25000.0));
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.05);
        $this->calculationService->method('resolveTypeName')->willReturn('Rifter');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturn(null);
        $this->userSettingsRepository->method('findOneBy')->willReturn(null);

        // Output: 1 per run * 10 runs = 10
        $outputProduct = new IndustryActivityProduct();
        $outputProduct->setTypeId(586);
        $outputProduct->setActivityId(IndustryActivityType::Manufacturing->value);
        $outputProduct->setProductTypeId(587);
        $outputProduct->setQuantity(1);
        $this->productRepository->method('findBlueprintForProduct')->willReturn($outputProduct);

        $result = $this->service->estimateTotalCost($project);

        // materialCost: 100000 * 5.0 = 500000
        // jobInstallCost: 25000
        // bpoCost: 50000
        // total: 575000
        $this->assertSame(500000.0, $result['materialCost']);
        $this->assertSame(25000.0, $result['jobInstallCost']);
        $this->assertSame(50000.0, $result['bpoCost']);
        $this->assertSame(575000.0, $result['totalCost']);

        // perUnit: 575000 / 10 = 57500
        $this->assertSame(57500.0, $result['perUnit']);
    }

    public function testEstimateTotalCostPerUnitWithMultipleOutputPerRun(): void
    {
        $config = $this->createStructureConfig(30002510);
        $step = $this->createStep('manufacturing', 11399, 5, $config, 11398);

        $project = $this->createProject(11399, 5, null);
        $project->method('getSteps')->willReturn(new ArrayCollection([$step]));

        $this->shoppingListBuilder->method('getShoppingList')->willReturn([
            'materials' => [
                ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 5000],
            ],
            'failedProducts' => [],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 10.0]);
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([]);
        $this->esiCostIndexService->method('estimateJobInstallCost')->willReturn($this->installCostOf(0.0));
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.0);
        $this->calculationService->method('resolveTypeName')->willReturn('Component');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturn(null);
        $this->userSettingsRepository->method('findOneBy')->willReturn(null);

        // Output: 3 per run * 5 runs = 15
        $outputProduct = new IndustryActivityProduct();
        $outputProduct->setTypeId(11398);
        $outputProduct->setActivityId(IndustryActivityType::Manufacturing->value);
        $outputProduct->setProductTypeId(11399);
        $outputProduct->setQuantity(3);
        $this->productRepository->method('findBlueprintForProduct')->willReturn($outputProduct);

        $result = $this->service->estimateTotalCost($project);

        // materialCost: 50000, total: 50000, output: 15
        // perUnit: 50000 / 15 = 3333.33...
        $this->assertEqualsWithDelta(3333.33, $result['perUnit'], 0.01);
    }

    public function testEstimateTotalCostWithNoBpoCost(): void
    {
        $config = $this->createStructureConfig(30002510);
        $step = $this->createStep('manufacturing', 587, 1, $config, 586);

        $project = $this->createProject(587, 1, null);
        $project->method('getSteps')->willReturn(new ArrayCollection([$step]));

        $this->shoppingListBuilder->method('getShoppingList')->willReturn(['materials' => [], 'failedProducts' => []]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([]);
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([]);
        $this->esiCostIndexService->method('estimateJobInstallCost')->willReturn($this->installCostOf(0.0));
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.0);
        $this->calculationService->method('resolveTypeName')->willReturn('Rifter');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturn(null);
        $this->userSettingsRepository->method('findOneBy')->willReturn(null);

        $outputProduct = new IndustryActivityProduct();
        $outputProduct->setTypeId(586);
        $outputProduct->setActivityId(IndustryActivityType::Manufacturing->value);
        $outputProduct->setProductTypeId(587);
        $outputProduct->setQuantity(1);
        $this->productRepository->method('findBlueprintForProduct')->willReturn($outputProduct);

        $result = $this->service->estimateTotalCost($project);

        $this->assertSame(0.0, $result['bpoCost']);
        $this->assertSame(0.0, $result['totalCost']);
    }

    #[Group('issue-119')]
    public function testEstimateTotalCostOfAProjectWithAFailedProductHasUnknownMaterialTotalAndPerUnitCostsAndPricedJobAndBpoCosts(): void
    {
        $project = $this->projectWithOneRifterManufacturingStep(1000.0);
        $this->stubShoppingList(
            [['typeId' => self::PYERITE_TYPE_ID, 'typeName' => 'Pyerite', 'quantity' => 1200]],
            [['typeId' => self::ROBOTICS_TYPE_ID, 'reason' => self::ROBOTICS_FAILURE_REASON]],
        );

        $result = $this->service->estimateTotalCost($project);

        self::assertNull($result['materialCost']);
        self::assertNull($result['totalCost']);
        self::assertNull($result['perUnit']);
        self::assertSame(5000.0, $result['jobInstallCost']);
        self::assertSame(1000.0, $result['bpoCost']);
        self::assertSame([
            ['typeId' => self::PYERITE_TYPE_ID, 'typeName' => 'Pyerite', 'quantity' => 1200, 'unitPrice' => 7.25, 'totalPrice' => 8700.0],
        ], $result['materials']);
        self::assertSame([
            ['typeId' => self::ROBOTICS_TYPE_ID, 'reason' => self::ROBOTICS_FAILURE_REASON],
        ], $result['failedProducts']);
    }

    #[Group('issue-119')]
    public function testEstimateTotalCostWithoutFailedProductSumsMaterialJobAndBpoCostsAndCarriesNoFailedProduct(): void
    {
        $project = $this->projectWithOneRifterManufacturingStep(1000.0);
        $this->stubShoppingList(
            [['typeId' => self::PYERITE_TYPE_ID, 'typeName' => 'Pyerite', 'quantity' => 1200]],
            [],
        );

        $result = $this->service->estimateTotalCost($project);

        self::assertSame(8700.0, $result['materialCost']);
        self::assertSame(5000.0, $result['jobInstallCost']);
        self::assertSame(1000.0, $result['bpoCost']);
        self::assertSame(14700.0, $result['totalCost']);
        self::assertSame(7350.0, $result['perUnit']);
        self::assertSame([], $result['failedProducts']);
    }

    #[Group('issue-7')]
    public function testEstimateTotalCostWithAnUnknownStepHasUnknownJobTotalAndTotalAndPerUnitCostsAndKnownMaterialCost(): void
    {
        $project = $this->projectWithOneRifterManufacturingStep(
            1000.0,
            $this->unknownInstallCost('missing_cost_index'),
            null,
        );
        $this->stubShoppingList(
            [['typeId' => self::PYERITE_TYPE_ID, 'typeName' => 'Pyerite', 'quantity' => 1200]],
            [],
        );

        $result = $this->service->estimateTotalCost($project);

        self::assertSame(8700.0, $result['materialCost']);
        self::assertNull($result['jobInstallCost']);
        self::assertSame(1000.0, $result['bpoCost']);
        self::assertNull($result['totalCost']);
        self::assertNull($result['perUnit']);
        self::assertNull($result['jobInstallSteps'][0]['installCost']);
        self::assertNull($result['jobInstallSteps'][0]['costIndex']);
        self::assertSame('missing_cost_index', $result['jobInstallSteps'][0]['unknownReason']);
        self::assertSame([], $result['jobInstallSteps'][0]['missingAdjustedPriceTypeIds']);
    }

    #[Group('issue-7')]
    public function testEstimateJobInstallCostsOfAStepWithAMaterialWithoutAdjustedPriceCarriesTheMissingType(): void
    {
        $project = $this->projectWithOneRifterManufacturingStep(
            1000.0,
            $this->unknownInstallCost('missing_adjusted_price', [self::PYERITE_TYPE_ID]),
        );

        $result = $this->service->estimateJobInstallCosts($project);

        self::assertNull($result['total']);
        self::assertNull($result['steps'][0]['installCost']);
        self::assertSame(0.0412, $result['steps'][0]['costIndex']);
        self::assertSame('missing_adjusted_price', $result['steps'][0]['unknownReason']);
        self::assertSame([self::PYERITE_TYPE_ID], $result['steps'][0]['missingAdjustedPriceTypeIds']);
    }

    #[Group('issue-127')]
    public function testEstimateJobInstallCostsIgnoresAPurchasedStep(): void
    {
        $project = $this->projectWithStepsAAndB(purchasedB: true);
        $calls = $this->recordInstallCostEstimatesOfStepsAAndB();

        $result = $this->service->estimateJobInstallCosts($project);

        self::assertSame(1000.0, $result['total']);
        self::assertCount(1, $result['steps']);
        self::assertSame(self::STEP_A_PRODUCT_TYPE_ID, $result['steps'][0]['productTypeId']);
        self::assertSame(1000.0, $result['steps'][0]['installCost']);
        self::assertSame([
            [[['materialTypeId' => 34, 'quantity' => 100000]], 10, 30002510, 'manufacturing', null],
        ], $calls->getArrayCopy());
    }

    #[Group('issue-127')]
    public function testEstimateJobInstallCostsIgnoresAStepFullyInStock(): void
    {
        $project = $this->projectWithStepsAAndB(inStockB: true);
        $calls = $this->recordInstallCostEstimatesOfStepsAAndB();

        $result = $this->service->estimateJobInstallCosts($project);

        self::assertSame(1000.0, $result['total']);
        self::assertCount(1, $result['steps']);
        self::assertSame(self::STEP_A_PRODUCT_TYPE_ID, $result['steps'][0]['productTypeId']);
        self::assertSame([
            [[['materialTypeId' => 34, 'quantity' => 100000]], 10, 30002510, 'manufacturing', null],
        ], $calls->getArrayCopy());
    }

    #[Group('issue-127')]
    public function testEstimateJobInstallCostsStillCountsAStepPartiallyInStock(): void
    {
        $project = $this->projectWithStepsAAndB();
        $calls = $this->recordInstallCostEstimatesOfStepsAAndB();

        $result = $this->service->estimateJobInstallCosts($project);

        self::assertSame(1500.0, $result['total']);
        self::assertCount(2, $result['steps']);
        self::assertSame(self::STEP_B_PRODUCT_TYPE_ID, $result['steps'][1]['productTypeId']);
        self::assertSame(500.0, $result['steps'][1]['installCost']);
        self::assertCount(2, $calls);
    }

    #[Group('issue-127')]
    public function testEstimateJobInstallCostsOfAPurchasedStepInASystemWithoutCostIndexStaysKnown(): void
    {
        $project = $this->projectWithStepsAAndB(purchasedB: true);
        $this->recordInstallCostEstimates(fn (array $materials): array => $materials[0]['quantity'] === 100000
            ? $this->installCostOf(1000.0)
            : $this->unknownInstallCost('missing_cost_index'));
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.0412);

        $result = $this->service->estimateJobInstallCosts($project);

        self::assertSame(1000.0, $result['total']);
        self::assertCount(1, $result['steps']);
    }

    #[Group('issue-127')]
    public function testEstimateTotalCostIgnoresTheJobInstallCostOfAPurchasedStep(): void
    {
        $project = $this->projectWithStepsAAndB(purchasedB: true);
        $this->recordInstallCostEstimatesOfStepsAAndB();
        $this->shoppingListBuilder->method('getShoppingList')->willReturn([
            'materials' => [['typeId' => self::PYERITE_TYPE_ID, 'typeName' => 'Pyerite', 'quantity' => 2000]],
            'failedProducts' => [],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([self::PYERITE_TYPE_ID => 5.0]);

        $result = $this->service->estimateTotalCost($project);

        self::assertSame(10000.0, $result['materialCost']);
        self::assertSame(1000.0, $result['jobInstallCost']);
        self::assertSame(11000.0, $result['totalCost']);
        self::assertCount(1, $result['jobInstallSteps']);
    }

    #[Group('issue-127')]
    public function testEstimateJobInstallCostsKeepsCountingTheStepsAfterAPurchasedStep(): void
    {
        $project = $this->projectWithStepsAAndB(purchasedA: true);
        $calls = $this->recordInstallCostEstimatesOfStepsAAndB();

        $result = $this->service->estimateJobInstallCosts($project);

        self::assertSame(500.0, $result['total']);
        self::assertCount(1, $result['steps']);
        self::assertSame(self::STEP_B_PRODUCT_TYPE_ID, $result['steps'][0]['productTypeId']);
        self::assertSame([
            [[['materialTypeId' => 34, 'quantity' => 20000]], 5, 30002510, 'manufacturing', null],
        ], $calls->getArrayCopy());
    }

    private function projectWithStepsAAndB(bool $purchasedB = false, bool $inStockB = false, bool $purchasedA = false): IndustryProject&MockObject
    {
        $config = $this->createStructureConfig(30002510);
        $stepA = $this->createStep('manufacturing', self::STEP_A_PRODUCT_TYPE_ID, 10, $config, 586, $purchasedA);
        $stepB = $this->createStep('manufacturing', self::STEP_B_PRODUCT_TYPE_ID, 5, $config, 11398, $purchasedB, $inStockB);

        $project = $this->createProject(self::STEP_A_PRODUCT_TYPE_ID, 10);
        $project->method('getSteps')->willReturn(new ArrayCollection([$stepA, $stepB]));

        $this->materialRepository->method('findMaterialsForBlueprints')
            ->willReturnCallback(static fn (array $blueprintTypeIds): array => match ($blueprintTypeIds[0]) {
                586 => [586 => [['materialTypeId' => 34, 'quantity' => 100000]]],
                11398 => [11398 => [['materialTypeId' => 34, 'quantity' => 20000]]],
                default => [],
            });
        $this->calculationService->method('resolveTypeName')->willReturn('Type');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturn(null);
        $this->userSettingsRepository->method('findOneBy')->willReturn(null);
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.0412);

        $outputProduct = new IndustryActivityProduct();
        $outputProduct->setTypeId(586);
        $outputProduct->setActivityId(IndustryActivityType::Manufacturing->value);
        $outputProduct->setProductTypeId(self::STEP_A_PRODUCT_TYPE_ID);
        $outputProduct->setQuantity(1);
        $this->productRepository->method('findBlueprintForProduct')->willReturn($outputProduct);

        return $project;
    }

    private function recordInstallCostEstimatesOfStepsAAndB(): \ArrayObject
    {
        return $this->recordInstallCostEstimates(fn (array $materials): array => $this->installCostOf(
            $materials[0]['quantity'] === 100000 ? 1000.0 : 500.0,
        ));
    }

    private function projectWithOneRifterManufacturingStep(float $bpoCost, ?array $installCostEstimate = null, ?float $costIndex = 0.0412): IndustryProject&MockObject
    {
        $step = $this->createStep('manufacturing', self::RIFTER_TYPE_ID, 2, $this->createStructureConfig(30002510), self::RIFTER_BLUEPRINT_TYPE_ID);
        $project = $this->createProject(self::RIFTER_TYPE_ID, 2, $bpoCost);
        $project->method('getSteps')->willReturn(new ArrayCollection([$step]));

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            self::RIFTER_BLUEPRINT_TYPE_ID => [['materialTypeId' => self::PYERITE_TYPE_ID, 'quantity' => 600]],
        ]);
        $this->esiCostIndexService->method('estimateJobInstallCost')->willReturn($installCostEstimate ?? $this->installCostOf(5000.0));
        $this->esiCostIndexService->method('getCostIndex')->willReturn($costIndex);
        $this->calculationService->method('resolveTypeName')->willReturn('Rifter');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturn(null);
        $this->userSettingsRepository->method('findOneBy')->willReturn(null);

        $outputProduct = new IndustryActivityProduct();
        $outputProduct->setTypeId(self::RIFTER_BLUEPRINT_TYPE_ID);
        $outputProduct->setActivityId(IndustryActivityType::Manufacturing->value);
        $outputProduct->setProductTypeId(self::RIFTER_TYPE_ID);
        $outputProduct->setQuantity(1);
        $this->productRepository->method('findBlueprintForProduct')->willReturn($outputProduct);

        return $project;
    }

    private function stubShoppingList(array $materials, array $failedProducts): void
    {
        $this->shoppingListBuilder->method('getShoppingList')->willReturn([
            'materials' => $materials,
            'failedProducts' => $failedProducts,
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key(
                [self::PYERITE_TYPE_ID => 7.25, self::ROBOTICS_TYPE_ID => 3100.0, self::TYPE_MISSING_FROM_SDE_ID => 42.0],
                array_flip($typeIds),
            ),
        );
    }

    // ===========================================
    // Solar system resolution fallback chain
    // ===========================================

    public function testSolarSystemFallsBackToUserFavorite(): void
    {
        // Step with no structure config
        $step = $this->createStep('manufacturing', 587, 1, null, 586);

        $project = $this->createProject(587, 1);
        $project->method('getSteps')->willReturn(new ArrayCollection([$step]));

        // User has a favorite manufacturing system
        $settings = $this->createMock(IndustryUserSettings::class);
        $settings->method('getFavoriteManufacturingSystemId')->willReturn(30004759); // 1DQ1-A
        $settings->method('getFavoriteReactionSystemId')->willReturn(null);
        $this->userSettingsRepository->method('findOneBy')->willReturn($settings);

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [['materialTypeId' => 34, 'quantity' => 100000]],
        ]);

        $this->esiCostIndexService
            ->expects($this->once())
            ->method('estimateJobInstallCost')
            ->with(
                [['materialTypeId' => 34, 'quantity' => 100000]],
                1,
                30004759,
                'manufacturing',
                self::NPC_STATION_FACILITY_TAX_PERCENT,
            )
            ->willReturn($this->installCostOf(2000.0));

        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.05);
        $this->calculationService->method('resolveTypeName')->willReturn('Rifter');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturn(null);

        $result = $this->service->estimateJobInstallCosts($project);

        $this->assertSame(30004759, $result['steps'][0]['solarSystemId']);
    }

    public function testReactionStepUsesFavoriteReactionSystem(): void
    {
        $step = $this->createStep('reaction', 30003, 10, null, 30002);

        $project = $this->createProject(30003, 10);
        $project->method('getSteps')->willReturn(new ArrayCollection([$step]));

        $settings = $this->createMock(IndustryUserSettings::class);
        $settings->method('getFavoriteManufacturingSystemId')->willReturn(30004759);
        $settings->method('getFavoriteReactionSystemId')->willReturn(30001234); // Reaction system
        $this->userSettingsRepository->method('findOneBy')->willReturn($settings);

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            30002 => [['materialTypeId' => 200, 'quantity' => 1000]],
        ]);

        $this->esiCostIndexService
            ->expects($this->once())
            ->method('estimateJobInstallCost')
            ->with(
                [['materialTypeId' => 200, 'quantity' => 1000]],
                10,
                30001234,
                'reaction',
                self::NPC_STATION_FACILITY_TAX_PERCENT,
            )
            ->willReturn($this->installCostOf(500.0));

        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.01);
        $this->calculationService->method('resolveTypeName')->willReturn('RCF');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturn(null);

        $result = $this->service->estimateJobInstallCosts($project);

        $this->assertSame(30001234, $result['steps'][0]['solarSystemId']);
    }

    #[Group('issue-115')]
    public function testJobInstallCostOfAStepWhoseProductIsMissingFromTheSdeHasAnUnknownProductNameAndAnUnchangedCost(): void
    {
        $config = $this->createStructureConfig(30004759, 0.25);
        $roboticsStep = $this->createStep('manufacturing', self::ROBOTICS_TYPE_ID, 30, $config, 9849);
        $nitrogenFuelBlockStep = $this->createStep('manufacturing', self::NITROGEN_FUEL_BLOCK_TYPE_ID, 40, $config, 4314);

        $project = $this->createProject(self::ROBOTICS_TYPE_ID, 30);
        $project->method('getSteps')->willReturn(new ArrayCollection([$roboticsStep, $nitrogenFuelBlockStep]));

        $this->materialRepository->method('findMaterialsForBlueprints')
            ->willReturnCallback(static fn (array $blueprintTypeIds): array => match ($blueprintTypeIds[0]) {
                9849 => [9849 => [['materialTypeId' => 34, 'quantity' => 50_000]]],
                4314 => [4314 => [['materialTypeId' => 44, 'quantity' => 400]]],
                default => [],
            });
        $calls = $this->recordInstallCostEstimates(fn (array $materials): array => $this->installCostOf(
            match ($materials[0]['materialTypeId']) {
                34 => 12_400.0,
                44 => 9_870.0,
            },
        ));
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.0525);

        $solarSystem = $this->createMock(MapSolarSystem::class);
        $solarSystem->method('getSolarSystemName')->willReturn('1DQ1-A');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturn($solarSystem);

        $service = new ProductionCostService(
            $this->jitaMarketService,
            $this->esiCostIndexService,
            $this->shoppingListBuilder,
            $this->calculationServiceOnSdeKnowingOnlyNitrogenFuelBlock(),
            new ProjectStepSolarSystemResolver($this->userSettingsRepository),
            $this->materialRepository,
            $this->productRepository,
            $this->solarSystemRepository,
            $this->facilityTaxRateResolver(),
        );

        $result = $service->estimateJobInstallCosts($project);

        self::assertSame(22_270.0, $result['total']);
        self::assertCount(2, $result['steps']);
        self::assertSame(self::ROBOTICS_TYPE_ID, $result['steps'][0]['productTypeId']);
        self::assertNull($result['steps'][0]['productName']);
        self::assertSame(30, $result['steps'][0]['runs']);
        self::assertSame(12_400.0, $result['steps'][0]['installCost']);
        self::assertSame(self::NITROGEN_FUEL_BLOCK_NAME, $result['steps'][1]['productName']);
        self::assertSame(9_870.0, $result['steps'][1]['installCost']);
        self::assertSame([
            [[['materialTypeId' => 34, 'quantity' => 50_000]], 30, 30004759, 'manufacturing', 0.25],
            [[['materialTypeId' => 44, 'quantity' => 400]], 40, 30004759, 'manufacturing', 0.25],
        ], $calls->getArrayCopy());
    }
}
