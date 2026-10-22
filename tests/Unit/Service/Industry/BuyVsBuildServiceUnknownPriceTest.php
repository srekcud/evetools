<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\BuyVsBuildService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuyVsBuildService::class)]
#[Group('issue-8')]
final class BuyVsBuildServiceUnknownPriceTest extends TestCase
{
    use BuildsBuyVsBuildOnGoldenSde;

    private const int FACTORY_SYSTEM_ID = 30_002_187;
    private const int SELL_STRUCTURE_ID = 1_035_466_617_946;
    private const float BROKER_FEE_RATE = 0.03;
    private const float SALES_TAX_RATE = 0.045;
    private const int RUNS = 1;
    private const int ME_LEVEL = 2;

    private const int HAIL_L = 12779;
    private const int MORPHITE = 11399;
    private const int RAM_AMMUNITION_TECH = 11476;
    private const int FERNITE_CARBIDE = 16673;
    private const int FULLERIDES = 16679;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;
    private const int NOCXIUM = 38;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;
    private const int CARBON_POLYMERS = 16659;
    private const int PLATINUM_TECHNITE = 16662;
    private const int SCANDIUM = 16639;
    private const int VANADIUM = 16642;
    private const int EVAPORITE_DEPOSITS = 16635;
    private const int SILICATES = 16636;
    private const int ENRICHED_URANIUM = 44;
    private const int OXYGEN = 3683;
    private const int MECHANICAL_PARTS = 3689;
    private const int COOLANT = 9832;
    private const int ROBOTICS = 9848;
    private const int HEAVY_WATER = 16272;
    private const int LIQUID_OZONE = 16273;
    private const int STRONTIUM_CLATHRATES = 16275;
    private const int HYDROGEN_ISOTOPES = 17889;

    private const array COST_INDICES = [
        self::FACTORY_SYSTEM_ID => ['manufacturing' => 0.05, 'reaction' => 0.1, 'copying' => 0.03, 'invention' => 0.06],
    ];

    private const array HAIL_L_JITA_PRICES = [
        self::HAIL_L => 500.0,
        self::MORPHITE => 10000.0,
        self::RAM_AMMUNITION_TECH => 20000.0,
        self::FERNITE_CARBIDE => 60.0,
        self::FULLERIDES => 160.0,
        self::TRITANIUM => 5.0,
        self::PYERITE => 10.0,
        self::MEXALLON => 50.0,
        self::ISOGEN => 100.0,
        self::NOCXIUM => 800.0,
        self::HYDROGEN_FUEL_BLOCK => 20000.0,
        self::FERNITE_ALLOY => 300.0,
        self::CERAMIC_POWDER => 250.0,
        self::NITROGEN_FUEL_BLOCK => 21000.0,
        self::CARBON_POLYMERS => 500.0,
        self::PLATINUM_TECHNITE => 450.0,
        self::STUB_DATACORE_A => 3500.0,
        self::STUB_DATACORE_B => 4200.0,
    ];

    private const array HAIL_L_ADJUSTED_PRICES = [
        self::TRITANIUM => 4.0,
        self::PYERITE => 8.0,
        self::MEXALLON => 40.0,
        self::ISOGEN => 90.0,
        self::NOCXIUM => 700.0,
        self::MORPHITE => 9000.0,
        self::RAM_AMMUNITION_TECH => 22000.0,
        self::FERNITE_CARBIDE => 50.0,
        self::FULLERIDES => 145.0,
        self::HYDROGEN_FUEL_BLOCK => 18000.0,
        self::FERNITE_ALLOY => 280.0,
        self::CERAMIC_POWDER => 230.0,
        self::NITROGEN_FUEL_BLOCK => 16000.0,
        self::CARBON_POLYMERS => 390.0,
        self::PLATINUM_TECHNITE => 350.0,
    ];

    private const array FERNITE_CARBIDE_JITA_PRICES = [
        self::FERNITE_CARBIDE => 60.0,
        self::HYDROGEN_FUEL_BLOCK => 20000.0,
        self::FERNITE_ALLOY => 300.0,
        self::CERAMIC_POWDER => 250.0,
        self::SCANDIUM => 50.0,
        self::VANADIUM => 70.0,
        self::EVAPORITE_DEPOSITS => 20.0,
        self::SILICATES => 30.0,
        self::ENRICHED_URANIUM => 100.0,
        self::OXYGEN => 200.0,
        self::MECHANICAL_PARTS => 300.0,
        self::COOLANT => 400.0,
        self::ROBOTICS => 500.0,
        self::HEAVY_WATER => 10.0,
        self::LIQUID_OZONE => 20.0,
        self::STRONTIUM_CLATHRATES => 30.0,
        self::HYDROGEN_ISOTOPES => 40.0,
    ];

    private const array FERNITE_CARBIDE_ADJUSTED_PRICES = [
        self::HYDROGEN_FUEL_BLOCK => 18000.0,
        self::FERNITE_ALLOY => 280.0,
        self::CERAMIC_POWDER => 230.0,
        self::SCANDIUM => 46.0,
        self::VANADIUM => 64.0,
        self::EVAPORITE_DEPOSITS => 17.0,
        self::SILICATES => 27.0,
        self::ENRICHED_URANIUM => 95.0,
        self::OXYGEN => 190.0,
        self::MECHANICAL_PARTS => 280.0,
        self::COOLANT => 380.0,
        self::ROBOTICS => 470.0,
        self::HEAVY_WATER => 9.0,
        self::LIQUID_OZONE => 18.0,
        self::STRONTIUM_CLATHRATES => 27.0,
        self::HYDROGEN_ISOTOPES => 36.0,
    ];

    public function testComponentWithoutStructurePriceIsBoughtAtItsJitaPrice(): void
    {
        $result = $this->analyzeHailL(self::HAIL_L_JITA_PRICES);

        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame('buy', $ram['verdict']);
        $this->assertSame(54674.33, $ram['buildCost']);
        $this->assertSame(20000.0, $ram['buyCostJita']);
        $this->assertNull($ram['buyCostStructure']);
        $this->assertSame(-34674.33, $ram['savings']);
        $this->assertSame(63.42, $ram['savingsPercent']);
        $this->assertSame(451711.83, $result['buildAllCost']);
        $this->assertSame(384560.0, $result['buyAllCost']);
        $this->assertSame(383252.5, $result['optimalMixCost']);
        $this->assertSame(582567.84, $result['totalProductionCost']);
        $this->assertSame(296.95, $result['marginPercent']);
        $this->assertSame([], $result['missingData']);
    }

    public function testComponentWithoutAnyBuyPriceIsBuiltWithUnknownSavingsAndAnUnknownBuyAllCost(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPricesWithout(self::RAM_AMMUNITION_TECH));

        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame('build', $ram['verdict']);
        $this->assertSame(54674.33, $ram['buildCost']);
        $this->assertNull($ram['buyCostJita']);
        $this->assertNull($ram['buyCostStructure']);
        $this->assertNull($ram['savings']);
        $this->assertNull($ram['savingsPercent']);
        $this->assertSame([self::marketPriceMissing(self::RAM_AMMUNITION_TECH)], $ram['missingData']);
        $this->assertSame(451711.83, $result['buildAllCost']);
        $this->assertNull($result['buyAllCost']);
        $this->assertSame(417926.83, $result['optimalMixCost']);
        $this->assertSame(617242.17, $result['totalProductionCost']);
        $this->assertSame(274.65, $result['marginPercent']);
        $this->assertSame([self::RAM_AMMUNITION_TECH, self::FERNITE_CARBIDE], $result['buildTypeIds']);
        $this->assertSame([self::FULLERIDES], $result['buyTypeIds']);
        $this->assertSame([self::marketPriceMissing(self::RAM_AMMUNITION_TECH)], $result['missingData']);
    }

    public function testComponentWithAMaterialWithoutJitaPriceHasAnUnknownBuildCostAndIsBought(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPricesWithout(self::MEXALLON));

        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame([
            ['typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'quantity' => 501, 'unitPrice' => 5.0, 'totalPrice' => 2505.0],
            ['typeId' => self::PYERITE, 'typeName' => 'Pyerite', 'quantity' => 400, 'unitPrice' => 10.0, 'totalPrice' => 4000.0],
            ['typeId' => self::MEXALLON, 'typeName' => 'Mexallon', 'quantity' => 200, 'unitPrice' => null, 'totalPrice' => null],
            ['typeId' => self::ISOGEN, 'typeName' => 'Isogen', 'quantity' => 74, 'unitPrice' => 100.0, 'totalPrice' => 7400.0],
            ['typeId' => self::NOCXIUM, 'typeName' => 'Nocxium', 'quantity' => 33, 'unitPrice' => 800.0, 'totalPrice' => 26400.0],
        ], $ram['buildMaterials']);
        $this->assertNull($ram['buildCost']);
        $this->assertSame(4369.33, $ram['buildJobInstallCost']);
        $this->assertSame('buy', $ram['verdict']);
        $this->assertSame(20000.0, $ram['buyCostJita']);
        $this->assertNull($ram['savings']);
        $this->assertNull($ram['savingsPercent']);
        $this->assertSame([self::marketPriceMissing(self::MEXALLON)], $ram['missingData']);
        $this->assertNull($result['buildAllCost']);
        $this->assertSame(384560.0, $result['buyAllCost']);
        $this->assertSame(383252.5, $result['optimalMixCost']);
        $this->assertSame(582567.84, $result['totalProductionCost']);
        $this->assertSame(296.95, $result['marginPercent']);
        $this->assertSame([self::FERNITE_CARBIDE], $result['buildTypeIds']);
        $this->assertSame([self::RAM_AMMUNITION_TECH, self::FULLERIDES], $result['buyTypeIds']);
        $this->assertSame([self::marketPriceMissing(self::MEXALLON)], $result['missingData']);
    }

    public function testComponentWithoutBuildCostNorBuyCostHasAnUnknownVerdictAndMakesEveryTotalUnknown(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPricesWithout(self::MEXALLON, self::RAM_AMMUNITION_TECH));

        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame('unknown', $ram['verdict']);
        $this->assertNull($ram['buildCost']);
        $this->assertNull($ram['buyCostJita']);
        $this->assertNull($ram['savings']);
        $this->assertNull($ram['savingsPercent']);
        $this->assertSame([self::marketPriceMissing(self::MEXALLON), self::marketPriceMissing(self::RAM_AMMUNITION_TECH)], $ram['missingData']);
        $this->assertNull($result['buildAllCost']);
        $this->assertNull($result['buyAllCost']);
        $this->assertNull($result['optimalMixCost']);
        $this->assertNull($result['totalProductionCost']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame(500.0, $result['sellPrice']);
        $this->assertSame([self::FERNITE_CARBIDE], $result['buildTypeIds']);
        $this->assertSame([self::FULLERIDES], $result['buyTypeIds']);
        $this->assertSame([self::marketPriceMissing(self::MEXALLON), self::marketPriceMissing(self::RAM_AMMUNITION_TECH)], $result['missingData']);
    }

    public function testFinalJobLeafWithoutJitaPriceMakesTheTotalProductionCostAndTheMarginUnknown(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPricesWithout(self::MORPHITE));

        $this->assertSame('buy', $this->componentOf($result, self::RAM_AMMUNITION_TECH)['verdict']);
        $this->assertSame(451711.83, $result['buildAllCost']);
        $this->assertSame(384560.0, $result['buyAllCost']);
        $this->assertSame(383252.5, $result['optimalMixCost']);
        $this->assertNull($result['totalProductionCost']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame([self::marketPriceMissing(self::MORPHITE)], $result['missingData']);
    }

    public function testFullyPricedAnalysisHasNoMissingData(): void
    {
        $result = $this->analyzeHailL(self::HAIL_L_JITA_PRICES);

        $this->assertSame([
            'typeId' => self::HAIL_L,
            'typeName' => 'Hail L',
            'isT2' => true,
            'runs' => self::RUNS,
            'totalProductionCost' => 582567.84,
            'sellPrice' => 500.0,
            'marginPercent' => 296.95,
            'components' => [
                [
                    'typeId' => self::FERNITE_CARBIDE,
                    'typeName' => 'Fernite Carbide',
                    'quantity' => 2940,
                    'stage' => 'Reaction',
                    'buildCost' => 175092.5,
                    'buildMaterials' => [
                        ['typeId' => self::HYDROGEN_FUEL_BLOCK, 'typeName' => 'Hydrogen Fuel Block', 'quantity' => 5, 'unitPrice' => 20000.0, 'totalPrice' => 100000.0],
                        ['typeId' => self::FERNITE_ALLOY, 'typeName' => 'Fernite Alloy', 'quantity' => 100, 'unitPrice' => 300.0, 'totalPrice' => 30000.0],
                        ['typeId' => self::CERAMIC_POWDER, 'typeName' => 'Ceramic Powder', 'quantity' => 100, 'unitPrice' => 250.0, 'totalPrice' => 25000.0],
                    ],
                    'buildJobInstallCost' => 20092.5,
                    'buyCostJita' => 176400.0,
                    'buyCostStructure' => null,
                    'verdict' => 'build',
                    'savings' => 1307.5,
                    'savingsPercent' => 0.74,
                    'meUsed' => 0,
                    'runs' => 1,
                    'missingData' => [],
                ],
                [
                    'typeId' => self::FULLERIDES,
                    'typeName' => 'Fullerides',
                    'quantity' => 1176,
                    'stage' => 'Reaction',
                    'buildCost' => 221945.0,
                    'buildMaterials' => [
                        ['typeId' => self::NITROGEN_FUEL_BLOCK, 'typeName' => 'Nitrogen Fuel Block', 'quantity' => 5, 'unitPrice' => 21000.0, 'totalPrice' => 105000.0],
                        ['typeId' => self::CARBON_POLYMERS, 'typeName' => 'Carbon Polymers', 'quantity' => 100, 'unitPrice' => 500.0, 'totalPrice' => 50000.0],
                        ['typeId' => self::PLATINUM_TECHNITE, 'typeName' => 'Platinum Technite', 'quantity' => 100, 'unitPrice' => 450.0, 'totalPrice' => 45000.0],
                    ],
                    'buildJobInstallCost' => 21945.0,
                    'buyCostJita' => 188160.0,
                    'buyCostStructure' => null,
                    'verdict' => 'buy',
                    'savings' => -33785.0,
                    'savingsPercent' => 15.22,
                    'meUsed' => 0,
                    'runs' => 1,
                    'missingData' => [],
                ],
                [
                    'typeId' => self::RAM_AMMUNITION_TECH,
                    'typeName' => 'R.A.M.- Ammunition Tech',
                    'quantity' => 1,
                    'stage' => 'T1 Component',
                    'buildCost' => 54674.33,
                    'buildMaterials' => [
                        ['typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'quantity' => 501, 'unitPrice' => 5.0, 'totalPrice' => 2505.0],
                        ['typeId' => self::PYERITE, 'typeName' => 'Pyerite', 'quantity' => 400, 'unitPrice' => 10.0, 'totalPrice' => 4000.0],
                        ['typeId' => self::MEXALLON, 'typeName' => 'Mexallon', 'quantity' => 200, 'unitPrice' => 50.0, 'totalPrice' => 10000.0],
                        ['typeId' => self::ISOGEN, 'typeName' => 'Isogen', 'quantity' => 74, 'unitPrice' => 100.0, 'totalPrice' => 7400.0],
                        ['typeId' => self::NOCXIUM, 'typeName' => 'Nocxium', 'quantity' => 33, 'unitPrice' => 800.0, 'totalPrice' => 26400.0],
                    ],
                    'buildJobInstallCost' => 4369.33,
                    'buyCostJita' => 20000.0,
                    'buyCostStructure' => null,
                    'verdict' => 'buy',
                    'savings' => -34674.33,
                    'savingsPercent' => 63.42,
                    'meUsed' => 10,
                    'runs' => 1,
                    'missingData' => [],
                ],
            ],
            'buildAllCost' => 451711.83,
            'buyAllCost' => 384560.0,
            'optimalMixCost' => 383252.5,
            'buildTypeIds' => [self::FERNITE_CARBIDE],
            'buyTypeIds' => [self::RAM_AMMUNITION_TECH, self::FULLERIDES],
            'missingData' => [],
        ], $result);
    }

    public function testComponentWithOnlyAStructurePriceHasAKnownBuyCostAndNoMissingData(): void
    {
        $result = $this->analyzeHailL(
            $this->hailLJitaPricesWithout(self::RAM_AMMUNITION_TECH),
            [self::RAM_AMMUNITION_TECH => 25000.0],
        );

        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame('buy', $ram['verdict']);
        $this->assertNull($ram['buyCostJita']);
        $this->assertSame(25000.0, $ram['buyCostStructure']);
        $this->assertSame(-29674.33, $ram['savings']);
        $this->assertSame(54.27, $ram['savingsPercent']);
        $this->assertSame([], $ram['missingData']);
        $this->assertSame(389560.0, $result['buyAllCost']);
        $this->assertSame(587567.84, $result['totalProductionCost']);
        $this->assertSame(293.57, $result['marginPercent']);
        $this->assertSame([], $result['missingData']);
    }

    public function testOneUnknownBuildCostMakesTheBuildAllCostUnknownWhileTheBuyAllAndOptimalMixCostsStayKnown(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPricesWithout(self::PLATINUM_TECHNITE));

        $this->assertSame(
            [self::FERNITE_CARBIDE => 'build', self::RAM_AMMUNITION_TECH => 'buy', self::FULLERIDES => 'buy'],
            array_column($result['components'], 'verdict', 'typeId'),
        );
        $this->assertNull($result['buildAllCost']);
        $this->assertSame(384560.0, $result['buyAllCost']);
        $this->assertSame(383252.5, $result['optimalMixCost']);
        $this->assertSame(582567.84, $result['totalProductionCost']);
        $this->assertSame(296.95, $result['marginPercent']);
        $this->assertSame([self::marketPriceMissing(self::PLATINUM_TECHNITE)], $result['missingData']);
    }

    public function testOneUnknownBuyCostMakesTheBuyAllCostUnknownWhileTheBuildAllAndOptimalMixCostsStayKnown(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPricesWithout(self::FERNITE_CARBIDE));

        $this->assertSame(
            [self::FULLERIDES => 'buy', self::RAM_AMMUNITION_TECH => 'buy', self::FERNITE_CARBIDE => 'build'],
            array_column($result['components'], 'verdict', 'typeId'),
        );
        $this->assertSame(451711.83, $result['buildAllCost']);
        $this->assertNull($result['buyAllCost']);
        $this->assertSame(383252.5, $result['optimalMixCost']);
        $this->assertSame(582567.84, $result['totalProductionCost']);
        $this->assertSame(296.95, $result['marginPercent']);
        $this->assertSame([self::marketPriceMissing(self::FERNITE_CARBIDE)], $result['missingData']);
    }

    public function testOneComponentWithAnUnknownVerdictMakesTheOptimalMixCostUnknownDespitePricedComponents(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPricesWithout(self::PLATINUM_TECHNITE, self::FULLERIDES));

        $this->assertSame(
            [self::FERNITE_CARBIDE => 'build', self::RAM_AMMUNITION_TECH => 'buy', self::FULLERIDES => 'unknown'],
            array_column($result['components'], 'verdict', 'typeId'),
        );
        $this->assertNull($result['buildAllCost']);
        $this->assertNull($result['buyAllCost']);
        $this->assertNull($result['optimalMixCost']);
        $this->assertNull($result['totalProductionCost']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame([self::FERNITE_CARBIDE], $result['buildTypeIds']);
        $this->assertSame([self::RAM_AMMUNITION_TECH], $result['buyTypeIds']);
        $this->assertSame([self::marketPriceMissing(self::PLATINUM_TECHNITE), self::marketPriceMissing(self::FULLERIDES)], $result['missingData']);
    }

    public function testComponentsWithUnknownSavingsComeAfterTheOthersByAscendingTypeId(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPricesWithout(self::RAM_AMMUNITION_TECH, self::HYDROGEN_FUEL_BLOCK));

        $this->assertSame(
            [
                [self::FULLERIDES, -33785.0],
                [self::RAM_AMMUNITION_TECH, null],
                [self::FERNITE_CARBIDE, null],
            ],
            array_map(static fn (array $component): array => [$component['typeId'], $component['savings']], $result['components']),
        );
    }

    public function testMaterialWithoutJitaPriceInTwoComponentsIsListedOnce(): void
    {
        $result = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: array_diff_key(self::FERNITE_CARBIDE_JITA_PRICES, [self::HYDROGEN_FUEL_BLOCK => true]),
            structurePrices: [],
            adjustedPrices: self::FERNITE_CARBIDE_ADJUSTED_PRICES,
            costIndices: self::COST_INDICES,
        )->analyze(self::FERNITE_CARBIDE, self::RUNS, 10, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, self::BROKER_FEE_RATE, self::SALES_TAX_RATE, null);

        $this->assertSame(
            [
                self::HYDROGEN_FUEL_BLOCK => [37427.95, null, 'build', [self::marketPriceMissing(self::HYDROGEN_FUEL_BLOCK)]],
                self::FERNITE_ALLOY => [null, 30000.0, 'buy', [self::marketPriceMissing(self::HYDROGEN_FUEL_BLOCK)]],
                self::CERAMIC_POWDER => [null, 25000.0, 'buy', [self::marketPriceMissing(self::HYDROGEN_FUEL_BLOCK)]],
            ],
            array_combine(
                array_column($result['components'], 'typeId'),
                array_map(static fn (array $component): array => [$component['buildCost'], $component['buyCostJita'], $component['verdict'], $component['missingData']], $result['components']),
            ),
        );
        $this->assertSame(92427.95, $result['optimalMixCost']);
        $this->assertSame(112520.45, $result['totalProductionCost']);
        $this->assertSame(393.24, $result['marginPercent']);
        $this->assertSame([self::marketPriceMissing(self::HYDROGEN_FUEL_BLOCK)], $result['missingData']);
    }

    public function testZeroMaterialAndLeafPricesAreKnownPrices(): void
    {
        $result = $this->analyzeHailL([self::MEXALLON => 0.0, self::MORPHITE => 0.0] + self::HAIL_L_JITA_PRICES);

        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame(
            ['typeId' => self::MEXALLON, 'typeName' => 'Mexallon', 'quantity' => 200, 'unitPrice' => 0.0, 'totalPrice' => 0.0],
            $ram['buildMaterials'][2],
        );
        $this->assertSame(44674.33, $ram['buildCost']);
        $this->assertSame('buy', $ram['verdict']);
        $this->assertSame(-24674.33, $ram['savings']);
        $this->assertSame(55.23, $ram['savingsPercent']);
        $this->assertSame(441711.83, $result['buildAllCost']);
        $this->assertSame(432567.84, $result['totalProductionCost']);
        $this->assertSame(434.6, $result['marginPercent']);
        $this->assertSame([], $result['missingData']);
    }

    public function testZeroBuyPriceIsAKnownPriceSoTheFreePurchaseWins(): void
    {
        $result = $this->analyzeHailL([self::RAM_AMMUNITION_TECH => 0.0] + self::HAIL_L_JITA_PRICES);

        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame('buy', $ram['verdict']);
        $this->assertSame(0.0, $ram['buyCostJita']);
        $this->assertSame(-54674.33, $ram['savings']);
        $this->assertSame(100.0, $ram['savingsPercent']);
        $this->assertSame(364560.0, $result['buyAllCost']);
        $this->assertSame(363252.5, $result['optimalMixCost']);
        $this->assertSame(562567.84, $result['totalProductionCost']);
        $this->assertSame(311.06, $result['marginPercent']);
        $this->assertSame([], $result['missingData']);
    }

    private function analyzeHailL(array $jitaPrices, array $structurePrices = []): array
    {
        return $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: $jitaPrices,
            structurePrices: [self::SELL_STRUCTURE_ID => $structurePrices],
            adjustedPrices: self::HAIL_L_ADJUSTED_PRICES,
            costIndices: self::COST_INDICES,
        )->analyze(self::HAIL_L, self::RUNS, self::ME_LEVEL, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, self::BROKER_FEE_RATE, self::SALES_TAX_RATE, null);
    }

    private function hailLJitaPricesWithout(int ...$typeIds): array
    {
        return array_diff_key(self::HAIL_L_JITA_PRICES, array_flip($typeIds));
    }

    private function componentOf(array $result, int $typeId): array
    {
        foreach ($result['components'] as $component) {
            if ($component['typeId'] === $typeId) {
                return $component;
            }
        }

        $this->fail("No component {$typeId} in the buy-vs-build output");
    }
}
