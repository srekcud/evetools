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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuyVsBuildService::class)]
#[Group('issue-8')]
final class BuyVsBuildServiceUnknownPriceTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private const int SOLAR_SYSTEM_ID = 30002510;
    private const int SELL_STRUCTURE_ID = 1035466617946;
    private const float BROKER_FEE_RATE = 0.03;
    private const float SALES_TAX_RATE = 0.045;
    private const int RUNS = 2;
    private const int ME_LEVEL = 2;

    private const int SABRE = 22456;
    private const int SABRE_BLUEPRINT = 22455;
    private const int MORPHITE = 11399;
    private const int MORPHITE_LEAF_QUANTITY = 30;
    private const int PLASMA_THRUSTER = 11530;
    private const int PLASMA_THRUSTER_BLUEPRINT = 11529;
    private const int PHOTON_MICROPROCESSOR = 11541;
    private const int PHOTON_MICROPROCESSOR_BLUEPRINT = 11540;
    private const int GRAVIMETRIC_SENSOR_CLUSTER = 11534;
    private const int GRAVIMETRIC_SENSOR_CLUSTER_BLUEPRINT = 11533;
    private const int TRITANIUM = 34;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;
    private const int NOCXIUM = 38;
    private const int ZYDRINE = 39;

    private const array PLASMA_THRUSTER_COMPONENT = [
        'typeId' => self::PLASMA_THRUSTER,
        'blueprintTypeId' => self::PLASMA_THRUSTER_BLUEPRINT,
        'quantity' => 10,
        'runs' => 1,
        'materials' => [self::TRITANIUM => 100, self::MEXALLON => 20],
    ];

    private const array PHOTON_MICROPROCESSOR_COMPONENT = [
        'typeId' => self::PHOTON_MICROPROCESSOR,
        'blueprintTypeId' => self::PHOTON_MICROPROCESSOR_BLUEPRINT,
        'quantity' => 4,
        'runs' => 2,
        'materials' => [self::ISOGEN => 40, self::NOCXIUM => 6],
    ];

    private const array GRAVIMETRIC_SENSOR_CLUSTER_COMPONENT = [
        'typeId' => self::GRAVIMETRIC_SENSOR_CLUSTER,
        'blueprintTypeId' => self::GRAVIMETRIC_SENSOR_CLUSTER_BLUEPRINT,
        'quantity' => 3,
        'runs' => 3,
        'materials' => [self::ZYDRINE => 2],
    ];

    private const array JITA_PRICES = [
        self::SABRE => 50000.0,
        self::MORPHITE => 400.0,
        self::PLASMA_THRUSTER => 150.0,
        self::PHOTON_MICROPROCESSOR => 2100.0,
        self::GRAVIMETRIC_SENSOR_CLUSTER => 1300.0,
        self::TRITANIUM => 5.0,
        self::MEXALLON => 50.0,
        self::ISOGEN => 80.0,
        self::NOCXIUM => 700.0,
        self::ZYDRINE => 1500.0,
    ];

    private const array JOB_INSTALL_COST_BY_BLUEPRINT = [
        self::SABRE_BLUEPRINT => 900.0,
        self::PLASMA_THRUSTER_BLUEPRINT => 200.0,
        self::PHOTON_MICROPROCESSOR_BLUEPRINT => 350.0,
        self::GRAVIMETRIC_SENSOR_CLUSTER_BLUEPRINT => 120.0,
    ];

    public function testComponentWithoutStructurePriceIsBoughtAtItsJitaPrice(): void
    {
        $result = $this->analyze([self::PLASMA_THRUSTER_COMPONENT], self::JITA_PRICES);

        $plasmaThruster = $result['components'][0];
        $this->assertSame('buy', $plasmaThruster['verdict']);
        $this->assertSame(1700.0, $plasmaThruster['buildCost']);
        $this->assertSame(1500.0, $plasmaThruster['buyCostJita']);
        $this->assertNull($plasmaThruster['buyCostStructure']);
        $this->assertSame(-200.0, $plasmaThruster['savings']);
        $this->assertSame(11.76, $plasmaThruster['savingsPercent']);
        $this->assertSame(1700.0, $result['buildAllCost']);
        $this->assertSame(1500.0, $result['buyAllCost']);
        $this->assertSame(1500.0, $result['optimalMixCost']);
        $this->assertSame(14400.0, $result['totalProductionCost']);
        $this->assertSame(542.36, $result['marginPercent']);
        $this->assertSame([], $result['missingPriceTypeIds']);
    }

    public function testComponentWithoutAnyBuyPriceIsBuiltWithUnknownSavingsAndAnUnknownBuyAllCost(): void
    {
        $result = $this->analyze([self::PLASMA_THRUSTER_COMPONENT], $this->jitaPricesWithout(self::PLASMA_THRUSTER));

        $plasmaThruster = $result['components'][0];
        $this->assertSame('build', $plasmaThruster['verdict']);
        $this->assertSame(1700.0, $plasmaThruster['buildCost']);
        $this->assertNull($plasmaThruster['buyCostJita']);
        $this->assertNull($plasmaThruster['buyCostStructure']);
        $this->assertNull($plasmaThruster['savings']);
        $this->assertNull($plasmaThruster['savingsPercent']);
        $this->assertSame(1700.0, $result['buildAllCost']);
        $this->assertNull($result['buyAllCost']);
        $this->assertSame(1700.0, $result['optimalMixCost']);
        $this->assertSame(14600.0, $result['totalProductionCost']);
        $this->assertSame(533.56, $result['marginPercent']);
        $this->assertSame([self::PLASMA_THRUSTER], $result['buildTypeIds']);
        $this->assertSame([], $result['buyTypeIds']);
        $this->assertSame([self::PLASMA_THRUSTER], $result['missingPriceTypeIds']);
    }

    public function testComponentWithAMaterialWithoutJitaPriceHasAnUnknownBuildCostAndIsBought(): void
    {
        $result = $this->analyze([self::PLASMA_THRUSTER_COMPONENT], $this->jitaPricesWithout(self::MEXALLON));

        $plasmaThruster = $result['components'][0];
        $this->assertSame([
            ['typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'quantity' => 100, 'unitPrice' => 5.0, 'totalPrice' => 500.0],
            ['typeId' => self::MEXALLON, 'typeName' => 'Mexallon', 'quantity' => 20, 'unitPrice' => null, 'totalPrice' => null],
        ], $plasmaThruster['buildMaterials']);
        $this->assertNull($plasmaThruster['buildCost']);
        $this->assertSame('buy', $plasmaThruster['verdict']);
        $this->assertSame(1500.0, $plasmaThruster['buyCostJita']);
        $this->assertNull($plasmaThruster['savings']);
        $this->assertNull($plasmaThruster['savingsPercent']);
        $this->assertNull($result['buildAllCost']);
        $this->assertSame(1500.0, $result['buyAllCost']);
        $this->assertSame(1500.0, $result['optimalMixCost']);
        $this->assertSame(14400.0, $result['totalProductionCost']);
        $this->assertSame(542.36, $result['marginPercent']);
        $this->assertSame([], $result['buildTypeIds']);
        $this->assertSame([self::PLASMA_THRUSTER], $result['buyTypeIds']);
        $this->assertSame([self::MEXALLON], $result['missingPriceTypeIds']);
    }

    public function testComponentWithoutBuildCostNorBuyCostHasAnUnknownVerdictAndMakesEveryTotalUnknown(): void
    {
        $result = $this->analyze([self::PLASMA_THRUSTER_COMPONENT], $this->jitaPricesWithout(self::MEXALLON, self::PLASMA_THRUSTER));

        $plasmaThruster = $result['components'][0];
        $this->assertSame('unknown', $plasmaThruster['verdict']);
        $this->assertNull($plasmaThruster['buildCost']);
        $this->assertNull($plasmaThruster['buyCostJita']);
        $this->assertNull($plasmaThruster['savings']);
        $this->assertNull($plasmaThruster['savingsPercent']);
        $this->assertNull($result['buildAllCost']);
        $this->assertNull($result['buyAllCost']);
        $this->assertNull($result['optimalMixCost']);
        $this->assertNull($result['totalProductionCost']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame(50000.0, $result['sellPrice']);
        $this->assertSame([self::MEXALLON, self::PLASMA_THRUSTER], $result['missingPriceTypeIds']);
    }

    public function testFinalJobLeafWithoutJitaPriceMakesTheTotalProductionCostAndTheMarginUnknown(): void
    {
        $result = $this->analyze([self::PLASMA_THRUSTER_COMPONENT], $this->jitaPricesWithout(self::MORPHITE));

        $this->assertSame('buy', $result['components'][0]['verdict']);
        $this->assertSame(1700.0, $result['buildAllCost']);
        $this->assertSame(1500.0, $result['buyAllCost']);
        $this->assertSame(1500.0, $result['optimalMixCost']);
        $this->assertNull($result['totalProductionCost']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame([self::MORPHITE], $result['missingPriceTypeIds']);
    }

    public function testFullyPricedAnalysisIsUnchangedAndHasNoMissingPriceTypeIds(): void
    {
        $result = $this->analyze(
            [self::PLASMA_THRUSTER_COMPONENT, self::PHOTON_MICROPROCESSOR_COMPONENT, self::GRAVIMETRIC_SENSOR_CLUSTER_COMPONENT],
            self::JITA_PRICES,
        );

        $this->assertSame([
            'typeId' => self::SABRE,
            'typeName' => 'Sabre',
            'isT2' => true,
            'runs' => self::RUNS,
            'totalProductionCost' => 25270.0,
            'sellPrice' => 50000.0,
            'marginPercent' => 266.05,
            'components' => [
                [
                    'typeId' => self::GRAVIMETRIC_SENSOR_CLUSTER,
                    'typeName' => 'Gravimetric Sensor Cluster',
                    'quantity' => 3,
                    'stage' => 'T1 Component',
                    'buildCost' => 3120.0,
                    'buildMaterials' => [
                        ['typeId' => self::ZYDRINE, 'typeName' => 'Zydrine', 'quantity' => 2, 'unitPrice' => 1500.0, 'totalPrice' => 3000.0],
                    ],
                    'buildJobInstallCost' => 120.0,
                    'buyCostJita' => 3900.0,
                    'buyCostStructure' => null,
                    'verdict' => 'build',
                    'savings' => 780.0,
                    'savingsPercent' => 20.0,
                    'meUsed' => 10,
                    'runs' => 3,
                ],
                [
                    'typeId' => self::PHOTON_MICROPROCESSOR,
                    'typeName' => 'Photon Microprocessor',
                    'quantity' => 4,
                    'stage' => 'T1 Component',
                    'buildCost' => 7750.0,
                    'buildMaterials' => [
                        ['typeId' => self::ISOGEN, 'typeName' => 'Isogen', 'quantity' => 40, 'unitPrice' => 80.0, 'totalPrice' => 3200.0],
                        ['typeId' => self::NOCXIUM, 'typeName' => 'Nocxium', 'quantity' => 6, 'unitPrice' => 700.0, 'totalPrice' => 4200.0],
                    ],
                    'buildJobInstallCost' => 350.0,
                    'buyCostJita' => 8400.0,
                    'buyCostStructure' => null,
                    'verdict' => 'build',
                    'savings' => 650.0,
                    'savingsPercent' => 7.74,
                    'meUsed' => 10,
                    'runs' => 2,
                ],
                [
                    'typeId' => self::PLASMA_THRUSTER,
                    'typeName' => 'Plasma Thruster',
                    'quantity' => 10,
                    'stage' => 'T1 Component',
                    'buildCost' => 1700.0,
                    'buildMaterials' => [
                        ['typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'quantity' => 100, 'unitPrice' => 5.0, 'totalPrice' => 500.0],
                        ['typeId' => self::MEXALLON, 'typeName' => 'Mexallon', 'quantity' => 20, 'unitPrice' => 50.0, 'totalPrice' => 1000.0],
                    ],
                    'buildJobInstallCost' => 200.0,
                    'buyCostJita' => 1500.0,
                    'buyCostStructure' => null,
                    'verdict' => 'buy',
                    'savings' => -200.0,
                    'savingsPercent' => 11.76,
                    'meUsed' => 10,
                    'runs' => 1,
                ],
            ],
            'buildAllCost' => 12570.0,
            'buyAllCost' => 13800.0,
            'optimalMixCost' => 12370.0,
            'buildTypeIds' => [self::PHOTON_MICROPROCESSOR, self::GRAVIMETRIC_SENSOR_CLUSTER],
            'buyTypeIds' => [self::PLASMA_THRUSTER],
            'missingPriceTypeIds' => [],
        ], $result);
    }

    public function testComponentWithOnlyAStructurePriceHasAKnownBuyCostAndNoMissingPrice(): void
    {
        $result = $this->analyze(
            [self::PLASMA_THRUSTER_COMPONENT],
            $this->jitaPricesWithout(self::PLASMA_THRUSTER),
            [self::PLASMA_THRUSTER => 140.0],
        );

        $plasmaThruster = $result['components'][0];
        $this->assertSame('buy', $plasmaThruster['verdict']);
        $this->assertNull($plasmaThruster['buyCostJita']);
        $this->assertSame(1400.0, $plasmaThruster['buyCostStructure']);
        $this->assertSame(-300.0, $plasmaThruster['savings']);
        $this->assertSame(17.65, $plasmaThruster['savingsPercent']);
        $this->assertSame(1400.0, $result['buyAllCost']);
        $this->assertSame(14300.0, $result['totalProductionCost']);
        $this->assertSame(546.85, $result['marginPercent']);
        $this->assertSame([], $result['missingPriceTypeIds']);
    }

    public function testOneUnknownBuildCostMakesTheBuildAllCostUnknownWhileTheBuyAllAndOptimalMixCostsStayKnown(): void
    {
        $result = $this->analyze(
            [self::PLASMA_THRUSTER_COMPONENT, self::PHOTON_MICROPROCESSOR_COMPONENT],
            $this->jitaPricesWithout(self::NOCXIUM),
        );

        $this->assertSame(['buy', 'buy'], array_column($result['components'], 'verdict'));
        $this->assertNull($result['buildAllCost']);
        $this->assertSame(9900.0, $result['buyAllCost']);
        $this->assertSame(9900.0, $result['optimalMixCost']);
        $this->assertSame(22800.0, $result['totalProductionCost']);
        $this->assertSame(305.7, $result['marginPercent']);
        $this->assertSame([self::NOCXIUM], $result['missingPriceTypeIds']);
    }

    public function testOneUnknownBuyCostMakesTheBuyAllCostUnknownWhileTheBuildAllAndOptimalMixCostsStayKnown(): void
    {
        $result = $this->analyze(
            [self::PLASMA_THRUSTER_COMPONENT, self::PHOTON_MICROPROCESSOR_COMPONENT],
            $this->jitaPricesWithout(self::PHOTON_MICROPROCESSOR),
        );

        $this->assertSame(
            [self::PLASMA_THRUSTER => 'buy', self::PHOTON_MICROPROCESSOR => 'build'],
            array_column($result['components'], 'verdict', 'typeId'),
        );
        $this->assertSame(9450.0, $result['buildAllCost']);
        $this->assertNull($result['buyAllCost']);
        $this->assertSame(9250.0, $result['optimalMixCost']);
        $this->assertSame(22150.0, $result['totalProductionCost']);
        $this->assertSame(317.61, $result['marginPercent']);
        $this->assertSame([self::PHOTON_MICROPROCESSOR], $result['missingPriceTypeIds']);
    }

    public function testOneComponentWithAnUnknownVerdictMakesTheOptimalMixCostUnknownDespiteAPricedComponent(): void
    {
        $result = $this->analyze(
            [self::PLASMA_THRUSTER_COMPONENT, self::PHOTON_MICROPROCESSOR_COMPONENT],
            $this->jitaPricesWithout(self::NOCXIUM, self::PHOTON_MICROPROCESSOR),
        );

        $this->assertSame(
            [self::PLASMA_THRUSTER => 'buy', self::PHOTON_MICROPROCESSOR => 'unknown'],
            array_column($result['components'], 'verdict', 'typeId'),
        );
        $this->assertNull($result['buildAllCost']);
        $this->assertNull($result['buyAllCost']);
        $this->assertNull($result['optimalMixCost']);
        $this->assertNull($result['totalProductionCost']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame([self::NOCXIUM, self::PHOTON_MICROPROCESSOR], $result['missingPriceTypeIds']);
    }

    public function testComponentsWithUnknownSavingsComeAfterTheOthersByAscendingTypeId(): void
    {
        $result = $this->analyze(
            [self::PHOTON_MICROPROCESSOR_COMPONENT, self::PLASMA_THRUSTER_COMPONENT, self::GRAVIMETRIC_SENSOR_CLUSTER_COMPONENT],
            $this->jitaPricesWithout(self::NOCXIUM, self::GRAVIMETRIC_SENSOR_CLUSTER),
        );

        $this->assertSame(
            [
                [self::PLASMA_THRUSTER, -200.0],
                [self::GRAVIMETRIC_SENSOR_CLUSTER, null],
                [self::PHOTON_MICROPROCESSOR, null],
            ],
            array_map(static fn (array $component): array => [$component['typeId'], $component['savings']], $result['components']),
        );
    }

    public function testMaterialWithoutJitaPriceInTwoComponentsIsListedOnce(): void
    {
        $photonMicroprocessorConsumingMexallon = ['materials' => [self::ISOGEN => 40, self::MEXALLON => 12]] + self::PHOTON_MICROPROCESSOR_COMPONENT;

        $result = $this->analyze(
            [self::PLASMA_THRUSTER_COMPONENT, $photonMicroprocessorConsumingMexallon],
            $this->jitaPricesWithout(self::MEXALLON),
        );

        $this->assertSame([null, null], array_column($result['components'], 'buildCost'));
        $this->assertSame(9900.0, $result['optimalMixCost']);
        $this->assertSame([self::MEXALLON], $result['missingPriceTypeIds']);
    }

    public function testZeroMaterialAndLeafPricesAreKnownPrices(): void
    {
        $result = $this->analyze([self::PLASMA_THRUSTER_COMPONENT], [self::MEXALLON => 0.0, self::MORPHITE => 0.0] + self::JITA_PRICES);

        $plasmaThruster = $result['components'][0];
        $this->assertSame(
            ['typeId' => self::MEXALLON, 'typeName' => 'Mexallon', 'quantity' => 20, 'unitPrice' => 0.0, 'totalPrice' => 0.0],
            $plasmaThruster['buildMaterials'][1],
        );
        $this->assertSame(700.0, $plasmaThruster['buildCost']);
        $this->assertSame('build', $plasmaThruster['verdict']);
        $this->assertSame(800.0, $plasmaThruster['savings']);
        $this->assertSame(53.33, $plasmaThruster['savingsPercent']);
        $this->assertSame(1600.0, $result['totalProductionCost']);
        $this->assertSame(5681.25, $result['marginPercent']);
        $this->assertSame([], $result['missingPriceTypeIds']);
    }

    public function testZeroBuyPriceIsAKnownPriceSoTheFreePurchaseWins(): void
    {
        $result = $this->analyze([self::PLASMA_THRUSTER_COMPONENT], [self::PLASMA_THRUSTER => 0.0] + self::JITA_PRICES);

        $plasmaThruster = $result['components'][0];
        $this->assertSame('buy', $plasmaThruster['verdict']);
        $this->assertSame(0.0, $plasmaThruster['buyCostJita']);
        $this->assertSame(-1700.0, $plasmaThruster['savings']);
        $this->assertSame(100.0, $plasmaThruster['savingsPercent']);
        $this->assertSame(0.0, $result['buyAllCost']);
        $this->assertSame(0.0, $result['optimalMixCost']);
        $this->assertSame(12900.0, $result['totalProductionCost']);
        $this->assertSame(617.05, $result['marginPercent']);
        $this->assertSame([], $result['missingPriceTypeIds']);
    }

    private function analyze(array $components, array $jitaPrices, array $structurePrices = []): array
    {
        $treeService = $this->createStub(IndustryTreeService::class);
        $treeService->method('buildProductionTree')->willReturn($this->sabreTree($components));

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPricesWithFallback')->willReturnCallback(
            static fn (array $typeIds): array => array_combine(
                array_values($typeIds),
                array_map(static fn (int $typeId): ?float => $jitaPrices[$typeId] ?? null, array_values($typeIds)),
            ),
        );

        $structureMarketService = $this->createStub(StructureMarketService::class);
        $structureMarketService->method('getLowestSellPrice')->willReturn(null);
        $structureMarketService->method('getLowestSellPrices')->willReturnCallback(
            static fn (int $structureId, array $typeIds): array => array_combine(
                array_values($typeIds),
                array_map(static fn (int $typeId): ?float => $structurePrices[$typeId] ?? null, array_values($typeIds)),
            ),
        );

        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findMaterialsForBlueprints')->willReturnCallback(
            static fn (array $blueprintTypeIds): array => [$blueprintTypeIds[0] => [['materialTypeId' => $blueprintTypeIds[0], 'quantity' => 1]]],
        );

        $esiCostIndexService = $this->createStub(EsiCostIndexService::class);
        $esiCostIndexService->method('calculateEiv')->willReturnCallback(
            static fn (array $materials): float => (float) $materials[0]['materialTypeId'],
        );
        $esiCostIndexService->method('calculateJobInstallCost')->willReturnCallback(
            static fn (float $eiv): float => self::JOB_INSTALL_COST_BY_BLUEPRINT[(int) $eiv],
        );

        $inventionService = $this->createStub(InventionService::class);
        $inventionService->method('isT2')->willReturn(true);

        $service = new BuyVsBuildService(
            $treeService,
            $jitaMarketService,
            $structureMarketService,
            $esiCostIndexService,
            $inventionService,
            new TypeNameResolver($this->sdeTypeRepositoryKnowing([
                self::SABRE => 'Sabre',
                self::MORPHITE => 'Morphite',
                self::PLASMA_THRUSTER => 'Plasma Thruster',
                self::PHOTON_MICROPROCESSOR => 'Photon Microprocessor',
                self::GRAVIMETRIC_SENSOR_CLUSTER => 'Gravimetric Sensor Cluster',
                self::TRITANIUM => 'Tritanium',
                self::MEXALLON => 'Mexallon',
                self::ISOGEN => 'Isogen',
                self::NOCXIUM => 'Nocxium',
                self::ZYDRINE => 'Zydrine',
            ])),
            $this->createStub(IndustryBlacklistService::class),
            $materialRepository,
        );

        return $service->analyze(
            self::SABRE,
            self::RUNS,
            self::ME_LEVEL,
            self::SOLAR_SYSTEM_ID,
            self::SELL_STRUCTURE_ID,
            self::BROKER_FEE_RATE,
            self::SALES_TAX_RATE,
            null,
        );
    }

    private function jitaPricesWithout(int ...$typeIds): array
    {
        return array_diff_key(self::JITA_PRICES, array_flip($typeIds));
    }

    private function sabreTree(array $components): array
    {
        $rootMaterials = [
            ['typeId' => self::MORPHITE, 'typeName' => 'Morphite', 'quantity' => self::MORPHITE_LEAF_QUANTITY, 'isBuildable' => false, 'activityType' => null],
        ];
        foreach ($components as $component) {
            $componentMaterials = [];
            foreach ($component['materials'] as $materialTypeId => $quantity) {
                $componentMaterials[] = ['typeId' => $materialTypeId, 'typeName' => 'Tree Material Name', 'quantity' => $quantity, 'isBuildable' => false, 'activityType' => null];
            }
            $rootMaterials[] = [
                'typeId' => $component['typeId'],
                'typeName' => 'Tree Component Name',
                'quantity' => $component['quantity'],
                'isBuildable' => true,
                'activityType' => 'manufacturing',
                'blueprint' => [
                    'blueprintTypeId' => $component['blueprintTypeId'],
                    'productTypeId' => $component['typeId'],
                    'productTypeName' => 'Tree Component Name',
                    'quantity' => $component['quantity'],
                    'runs' => $component['runs'],
                    'outputPerRun' => 10,
                    'depth' => 1,
                    'activityType' => 'manufacturing',
                    'hasCopy' => false,
                    'materials' => $componentMaterials,
                    'structureBonus' => 0.0,
                    'structureName' => null,
                    'productCategory' => null,
                ],
            ];
        }

        return [
            'blueprintTypeId' => self::SABRE_BLUEPRINT,
            'productTypeId' => self::SABRE,
            'productTypeName' => 'Sabre',
            'quantity' => self::RUNS,
            'runs' => self::RUNS,
            'outputPerRun' => 1,
            'depth' => 0,
            'activityType' => 'manufacturing',
            'hasCopy' => false,
            'materials' => $rootMaterials,
            'structureBonus' => 0.0,
            'structureName' => null,
            'productCategory' => null,
        ];
    }
}
