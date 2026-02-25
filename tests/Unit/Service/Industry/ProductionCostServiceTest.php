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
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryShoppingListBuilder;
use App\Service\Industry\ProductionCostService;
use App\Service\JitaMarketService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProductionCostService::class)]
class ProductionCostServiceTest extends TestCase
{
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
            $this->userSettingsRepository,
            $this->materialRepository,
            $this->productRepository,
            $this->solarSystemRepository,
        );
    }

    // ===========================================
    // Helper methods
    // ===========================================

    private function createProject(int $productTypeId, int $runs, ?float $bpoCost = null): IndustryProject&MockObject
    {
        $user = $this->createMock(User::class);
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
    ): IndustryProjectStep&MockObject {
        $step = $this->createMock(IndustryProjectStep::class);
        $step->method('getActivityType')->willReturn($activityType);
        $step->method('getProductTypeId')->willReturn($productTypeId);
        $step->method('getRuns')->willReturn($runs);
        $step->method('getId')->willReturn(Uuid::v4());
        $step->method('getStructureConfig')->willReturn($structureConfig);
        $step->method('getBlueprintTypeId')->willReturn($blueprintTypeId);

        return $step;
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
                ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 250000],
                ['typeId' => 35, 'typeName' => 'Pyerite', 'quantity' => 50000],
                ['typeId' => 36, 'typeName' => 'Mexallon', 'quantity' => 10000],
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
                ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 1000],
                ['typeId' => 99999, 'typeName' => 'Unknown Material', 'quantity' => 500],
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

        $this->shoppingListBuilder->method('getShoppingList')->willReturn([]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([]);

        $result = $this->service->estimateMaterialCost($project);

        $this->assertSame(0.0, $result['total']);
        $this->assertCount(0, $result['items']);
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

        // calculateEiv returns the EIV, then calculateJobInstallCost uses it
        $this->esiCostIndexService
            ->method('calculateEiv')
            ->willReturnCallback(function (array $materials): float {
                $total = 0.0;
                foreach ($materials as $mat) {
                    // Simulate: Tritanium adjusted price = 5.0
                    if ($mat['materialTypeId'] === 34) {
                        $total += 5.0 * $mat['quantity'];
                    }
                }

                return $total;
            });

        $this->esiCostIndexService
            ->method('calculateJobInstallCost')
            ->willReturnCallback(function (float $eiv): float {
                // Simulate: 500000 EIV -> 150000, 100000 EIV -> 30000
                return match (true) {
                    $eiv >= 500000.0 => 150000.0,
                    $eiv >= 100000.0 => 30000.0,
                    default => 0.0,
                };
            });

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
        $this->esiCostIndexService->method('calculateEiv')->willReturn(50000.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(5000.0);
        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.02);
        $this->calculationService->method('resolveTypeName')->willReturn('RCF');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturn(null);
        $this->userSettingsRepository->method('findOneBy')->willReturn(null);

        $result = $this->service->estimateJobInstallCosts($project);

        $this->assertSame(5000.0, $result['total']);
        $this->assertCount(1, $result['steps']);
        $this->assertSame(30003, $result['steps'][0]['productTypeId']);
    }

    public function testEstimateJobInstallCostsFallsBackToPerimeterSolarSystem(): void
    {
        // Step with no structure config
        $step = $this->createStep('manufacturing', 587, 1, null, 586);

        $project = $this->createProject(587, 1);
        $project->method('getSteps')->willReturn(new ArrayCollection([$step]));

        $this->userSettingsRepository->method('findOneBy')->willReturn(null);

        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [['materialTypeId' => 34, 'quantity' => 100000]],
        ]);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(500000.0);

        // Should use PERIMETER_SOLAR_SYSTEM_ID as fallback
        $this->esiCostIndexService
            ->expects($this->once())
            ->method('calculateJobInstallCost')
            ->with(500000.0, 1, EveConstants::PERIMETER_SOLAR_SYSTEM_ID, 'manufacturing', null)
            ->willReturn(1000.0);

        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.01);
        $this->calculationService->method('resolveTypeName')->willReturn('Rifter');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturn(null);

        $result = $this->service->estimateJobInstallCosts($project);

        $this->assertSame(1000.0, $result['total']);
        $this->assertSame(EveConstants::PERIMETER_SOLAR_SYSTEM_ID, $result['steps'][0]['solarSystemId']);
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
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 100000],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 5.0]);

        // Job install cost
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([
            586 => [['materialTypeId' => 34, 'quantity' => 100000]],
        ]);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(500000.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(25000.0);
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
            ['typeId' => 34, 'typeName' => 'Tritanium', 'quantity' => 5000],
        ]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([34 => 10.0]);
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([]);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);
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

        $this->shoppingListBuilder->method('getShoppingList')->willReturn([]);
        $this->jitaMarketService->method('getPricesWithFallback')->willReturn([]);
        $this->materialRepository->method('findMaterialsForBlueprints')->willReturn([]);
        $this->esiCostIndexService->method('calculateEiv')->willReturn(0.0);
        $this->esiCostIndexService->method('calculateJobInstallCost')->willReturn(0.0);
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
        $this->esiCostIndexService->method('calculateEiv')->willReturn(500000.0);

        $this->esiCostIndexService
            ->expects($this->once())
            ->method('calculateJobInstallCost')
            ->with(500000.0, 1, 30004759, 'manufacturing', null)
            ->willReturn(2000.0);

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
        $this->esiCostIndexService->method('calculateEiv')->willReturn(50000.0);

        $this->esiCostIndexService
            ->expects($this->once())
            ->method('calculateJobInstallCost')
            ->with(50000.0, 10, 30001234, 'reaction', null)
            ->willReturn(500.0);

        $this->esiCostIndexService->method('getCostIndex')->willReturn(0.01);
        $this->calculationService->method('resolveTypeName')->willReturn('RCF');
        $this->solarSystemRepository->method('findBySolarSystemId')->willReturn(null);

        $result = $this->service->estimateJobInstallCosts($project);

        $this->assertSame(30001234, $result['steps'][0]['solarSystemId']);
    }
}
