<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\BuyVsBuildService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuyVsBuildService::class)]
#[Group('issue-7')]
final class BuyVsBuildServiceUnknownInstallCostTest extends TestCase
{
    use BuildsBuyVsBuildOnGoldenSde;

    private const int FACTORY_SYSTEM_ID = 30_002_187;
    private const int UNINDEXED_SYSTEM_ID = 30_000_001;
    private const int MANUFACTURING_ONLY_SYSTEM_ID = 30_002_053;
    private const int REACTION_ONLY_SYSTEM_ID = 30_045_352;
    private const int SELL_STRUCTURE_ID = 1_035_466_617_946;
    private const int RUNS = 1;
    private const int ME_LEVEL = 2;
    private const float BROKER_FEE_RATE = 0.03;
    private const float SALES_TAX_RATE = 0.045;

    private const array COST_INDICES = [
        self::FACTORY_SYSTEM_ID => ['manufacturing' => 0.05, 'reaction' => 0.1],
        self::MANUFACTURING_ONLY_SYSTEM_ID => ['manufacturing' => 0.04],
        self::REACTION_ONLY_SYSTEM_ID => ['reaction' => 0.08],
    ];

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

    private const array JITA_PRICES = [
        self::HAIL_L => 520.0,
        self::MORPHITE => 9800.0,
        self::RAM_AMMUNITION_TECH => 60000.0,
        self::FERNITE_CARBIDE => 64.0,
        self::FULLERIDES => 175.0,
        self::TRITANIUM => 5.0,
        self::PYERITE => 11.0,
        self::MEXALLON => 52.0,
        self::ISOGEN => 105.0,
        self::NOCXIUM => 790.0,
        self::HYDROGEN_FUEL_BLOCK => 19500.0,
        self::FERNITE_ALLOY => 310.0,
        self::CERAMIC_POWDER => 260.0,
        self::NITROGEN_FUEL_BLOCK => 20500.0,
        self::CARBON_POLYMERS => 480.0,
        self::PLATINUM_TECHNITE => 440.0,
        self::SCANDIUM => 48.0,
        self::VANADIUM => 72.0,
        self::EVAPORITE_DEPOSITS => 21.0,
        self::SILICATES => 33.0,
        self::ENRICHED_URANIUM => 102.0,
        self::OXYGEN => 205.0,
        self::MECHANICAL_PARTS => 305.0,
        self::COOLANT => 395.0,
        self::ROBOTICS => 505.0,
        self::HEAVY_WATER => 12.0,
        self::LIQUID_OZONE => 23.0,
        self::STRONTIUM_CLATHRATES => 34.0,
        self::HYDROGEN_ISOTOPES => 43.0,
    ];

    private const array ADJUSTED_PRICES = [
        self::MORPHITE => 9000.0,
        self::RAM_AMMUNITION_TECH => 22000.0,
        self::FERNITE_CARBIDE => 50.0,
        self::FULLERIDES => 145.0,
        self::TRITANIUM => 4.0,
        self::PYERITE => 8.0,
        self::MEXALLON => 40.0,
        self::ISOGEN => 90.0,
        self::NOCXIUM => 700.0,
        self::HYDROGEN_FUEL_BLOCK => 18000.0,
        self::FERNITE_ALLOY => 280.0,
        self::CERAMIC_POWDER => 230.0,
        self::NITROGEN_FUEL_BLOCK => 16000.0,
        self::CARBON_POLYMERS => 390.0,
        self::PLATINUM_TECHNITE => 350.0,
        self::SCANDIUM => 46.0,
        self::VANADIUM => 64.0,
        self::EVAPORITE_DEPOSITS => 17.0,
        self::SILICATES => 27.0,
    ];

    public function testFullyKnownInstallCostsLeaveTheAnalysisUnchangedWithNoUnknownInstallCostReason(): void
    {
        $result = $this->analyzeHailL(self::FACTORY_SYSTEM_ID);

        $this->assertSame([
            'typeId' => self::HAIL_L,
            'typeName' => 'Hail L',
            'isT2' => true,
            'runs' => self::RUNS,
            'totalProductionCost' => 598956.8,
            'sellPrice' => 520.0,
            'marginPercent' => 301.53,
            'components' => [
                [
                    'typeId' => self::FERNITE_CARBIDE,
                    'typeName' => 'Fernite Carbide',
                    'quantity' => 2940,
                    'stage' => 'Reaction',
                    'buildCost' => 168600.0,
                    'buildMaterials' => [
                        ['typeId' => self::HYDROGEN_FUEL_BLOCK, 'typeName' => 'Hydrogen Fuel Block', 'quantity' => 5, 'unitPrice' => 19500.0, 'totalPrice' => 97500.0],
                        ['typeId' => self::FERNITE_ALLOY, 'typeName' => 'Fernite Alloy', 'quantity' => 100, 'unitPrice' => 310.0, 'totalPrice' => 31000.0],
                        ['typeId' => self::CERAMIC_POWDER, 'typeName' => 'Ceramic Powder', 'quantity' => 100, 'unitPrice' => 260.0, 'totalPrice' => 26000.0],
                    ],
                    'buildJobInstallCost' => 14100.0,
                    'buyCostJita' => 188160.0,
                    'buyCostStructure' => null,
                    'verdict' => 'build',
                    'savings' => 19560.0,
                    'savingsPercent' => 10.4,
                    'meUsed' => 0,
                    'runs' => 1,
                ],
                [
                    'typeId' => self::RAM_AMMUNITION_TECH,
                    'typeName' => 'R.A.M.- Ammunition Tech',
                    'quantity' => 1,
                    'stage' => 'T1 Component',
                    'buildCost' => 53506.8,
                    'buildMaterials' => [
                        ['typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'quantity' => 501, 'unitPrice' => 5.0, 'totalPrice' => 2505.0],
                        ['typeId' => self::PYERITE, 'typeName' => 'Pyerite', 'quantity' => 400, 'unitPrice' => 11.0, 'totalPrice' => 4400.0],
                        ['typeId' => self::MEXALLON, 'typeName' => 'Mexallon', 'quantity' => 200, 'unitPrice' => 52.0, 'totalPrice' => 10400.0],
                        ['typeId' => self::ISOGEN, 'typeName' => 'Isogen', 'quantity' => 74, 'unitPrice' => 105.0, 'totalPrice' => 7770.0],
                        ['typeId' => self::NOCXIUM, 'typeName' => 'Nocxium', 'quantity' => 33, 'unitPrice' => 790.0, 'totalPrice' => 26070.0],
                    ],
                    'buildJobInstallCost' => 2361.8,
                    'buyCostJita' => 60000.0,
                    'buyCostStructure' => null,
                    'verdict' => 'build',
                    'savings' => 6493.2,
                    'savingsPercent' => 10.82,
                    'meUsed' => 10,
                    'runs' => 1,
                ],
                [
                    'typeId' => self::FULLERIDES,
                    'typeName' => 'Fullerides',
                    'quantity' => 1176,
                    'stage' => 'Reaction',
                    'buildCost' => 209900.0,
                    'buildMaterials' => [
                        ['typeId' => self::NITROGEN_FUEL_BLOCK, 'typeName' => 'Nitrogen Fuel Block', 'quantity' => 5, 'unitPrice' => 20500.0, 'totalPrice' => 102500.0],
                        ['typeId' => self::CARBON_POLYMERS, 'typeName' => 'Carbon Polymers', 'quantity' => 100, 'unitPrice' => 480.0, 'totalPrice' => 48000.0],
                        ['typeId' => self::PLATINUM_TECHNITE, 'typeName' => 'Platinum Technite', 'quantity' => 100, 'unitPrice' => 440.0, 'totalPrice' => 44000.0],
                    ],
                    'buildJobInstallCost' => 15400.0,
                    'buyCostJita' => 205800.0,
                    'buyCostStructure' => null,
                    'verdict' => 'buy',
                    'savings' => -4100.0,
                    'savingsPercent' => 1.95,
                    'meUsed' => 0,
                    'runs' => 1,
                ],
            ],
            'buildAllCost' => 432006.8,
            'buyAllCost' => 453960.0,
            'optimalMixCost' => 427906.8,
            'buildTypeIds' => [self::RAM_AMMUNITION_TECH, self::FERNITE_CARBIDE],
            'buyTypeIds' => [self::FULLERIDES],
            'missingPriceTypeIds' => [],
            'unknownInstallCostReasons' => [],
        ], $result);
    }

    public function testWithoutCostIndexInTheSystemEveryComponentIsBoughtAlthoughItsMaterialsCostLessThanItsPurchase(): void
    {
        $result = $this->analyzeHailL(self::UNINDEXED_SYSTEM_ID);

        $this->assertSame(
            [
                [self::RAM_AMMUNITION_TECH, null, null, 60000.0, 'buy', null, null],
                [self::FERNITE_CARBIDE, null, null, 188160.0, 'buy', null, null],
                [self::FULLERIDES, null, null, 205800.0, 'buy', null, null],
            ],
            $this->installCostAndVerdictOfEachComponent($result),
        );
        $this->assertNull($result['buildAllCost']);
        $this->assertSame(453960.0, $result['buyAllCost']);
        $this->assertSame(453960.0, $result['optimalMixCost']);
        $this->assertSame([], $result['buildTypeIds']);
        $this->assertSame([self::RAM_AMMUNITION_TECH, self::FERNITE_CARBIDE, self::FULLERIDES], $result['buyTypeIds']);
        $this->assertNull($result['totalProductionCost']);
        $this->assertSame(520.0, $result['sellPrice']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame([], $result['missingPriceTypeIds']);
        $this->assertSame(['missing_cost_index'], $result['unknownInstallCostReasons']);
    }

    public function testWithoutReactionCostIndexOnlyTheReactionComponentsHaveAnUnknownInstallCostAndTheFinalManufacturingJobStaysKnown(): void
    {
        $result = $this->analyzeHailL(self::MANUFACTURING_ONLY_SYSTEM_ID);

        $this->assertSame(
            [
                [self::RAM_AMMUNITION_TECH, 1889.44, 53034.44, 60000.0, 'build', 6965.56, 11.61],
                [self::FERNITE_CARBIDE, null, null, 188160.0, 'buy', null, null],
                [self::FULLERIDES, null, null, 205800.0, 'buy', null, null],
            ],
            $this->installCostAndVerdictOfEachComponent($result),
        );
        $this->assertNull($result['buildAllCost']);
        $this->assertSame(446994.44, $result['optimalMixCost']);
        $this->assertSame(613234.44, $result['totalProductionCost']);
        $this->assertSame(292.18, $result['marginPercent']);
        $this->assertSame(['missing_cost_index'], $result['unknownInstallCostReasons']);
    }

    public function testWithoutManufacturingCostIndexOnlyTheManufacturedComponentHasAnUnknownInstallCostAndTheFinalReactionJobStaysKnown(): void
    {
        $result = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: self::JITA_PRICES,
            structurePrices: [],
            adjustedPrices: self::ADJUSTED_PRICES,
            costIndices: self::COST_INDICES,
        )->analyze(self::FERNITE_CARBIDE, self::RUNS, 10, self::REACTION_ONLY_SYSTEM_ID, self::SELL_STRUCTURE_ID, self::BROKER_FEE_RATE, self::SALES_TAX_RATE, null);

        $this->assertSame(
            [
                [self::CERAMIC_POWDER, 7552.0, 110452.0, 26000.0, 'buy', -84452.0, 76.46],
                [self::FERNITE_ALLOY, 8080.0, 117580.0, 31000.0, 'buy', -86580.0, 73.63],
                [self::HYDROGEN_FUEL_BLOCK, null, null, 97500.0, 'buy', null, null],
            ],
            $this->installCostAndVerdictOfEachComponent($result),
        );
        $this->assertSame(154500.0, $result['optimalMixCost']);
        $this->assertSame(165780.0, $result['totalProductionCost']);
        $this->assertSame(257.1, $result['marginPercent']);
        $this->assertSame(['missing_cost_index'], $result['unknownInstallCostReasons']);
    }

    public function testComponentMaterialWithoutAdjustedPriceMakesOnlyThatComponentInstallCostUnknown(): void
    {
        $result = $this->analyzeHailL(self::FACTORY_SYSTEM_ID, adjustedPrices: $this->adjustedPricesWithout(self::CERAMIC_POWDER));

        $this->assertSame(
            [
                [self::RAM_AMMUNITION_TECH, 2361.8, 53506.8, 60000.0, 'build', 6493.2, 10.82],
                [self::FULLERIDES, 15400.0, 209900.0, 205800.0, 'buy', -4100.0, 1.95],
                [self::FERNITE_CARBIDE, null, null, 188160.0, 'buy', null, null],
            ],
            $this->installCostAndVerdictOfEachComponent($result),
        );
        $this->assertNull($result['buildAllCost']);
        $this->assertSame(447466.8, $result['optimalMixCost']);
        $this->assertSame(618516.8, $result['totalProductionCost']);
        $this->assertSame(288.83, $result['marginPercent']);
        $this->assertSame([], $result['missingPriceTypeIds']);
        $this->assertSame(['missing_adjusted_price'], $result['unknownInstallCostReasons']);
    }

    public function testComponentWithoutBuyPriceAndWithAnUnknownInstallCostHasAnUnknownVerdictAndAnUnknownOptimalMixCost(): void
    {
        $result = $this->analyzeHailL(
            self::FACTORY_SYSTEM_ID,
            jitaPrices: array_diff_key(self::JITA_PRICES, [self::FERNITE_CARBIDE => true]),
            adjustedPrices: $this->adjustedPricesWithout(self::CERAMIC_POWDER),
        );

        $this->assertSame(
            [self::FERNITE_CARBIDE, null, null, null, 'unknown', null, null],
            $this->installCostAndVerdictOfEachComponent($result)[2],
        );
        $this->assertNull($result['buildAllCost']);
        $this->assertNull($result['buyAllCost']);
        $this->assertNull($result['optimalMixCost']);
        $this->assertNull($result['totalProductionCost']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame([self::RAM_AMMUNITION_TECH], $result['buildTypeIds']);
        $this->assertSame([self::FULLERIDES], $result['buyTypeIds']);
        $this->assertSame([self::FERNITE_CARBIDE], $result['missingPriceTypeIds']);
        $this->assertSame(['missing_adjusted_price'], $result['unknownInstallCostReasons']);
    }

    public function testUnknownInstallCostReasonsAreListedOnceAndSorted(): void
    {
        $result = $this->analyzeHailL(self::REACTION_ONLY_SYSTEM_ID, adjustedPrices: $this->adjustedPricesWithout(self::CERAMIC_POWDER));

        $this->assertSame(
            [self::FULLERIDES => 12320.0, self::RAM_AMMUNITION_TECH => null, self::FERNITE_CARBIDE => null],
            array_column($result['components'], 'buildJobInstallCost', 'typeId'),
        );
        $this->assertNull($result['totalProductionCost']);
        $this->assertSame(['missing_adjusted_price', 'missing_cost_index'], $result['unknownInstallCostReasons']);
    }

    private function analyzeHailL(
        int $solarSystemId,
        array $jitaPrices = self::JITA_PRICES,
        array $adjustedPrices = self::ADJUSTED_PRICES,
    ): array {
        return $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: $jitaPrices,
            structurePrices: [],
            adjustedPrices: $adjustedPrices,
            costIndices: self::COST_INDICES,
            isT2: true,
        )->analyze(self::HAIL_L, self::RUNS, self::ME_LEVEL, $solarSystemId, self::SELL_STRUCTURE_ID, self::BROKER_FEE_RATE, self::SALES_TAX_RATE, null);
    }

    private function adjustedPricesWithout(int $typeId): array
    {
        return array_diff_key(self::ADJUSTED_PRICES, [$typeId => true]);
    }

    private function installCostAndVerdictOfEachComponent(array $result): array
    {
        return array_map(static fn (array $component): array => [
            $component['typeId'],
            $component['buildJobInstallCost'],
            $component['buildCost'],
            $component['buyCostJita'],
            $component['verdict'],
            $component['savings'],
            $component['savingsPercent'],
        ], $result['components']);
    }
}
