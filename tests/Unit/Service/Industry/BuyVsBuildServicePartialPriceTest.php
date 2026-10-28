<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\BuyVsBuildService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuyVsBuildService::class)]
final class BuyVsBuildServicePartialPriceTest extends TestCase
{
    use BuildsBuyVsBuildOnGoldenSde;

    private const int FACTORY_SYSTEM_ID = 30_002_187;
    private const int SELL_STRUCTURE_ID = 1_035_466_617_946;
    private const float BROKER_FEE_RATE = 0.03;
    private const float SALES_TAX_RATE = 0.045;
    private const int HAIL_L_RUNS = 1;
    private const int HAIL_L_ME_LEVEL = 2;
    private const int FERNITE_CARBIDE_RUNS = 10;
    private const int FERNITE_CARBIDE_ME_LEVEL = 10;

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

    private const float FULLERIDES_COVERAGE = 0.30;
    private const float FERNITE_CARBIDE_COVERAGE = 0.9375;
    private const float FERNITE_ALLOY_COVERAGE = 0.6;
    private const float PLATINUM_TECHNITE_COVERAGE = 0.45;
    private const float MORPHITE_COVERAGE = 0.7;
    private const float STUB_DATACORE_A_COVERAGE = 0.55;
    private const float RAM_AMMUNITION_TECH_COVERAGE = 0.2;
    private const float RAM_AMMUNITION_TECH_STRUCTURE_PRICE = 25000.0;

    private const int HYDROGEN_FUEL_BLOCKS_BOUGHT_BY_THE_ROOT = 50;
    private const int HYDROGEN_FUEL_BLOCKS_BOUGHT_BY_EACH_BUILT_COMPONENT = 25;
    private const float HYDROGEN_FUEL_BLOCK_COVERAGE_OF_THE_ROOT_PURCHASE = 0.4;
    private const float HYDROGEN_FUEL_BLOCK_COVERAGE_OF_A_COMPONENT_PURCHASE = 0.8;

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
        self::HYDROGEN_FUEL_BLOCK => 1.0,
        self::FERNITE_ALLOY => 1.0e9,
        self::CERAMIC_POWDER => 2.0e9,
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

    public function testComponentBoughtAtAPartialJitaPriceKeepsItsVerdictAndAmountsAndIsListedInItsWarningsAndTheAnalysisWarnings(): void
    {
        $result = $this->analyzeHailL([self::FULLERIDES => self::FULLERIDES_COVERAGE]);

        $fullerides = $this->componentOf($result, self::FULLERIDES);
        $this->assertSame('buy', $fullerides['verdict']);
        $this->assertSame(188160.0, $fullerides['buyCostJita']);
        $this->assertSame(-33785.0, $fullerides['savings']);
        $this->assertSame(15.22, $fullerides['savingsPercent']);
        $this->assertSame([], $fullerides['missingData']);
        $this->assertSame(383252.5, $result['optimalMixCost']);
        $this->assertSame(582567.84, $result['totalProductionCost']);
        $this->assertSame(296.95, $result['marginPercent']);
        $this->assertSame(
            [
                'buyCostJitaCoveragePercent' => 30.0,
                'componentWarnings' => [self::partialMarketDepth(self::FULLERIDES, 30.0)],
                'warnings' => [self::partialMarketDepth(self::FULLERIDES, 30.0)],
            ],
            [
                'buyCostJitaCoveragePercent' => $fullerides['buyCostJitaCoveragePercent'],
                'componentWarnings' => $fullerides['warnings'],
                'warnings' => $result['warnings'],
            ],
        );
    }

    public function testBuiltComponentWithAPartialJitaPriceIsListedInItsWarningsButNotInTheAnalysisWarnings(): void
    {
        $result = $this->analyzeHailL([self::FERNITE_CARBIDE => self::FERNITE_CARBIDE_COVERAGE]);

        $ferniteCarbide = $this->componentOf($result, self::FERNITE_CARBIDE);
        $this->assertSame('build', $ferniteCarbide['verdict']);
        $this->assertSame(175092.5, $ferniteCarbide['buildCost']);
        $this->assertSame(176400.0, $ferniteCarbide['buyCostJita']);
        $this->assertSame(1307.5, $ferniteCarbide['savings']);
        $this->assertSame(582567.84, $result['totalProductionCost']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame(
            [
                'buyCostJitaCoveragePercent' => 93.8,
                'componentWarnings' => [self::partialMarketDepth(self::FERNITE_CARBIDE, 93.8)],
            ],
            [
                'buyCostJitaCoveragePercent' => $ferniteCarbide['buyCostJitaCoveragePercent'],
                'componentWarnings' => $ferniteCarbide['warnings'],
            ],
        );
    }

    public function testPartialBuildMaterialIsListedInItsComponentWarningsAndInTheAnalysisWarningsOnlyWhenTheComponentIsBuilt(): void
    {
        $result = $this->analyzeHailL([
            self::FERNITE_ALLOY => self::FERNITE_ALLOY_COVERAGE,
            self::PLATINUM_TECHNITE => self::PLATINUM_TECHNITE_COVERAGE,
        ]);

        $ferniteCarbide = $this->componentOf($result, self::FERNITE_CARBIDE);
        $fullerides = $this->componentOf($result, self::FULLERIDES);
        $this->assertSame('build', $ferniteCarbide['verdict']);
        $this->assertSame('buy', $fullerides['verdict']);
        $this->assertSame(['typeId' => self::FERNITE_ALLOY, 'typeName' => 'Fernite Alloy', 'quantity' => 100, 'unitPrice' => 300.0, 'totalPrice' => 30000.0], $ferniteCarbide['buildMaterials'][1]);
        $this->assertSame(175092.5, $ferniteCarbide['buildCost']);
        $this->assertSame(221945.0, $fullerides['buildCost']);
        $this->assertNull($ferniteCarbide['buyCostJitaCoveragePercent']);
        $this->assertNull($fullerides['buyCostJitaCoveragePercent']);
        $this->assertSame(
            [
                'ferniteCarbideWarnings' => [self::partialMarketDepth(self::FERNITE_ALLOY, 60.0)],
                'fulleridesWarnings' => [self::partialMarketDepth(self::PLATINUM_TECHNITE, 45.0)],
                'warnings' => [self::partialMarketDepth(self::FERNITE_ALLOY, 60.0)],
            ],
            [
                'ferniteCarbideWarnings' => $ferniteCarbide['warnings'],
                'fulleridesWarnings' => $fullerides['warnings'],
                'warnings' => $result['warnings'],
            ],
        );
    }

    public function testStructurePriceRetainedForTheBuyCostLeavesTheComponentWarningsEmptyButMarksTheCoverageOfItsJitaPrice(): void
    {
        $result = $this->analyzeHailL(
            [self::RAM_AMMUNITION_TECH => self::RAM_AMMUNITION_TECH_COVERAGE],
            [self::RAM_AMMUNITION_TECH => self::RAM_AMMUNITION_TECH_STRUCTURE_PRICE],
        );

        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame(20000.0, $ram['buyCostJita']);
        $this->assertSame(25000.0, $ram['buyCostStructure']);
        $this->assertSame('buy', $ram['verdict']);
        $this->assertSame(-29674.33, $ram['savings']);
        $this->assertSame(54.27, $ram['savingsPercent']);
        $this->assertSame([], $ram['warnings']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame(20.0, $ram['buyCostJitaCoveragePercent']);
    }

    public function testPartialPricesOfTheRetainedCostsAreListedInTheAnalysisWarningsInOrderOfAppearance(): void
    {
        $result = $this->analyzeHailL([
            self::FERNITE_ALLOY => self::FERNITE_ALLOY_COVERAGE,
            self::FULLERIDES => self::FULLERIDES_COVERAGE,
            self::MORPHITE => self::MORPHITE_COVERAGE,
            self::STUB_DATACORE_A => self::STUB_DATACORE_A_COVERAGE,
        ]);

        $this->assertSame(582567.84, $result['totalProductionCost']);
        $this->assertSame([], $result['missingData']);
        $this->assertSame(
            [
                self::partialMarketDepth(self::FERNITE_ALLOY, 60.0),
                self::partialMarketDepth(self::FULLERIDES, 30.0),
                self::partialMarketDepth(self::MORPHITE, 70.0),
                self::partialMarketDepth(self::STUB_DATACORE_A, 55.0),
            ],
            $result['warnings'],
        );
    }

    public function testSameTypeBoughtByTheRootAndByTwoBuiltComponentsIsListedOnceAtTheLowestCoverage(): void
    {
        $result = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: self::FERNITE_CARBIDE_JITA_PRICES,
            structurePrices: [],
            adjustedPrices: self::FERNITE_CARBIDE_ADJUSTED_PRICES,
            costIndices: self::COST_INDICES,
            jitaCoverages: [self::HYDROGEN_FUEL_BLOCK => [
                self::HYDROGEN_FUEL_BLOCKS_BOUGHT_BY_THE_ROOT => self::HYDROGEN_FUEL_BLOCK_COVERAGE_OF_THE_ROOT_PURCHASE,
                self::HYDROGEN_FUEL_BLOCKS_BOUGHT_BY_EACH_BUILT_COMPONENT => self::HYDROGEN_FUEL_BLOCK_COVERAGE_OF_A_COMPONENT_PURCHASE,
            ]],
        )->analyze(self::FERNITE_CARBIDE, self::FERNITE_CARBIDE_RUNS, self::FERNITE_CARBIDE_ME_LEVEL, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, self::BROKER_FEE_RATE, self::SALES_TAX_RATE, null);

        $this->assertSame([self::HYDROGEN_FUEL_BLOCK], $result['buyTypeIds']);
        $this->assertSame([self::FERNITE_ALLOY, self::CERAMIC_POWDER], $result['buildTypeIds']);
        $this->assertSame(
            [
                self::HYDROGEN_FUEL_BLOCK => [self::partialMarketDepth(self::HYDROGEN_FUEL_BLOCK, 40.0)],
                self::FERNITE_ALLOY => [self::partialMarketDepth(self::HYDROGEN_FUEL_BLOCK, 80.0)],
                self::CERAMIC_POWDER => [self::partialMarketDepth(self::HYDROGEN_FUEL_BLOCK, 80.0)],
                'analysis' => [self::partialMarketDepth(self::HYDROGEN_FUEL_BLOCK, 40.0)],
            ],
            [
                self::HYDROGEN_FUEL_BLOCK => $this->componentOf($result, self::HYDROGEN_FUEL_BLOCK)['warnings'],
                self::FERNITE_ALLOY => $this->componentOf($result, self::FERNITE_ALLOY)['warnings'],
                self::CERAMIC_POWDER => $this->componentOf($result, self::CERAMIC_POWDER)['warnings'],
                'analysis' => $result['warnings'],
            ],
        );
    }

    private function analyzeHailL(array $jitaCoverages, array $structurePrices = []): array
    {
        return $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: self::HAIL_L_JITA_PRICES,
            structurePrices: [self::SELL_STRUCTURE_ID => $structurePrices],
            adjustedPrices: self::HAIL_L_ADJUSTED_PRICES,
            costIndices: self::COST_INDICES,
            jitaCoverages: $jitaCoverages,
        )->analyze(self::HAIL_L, self::HAIL_L_RUNS, self::HAIL_L_ME_LEVEL, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, self::BROKER_FEE_RATE, self::SALES_TAX_RATE, null);
    }

    private static function partialMarketDepth(int $typeId, float $coveragePercent): array
    {
        return ['reason' => 'partial_market_depth', 'typeId' => $typeId, 'coveragePercent' => $coveragePercent];
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
