<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\BuyVsBuildService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Characterization of the buy-vs-build output before `buildProductionTree()` (BuyVsBuildService.php:65)
 * is replaced by the Industry Application (docs/specs/industry-engine.md §7, rank 2).
 *
 * The real production tree is built from the SDE extract of the goldens. Market prices, adjusted prices
 * and cost indices are deterministic stubs. Tests whose name ends in `CurrentlyWrong...` or that carry a
 * `CARACTÉRISATION` comment pin a behavior the spec corrects: they are expected to change at the migration.
 */
#[CoversClass(BuyVsBuildService::class)]
final class BuyVsBuildServiceCharacterizationTest extends TestCase
{
    use BuildsBuyVsBuildOnGoldenSde;

    private const int FACTORY_SYSTEM_ID = 30_002_187;
    private const int SELL_STRUCTURE_ID = 1_035_466_617_946;

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
    private const int ZYDRINE = 39;
    private const int MEGACYTE = 40;
    private const int MARSHAL = 44996;
    private const int CAPITAL_JUMP_DRIVE = 21025;
    private const int PLASMA_THRUSTER = 11530;
    private const int GRAVIMETRIC_SENSOR_CLUSTER = 11534;
    private const int PHOTON_MICROPROCESSOR = 11541;
    private const int TUNGSTEN_CARBIDE_ARMOR_PLATE = 11543;
    private const int FUSION_REACTOR_UNIT = 11547;
    private const int TESSERACT_CAPACITOR_UNIT = 11554;
    private const int SUSTAINED_SHIELD_EMITTER = 11558;
    private const int WETWARE_MAINFRAME = 2876;
    private const int REINFORCED_CARBON_FIBER = 57457;
    private const int CONSTRUCTION_BLOCKS = 3828;
    private const int CRYSTALLINE_CARBONIDE = 16670;
    private const int TITANIUM_CARBIDE = 16671;
    private const int TUNGSTEN_CARBIDE = 16672;
    private const int SYLRAMIC_FIBERS = 16678;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int NANOTRANSISTORS = 16681;
    private const int HYPERSYNAPTIC_FIBERS = 16682;
    private const int FERROGEL = 16683;
    private const int FERMIONIC_CONDENSATES = 17317;
    private const int PHOTONIC_METAMATERIALS = 33359;
    private const int TERAHERTZ_METAMATERIALS = 33360;
    private const int PLATINUM_TECHNITE = 16662;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;
    private const int CARBON_POLYMERS = 16659;
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
    private const int NITROGEN_ISOTOPES = 17888;
    private const int HYDROGEN_ISOTOPES = 17889;

    private const array FACTORY_COST_INDICES = [self::FACTORY_SYSTEM_ID => ['manufacturing' => 0.05, 'reaction' => 0.1]];

    // ===========================================
    // Hail L: 5000 units per run, T2 component, two reaction intermediates
    // ===========================================

    #[Group('issue-101')]
    #[Group('issue-98')]
    public function testHailLTenRunsCurrentlyWrongBuildsTheTreeForOneRunButSellsTenRuns(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices(), runs: 10);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2.
        // buildProductionTree() reads the 10 runs as 10 units: ceil(10 / 5000) = 1 run. Materials are those of one run
        // (Fernite Carbide 2940 = ceil(3000 × 0.98) instead of 29400 for 10 runs), while the revenue counts
        // 10 runs × 5000 units and the final job install cost counts 10 runs.
        $this->assertSame([
            'typeId' => self::HAIL_L,
            'typeName' => 'Hail L',
            'isT2' => true,
            'runs' => 10,
            'totalProductionCost' => 772760.0,
            'sellPrice' => 500.0,
            'marginPercent' => 2892.52,
            'components' => [
                [
                    'typeId' => self::FERNITE_CARBIDE,
                    'typeName' => 'Fernite Carbide',
                    'quantity' => 2940,
                    'stage' => 'Reaction',
                    'buildCost' => 169100.0,
                    'buildMaterials' => [
                        ['typeId' => self::HYDROGEN_FUEL_BLOCK, 'typeName' => 'Hydrogen Fuel Block', 'quantity' => 5, 'unitPrice' => 20000.0, 'totalPrice' => 100000.0],
                        ['typeId' => self::FERNITE_ALLOY, 'typeName' => 'Fernite Alloy', 'quantity' => 100, 'unitPrice' => 300.0, 'totalPrice' => 30000.0],
                        ['typeId' => self::CERAMIC_POWDER, 'typeName' => 'Ceramic Powder', 'quantity' => 100, 'unitPrice' => 250.0, 'totalPrice' => 25000.0],
                    ],
                    'buildJobInstallCost' => 14100.0,
                    'buyCostJita' => 176400.0,
                    'buyCostStructure' => null,
                    'verdict' => 'build',
                    'savings' => 7300.0,
                    'savingsPercent' => 4.14,
                    'meUsed' => 0,
                    'runs' => 1,
                ],
                [
                    'typeId' => self::FULLERIDES,
                    'typeName' => 'Fullerides',
                    'quantity' => 1176,
                    'stage' => 'Reaction',
                    'buildCost' => 215400.0,
                    'buildMaterials' => [
                        ['typeId' => self::NITROGEN_FUEL_BLOCK, 'typeName' => 'Nitrogen Fuel Block', 'quantity' => 5, 'unitPrice' => 21000.0, 'totalPrice' => 105000.0],
                        ['typeId' => self::CARBON_POLYMERS, 'typeName' => 'Carbon Polymers', 'quantity' => 100, 'unitPrice' => 500.0, 'totalPrice' => 50000.0],
                        ['typeId' => self::PLATINUM_TECHNITE, 'typeName' => 'Platinum Technite', 'quantity' => 100, 'unitPrice' => 450.0, 'totalPrice' => 45000.0],
                    ],
                    'buildJobInstallCost' => 15400.0,
                    'buyCostJita' => 188160.0,
                    'buyCostStructure' => null,
                    'verdict' => 'buy',
                    'savings' => -27240.0,
                    'savingsPercent' => 12.65,
                    'meUsed' => 0,
                    'runs' => 1,
                ],
                [
                    'typeId' => self::RAM_AMMUNITION_TECH,
                    'typeName' => 'R.A.M.- Ammunition Tech',
                    'quantity' => 1,
                    'stage' => 'T1 Component',
                    'buildCost' => 52666.8,
                    'buildMaterials' => [
                        ['typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'quantity' => 501, 'unitPrice' => 5.0, 'totalPrice' => 2505.0],
                        ['typeId' => self::PYERITE, 'typeName' => 'Pyerite', 'quantity' => 400, 'unitPrice' => 10.0, 'totalPrice' => 4000.0],
                        ['typeId' => self::MEXALLON, 'typeName' => 'Mexallon', 'quantity' => 200, 'unitPrice' => 50.0, 'totalPrice' => 10000.0],
                        ['typeId' => self::ISOGEN, 'typeName' => 'Isogen', 'quantity' => 74, 'unitPrice' => 100.0, 'totalPrice' => 7400.0],
                        ['typeId' => self::NOCXIUM, 'typeName' => 'Nocxium', 'quantity' => 33, 'unitPrice' => 800.0, 'totalPrice' => 26400.0],
                    ],
                    'buildJobInstallCost' => 2361.8,
                    'buyCostJita' => 20000.0,
                    'buyCostStructure' => 25000.0,
                    'verdict' => 'buy',
                    'savings' => -27666.8,
                    'savingsPercent' => 52.53,
                    'meUsed' => 10,
                    'runs' => 1,
                ],
            ],
            'buildAllCost' => 437166.8,
            'buyAllCost' => 389560.0,
            'optimalMixCost' => 382260.0,
            'buildTypeIds' => [self::FERNITE_CARBIDE],
            'buyTypeIds' => [self::RAM_AMMUNITION_TECH, self::FULLERIDES],
            'missingPriceTypeIds' => [],
            'unknownInstallCostReasons' => [],
        ], $result);
    }

    #[Group('issue-8')]
    public function testComponentWithoutAnyBuyPriceAndWithAnUnpricedMaterialHasAnUnknownVerdictAndUnknownTotals(): void
    {
        $result = $this->analyzeHailL(array_diff_key($this->hailLJitaPrices(), [self::FULLERIDES => true, self::PLATINUM_TECHNITE => true]));

        $fullerides = $this->componentOf($result, self::FULLERIDES);
        $this->assertSame('unknown', $fullerides['verdict']);
        $this->assertNull($fullerides['buildCost']);
        $this->assertNull($fullerides['buyCostJita']);
        $this->assertNull($fullerides['buyCostStructure']);
        $this->assertNull($fullerides['savings']);
        $this->assertNull($fullerides['savingsPercent']);
        $this->assertSame(
            ['typeId' => self::PLATINUM_TECHNITE, 'typeName' => 'Platinum Technite', 'quantity' => 100, 'unitPrice' => null, 'totalPrice' => null],
            $fullerides['buildMaterials'][2],
        );
        $this->assertSame([self::FERNITE_CARBIDE, self::RAM_AMMUNITION_TECH, self::FULLERIDES], array_column($result['components'], 'typeId'));
        $this->assertNull($result['buildAllCost']);
        $this->assertNull($result['buyAllCost']);
        $this->assertNull($result['optimalMixCost']);
        $this->assertNull($result['totalProductionCost']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame([self::PLATINUM_TECHNITE, self::FULLERIDES], $result['missingPriceTypeIds']);
    }

    public function testStructurePriceIsPreferredToACheaperJitaPriceForTheBuyCost(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices());

        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame(20000.0, $ram['buyCostJita']);
        $this->assertSame(25000.0, $ram['buyCostStructure']);
        $this->assertSame(-27666.8, $ram['savings']);
    }

    public function testComponentIsBuiltWhenItsBuildCostEqualsItsBuyCost(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices(), ramStructurePrice: 52666.8);

        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame('build', $ram['verdict']);
        $this->assertSame(0.0, $ram['savings']);
        $this->assertSame(0.0, $ram['savingsPercent']);
        $this->assertSame([self::RAM_AMMUNITION_TECH, self::FERNITE_CARBIDE], $result['buildTypeIds']);
        $this->assertSame([self::FULLERIDES], $result['buyTypeIds']);
        $this->assertSame(409926.8, $result['optimalMixCost']);
    }

    #[Group('issue-8')]
    public function testWithoutAnyJitaPriceEveryComponentHasAnUnknownVerdictAndEveryCostIsUnknown(): void
    {
        $service = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: [],
            structurePrices: [self::SELL_STRUCTURE_ID => [self::HAIL_L => 480.0]],
            adjustedPrices: [],
            costIndices: self::FACTORY_COST_INDICES,
            isT2: true,
        );

        $result = $service->analyze(self::HAIL_L, 1, 2, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.03, 0.045, null);

        $this->assertSame(
            [
                [self::RAM_AMMUNITION_TECH, 'unknown', null, null, null],
                [self::FERNITE_CARBIDE, 'unknown', null, null, null],
                [self::FULLERIDES, 'unknown', null, null, null],
            ],
            array_map(static fn (array $component): array => [
                $component['typeId'], $component['verdict'], $component['buildCost'], $component['savings'], $component['savingsPercent'],
            ], $result['components']),
        );
        $this->assertNull($result['buildAllCost']);
        $this->assertNull($result['buyAllCost']);
        $this->assertNull($result['optimalMixCost']);
        $this->assertNull($result['totalProductionCost']);
        $this->assertSame(480.0, $result['sellPrice']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame(
            [
                self::TRITANIUM, self::PYERITE, self::MEXALLON, self::ISOGEN, self::NOCXIUM,
                self::NITROGEN_FUEL_BLOCK, self::HYDROGEN_FUEL_BLOCK, self::MORPHITE, self::RAM_AMMUNITION_TECH,
                self::FERNITE_ALLOY, self::CARBON_POLYMERS, self::CERAMIC_POWDER, self::PLATINUM_TECHNITE,
                self::FERNITE_CARBIDE, self::FULLERIDES,
            ],
            $result['missingPriceTypeIds'],
        );
    }

    #[Group('issue-98')]
    public function testZeroStructureSellPriceCurrentlyWrongHidesTheJitaPriceAndLeavesTheMarginUnknown(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices(), productStructurePrice: 0.0);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #8.
        // A structure sell price of 0 is taken as a real price: the Jita price (500) is ignored and the margin is unknown.
        $this->assertSame(0.0, $result['sellPrice']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame(556310.0, $result['totalProductionCost']);
    }

    #[Group('issue-98')]
    public function testPricesAndCostsAreRoundedToTwoDecimals(): void
    {
        $jitaPrices = [
            self::RAM_AMMUNITION_TECH => 30000.016,
            self::TRITANIUM => 5.678,
            self::HAIL_L => 500.123,
        ] + $this->hailLJitaPrices();

        $result = $this->analyzeHailL(
            $jitaPrices,
            ramStructurePrice: null,
            ferniteCarbideStructurePrice: 59.9993,
            tritaniumAdjustedPrice: 4.001,
        );

        // R.A.M.: Tritanium 501 × 5.678 = 2844.678, materials 50644.678, EIV 47236.556, job 2361.8278,
        // build 53006.5058, buy 30000.016. Fernite Carbide: structure buy 2940 × 59.9993 = 176397.9418.
        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame(['typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'quantity' => 501, 'unitPrice' => 5.68, 'totalPrice' => 2844.68], $ram['buildMaterials'][0]);
        $this->assertSame(2361.83, $ram['buildJobInstallCost']);
        $this->assertSame(53006.51, $ram['buildCost']);
        $this->assertSame(30000.02, $ram['buyCostJita']);
        $this->assertSame(-23006.49, $ram['savings']);
        $this->assertSame(43.4, $ram['savingsPercent']);
        $ferniteCarbide = $this->componentOf($result, self::FERNITE_CARBIDE);
        $this->assertSame(176397.94, $ferniteCarbide['buyCostStructure']);
        $this->assertSame(7297.94, $ferniteCarbide['savings']);
        $this->assertSame(500.12, $result['sellPrice']);
        $this->assertSame(561310.02, $result['totalProductionCost']);
        $this->assertSame(437506.51, $result['buildAllCost']);
        $this->assertSame(394557.96, $result['buyAllCost']);
        $this->assertSame(387260.02, $result['optimalMixCost']);
        $this->assertSame(312.08, $result['marginPercent']);
    }

    // ===========================================
    // Nitrogen Fuel Block: 40 units per run, no intermediate
    // ===========================================

    #[Group('issue-98')]
    public function testRootLeavesArePricedAtJitaSoNitrogenFuelBlockCostsItsMaterials(): void
    {
        $service = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: [self::NITROGEN_FUEL_BLOCK => 14000.0] + $this->fuelBlockInputJitaPrices(),
            structurePrices: [self::SELL_STRUCTURE_ID => [self::NITROGEN_FUEL_BLOCK => 15000.0]],
            adjustedPrices: [self::NITROGEN_ISOTOPES => 35.0] + $this->fuelBlockInputAdjustedPrices(),
            costIndices: self::FACTORY_COST_INDICES,
        );

        $result = $service->analyze(self::NITROGEN_FUEL_BLOCK, 1, 10, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.01, 0.02, null);

        $this->assertSame([
            'typeId' => self::NITROGEN_FUEL_BLOCK,
            'typeName' => 'Nitrogen Fuel Block',
            'isT2' => false,
            'runs' => 1,
            'totalProductionCost' => 37979.5,
            'sellPrice' => 15000.0,
            'marginPercent' => 1432.41,
            'components' => [],
            'buildAllCost' => 0.0,
            'buyAllCost' => 0.0,
            'optimalMixCost' => 0.0,
            'buildTypeIds' => [],
            'buyTypeIds' => [],
            'missingPriceTypeIds' => [],
            'unknownInstallCostReasons' => [],
        ], $result);
    }

    #[Group('issue-98')]
    public function testRootLeafIsPricedAtJitaWhenNoComponentConsumesIt(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices());

        $this->assertSame(382260.0, $result['optimalMixCost']);
        $this->assertSame(556310.0, $result['totalProductionCost']);
    }

    // ===========================================
    // Marshal: 1 unit per run, minerals consumed by the root job and by the Capital Jump Drive
    // ===========================================

    public function testRootLeafAlsoUsedByAComponentIsPricedAtJita(): void
    {
        $service = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: [
                self::MARSHAL => 300_000_000.0,
                self::CAPITAL_JUMP_DRIVE => 12_000_000.0,
                self::TRITANIUM => 5.0,
                self::PYERITE => 10.0,
                self::MEXALLON => 50.0,
                self::ISOGEN => 100.0,
                self::NOCXIUM => 800.0,
                self::ZYDRINE => 1500.0,
                self::MEGACYTE => 3000.0,
                self::WETWARE_MAINFRAME => 1_800_000.0,
                self::REINFORCED_CARBON_FIBER => 900.0,
                self::PLASMA_THRUSTER => 95_000.0,
                self::GRAVIMETRIC_SENSOR_CLUSTER => 41_000.0,
                self::PHOTON_MICROPROCESSOR => 52_000.0,
                self::TUNGSTEN_CARBIDE_ARMOR_PLATE => 23_000.0,
                self::FUSION_REACTOR_UNIT => 67_000.0,
                self::TESSERACT_CAPACITOR_UNIT => 88_000.0,
                self::SUSTAINED_SHIELD_EMITTER => 74_000.0,
                self::CRYSTALLINE_CARBONIDE => 110.0,
                self::TITANIUM_CARBIDE => 95.0,
                self::TUNGSTEN_CARBIDE => 85.0,
                self::FERNITE_CARBIDE => 60.0,
                self::SYLRAMIC_FIBERS => 240.0,
                self::FULLERIDES => 160.0,
                self::PHENOLIC_COMPOSITES => 130.0,
                self::NANOTRANSISTORS => 520.0,
                self::HYPERSYNAPTIC_FIBERS => 610.0,
                self::FERROGEL => 480.0,
                self::FERMIONIC_CONDENSATES => 1250.0,
                self::PHOTONIC_METAMATERIALS => 1900.0,
                self::TERAHERTZ_METAMATERIALS => 2100.0,
                self::CONSTRUCTION_BLOCKS => 7500.0,
                self::MORPHITE => 10000.0,
            ],
            structurePrices: [],
            adjustedPrices: [
                self::MORPHITE => 9000.0,
                self::TRITANIUM => 4.0,
                self::PYERITE => 8.0,
                self::MEXALLON => 40.0,
                self::ISOGEN => 90.0,
                self::NOCXIUM => 700.0,
                self::ZYDRINE => 1400.0,
                self::MEGACYTE => 2800.0,
                self::WETWARE_MAINFRAME => 1_650_000.0,
                self::REINFORCED_CARBON_FIBER => 850.0,
                self::CONSTRUCTION_BLOCKS => 7100.0,
                self::CAPITAL_JUMP_DRIVE => 11_500_000.0,
                self::PLASMA_THRUSTER => 91_000.0,
                self::GRAVIMETRIC_SENSOR_CLUSTER => 39_000.0,
                self::PHOTON_MICROPROCESSOR => 49_500.0,
                self::TUNGSTEN_CARBIDE_ARMOR_PLATE => 21_800.0,
                self::FUSION_REACTOR_UNIT => 63_000.0,
                self::TESSERACT_CAPACITOR_UNIT => 84_000.0,
                self::SUSTAINED_SHIELD_EMITTER => 70_500.0,
                self::CRYSTALLINE_CARBONIDE => 104.0,
                self::TITANIUM_CARBIDE => 91.0,
                self::TUNGSTEN_CARBIDE => 81.0,
                self::FERNITE_CARBIDE => 57.0,
                self::SYLRAMIC_FIBERS => 228.0,
                self::FULLERIDES => 152.0,
                self::PHENOLIC_COMPOSITES => 124.0,
                self::NANOTRANSISTORS => 495.0,
                self::HYPERSYNAPTIC_FIBERS => 580.0,
                self::FERROGEL => 456.0,
                self::FERMIONIC_CONDENSATES => 1190.0,
                self::PHOTONIC_METAMATERIALS => 1800.0,
                self::TERAHERTZ_METAMATERIALS => 1995.0,
            ],
            costIndices: self::FACTORY_COST_INDICES,
        );

        $result = $service->analyze(self::MARSHAL, 1, 10, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.0, 0.0, null);

        $this->assertSame(206_510_700.35, $result['optimalMixCost']);
        $this->assertSame(483126050.35, $result['totalProductionCost']);
        $this->assertSame(-37.9, $result['marginPercent']);
    }

    // ===========================================
    // Fernite Carbide: reaction root, 10000 units per run
    // ===========================================

    #[Group('issue-101')]
    public function testFerniteCarbideTenRunsCurrentlyWrongBuildsOneReactionRunAndCountsEachDepthApart(): void
    {
        $service = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: [
                self::FERNITE_CARBIDE => 60.0,
                self::HYDROGEN_FUEL_BLOCK => 20000.0,
                self::FERNITE_ALLOY => 300.0,
                self::CERAMIC_POWDER => 250.0,
                self::SCANDIUM => 50.0,
                self::VANADIUM => 70.0,
                self::EVAPORITE_DEPOSITS => 20.0,
                self::SILICATES => 30.0,
            ] + $this->fuelBlockInputJitaPrices(),
            structurePrices: [self::SELL_STRUCTURE_ID => [self::CERAMIC_POWDER => 240.0]],
            adjustedPrices: [
                self::HYDROGEN_ISOTOPES => 35.0,
                self::HYDROGEN_FUEL_BLOCK => 18000.0,
                self::FERNITE_ALLOY => 280.0,
                self::CERAMIC_POWDER => 230.0,
                self::SCANDIUM => 46.0,
                self::VANADIUM => 64.0,
                self::EVAPORITE_DEPOSITS => 17.0,
                self::SILICATES => 27.0,
            ] + $this->fuelBlockInputAdjustedPrices(),
            costIndices: self::FACTORY_COST_INDICES,
        );

        $result = $service->analyze(self::FERNITE_CARBIDE, 10, 10, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.0, 0.0, null);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issues #2, #16 et #69.
        // ceil(10 / 10000) = 1 reaction run (golden §4.3: 10 runs). Hydrogen Fuel Block is consumed at depth 1 (root)
        // and depth 2 (Fernite Alloy, Ceramic Powder): only the depth-1 demand is analyzed, the deeper ones are
        // bought at Jita inside the reaction build cost.
        $this->assertSame(230954.5, $result['totalProductionCost']);
        $this->assertSame(60.0, $result['sellPrice']);
        $this->assertSame(2497.91, $result['marginPercent']);
        $this->assertSame(272494.5, $result['buildAllCost']);
        $this->assertSame(154000.0, $result['buyAllCost']);
        $this->assertSame(89954.5, $result['optimalMixCost']);
        $this->assertSame([self::HYDROGEN_FUEL_BLOCK], $result['buildTypeIds']);
        $this->assertSame([self::FERNITE_ALLOY, self::CERAMIC_POWDER], $result['buyTypeIds']);

        $this->assertSame(
            [
                [self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 5, 'T1 Component', 10, 1, 35954.5, 1684.5, 100000.0, null, 'build', 64045.5, 64.05],
                [self::CERAMIC_POWDER, 'Ceramic Powder', 100, 'Reaction', 0, 1, 114440.0, 9440.0, 25000.0, 24000.0, 'buy', -90440.0, 79.03],
                [self::FERNITE_ALLOY, 'Fernite Alloy', 100, 'Reaction', 0, 1, 122100.0, 10100.0, 30000.0, null, 'buy', -92100.0, 75.43],
            ],
            array_map(static fn (array $component): array => [
                $component['typeId'], $component['typeName'], $component['quantity'], $component['stage'], $component['meUsed'], $component['runs'],
                $component['buildCost'], $component['buildJobInstallCost'], $component['buyCostJita'], $component['buyCostStructure'],
                $component['verdict'], $component['savings'], $component['savingsPercent'],
            ], $result['components']),
        );
        // Ceramic Powder: 100 units = 1 run of 200, its inputs bought at Jita (its own structure price 240 only sets its buy cost).
        $this->assertSame(
            [
                ['typeId' => self::HYDROGEN_FUEL_BLOCK, 'typeName' => 'Hydrogen Fuel Block', 'quantity' => 5, 'unitPrice' => 20000.0, 'totalPrice' => 100000.0],
                ['typeId' => self::EVAPORITE_DEPOSITS, 'typeName' => 'Evaporite Deposits', 'quantity' => 100, 'unitPrice' => 20.0, 'totalPrice' => 2000.0],
                ['typeId' => self::SILICATES, 'typeName' => 'Silicates', 'quantity' => 100, 'unitPrice' => 30.0, 'totalPrice' => 3000.0],
            ],
            $this->componentOf($result, self::CERAMIC_POWDER)['buildMaterials'],
        );
    }

    #[Group('issue-101')]
    public function testFuelBlockConsumedByAReactionIsLabelledT1Component(): void
    {
        $service = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: $this->fuelBlockInputJitaPrices(),
            structurePrices: [],
            adjustedPrices: [],
            costIndices: self::FACTORY_COST_INDICES,
        );

        $result = $service->analyze(self::FERNITE_CARBIDE, 1, 10, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.0, 0.0, null);

        $this->assertSame('T1 Component', $this->componentOf($result, self::HYDROGEN_FUEL_BLOCK)['stage']);
    }

    #[Group('issue-101')]
    public function testManufacturedComponentConsumingAReactionProductIsLabelledT2Component(): void
    {
        $service = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: [self::MARSHAL => 300_000_000.0, self::PLASMA_THRUSTER => 90_000.0, self::FERNITE_CARBIDE => 60.0],
            structurePrices: [],
            adjustedPrices: [],
            costIndices: self::FACTORY_COST_INDICES,
        );

        $result = $service->analyze(self::MARSHAL, 1, 10, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.0, 0.0, null);

        $this->assertSame('T2 Component', $this->componentOf($result, self::PLASMA_THRUSTER)['stage']);
    }

    // ===========================================
    // Helpers
    // ===========================================

    /**
     * @param array<int, float> $jitaPrices
     *
     * @return array<string, mixed>
     */
    private function analyzeHailL(
        array $jitaPrices,
        ?float $ramStructurePrice = 25000.0,
        ?float $productStructurePrice = null,
        ?float $ferniteCarbideStructurePrice = null,
        float $tritaniumAdjustedPrice = 4.0,
        int $runs = 1,
    ): array {
        $sellStructurePrices = [];
        if ($ferniteCarbideStructurePrice !== null) {
            $sellStructurePrices[self::FERNITE_CARBIDE] = $ferniteCarbideStructurePrice;
        }
        if ($ramStructurePrice !== null) {
            $sellStructurePrices[self::RAM_AMMUNITION_TECH] = $ramStructurePrice;
        }
        if ($productStructurePrice !== null) {
            $sellStructurePrices[self::HAIL_L] = $productStructurePrice;
        }

        $service = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: $jitaPrices,
            structurePrices: [self::SELL_STRUCTURE_ID => $sellStructurePrices],
            adjustedPrices: [
                self::TRITANIUM => $tritaniumAdjustedPrice,
                self::PYERITE => 8.0,
                self::MEXALLON => 40.0,
                self::ISOGEN => 90.0,
                self::NOCXIUM => 700.0,
                self::HYDROGEN_FUEL_BLOCK => 18000.0,
                self::FERNITE_ALLOY => 280.0,
                self::MORPHITE => 9000.0,
                self::RAM_AMMUNITION_TECH => 22000.0,
                self::FERNITE_CARBIDE => 50.0,
                self::CERAMIC_POWDER => 230.0,
                self::FULLERIDES => 145.0,
                self::NITROGEN_FUEL_BLOCK => 16000.0,
                self::CARBON_POLYMERS => 390.0,
                self::PLATINUM_TECHNITE => 350.0,
            ],
            costIndices: self::FACTORY_COST_INDICES,
            isT2: true,
        );

        return $service->analyze(self::HAIL_L, $runs, 2, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.03, 0.045, null);
    }

    /** @return array<int, float> */
    private function hailLJitaPrices(): array
    {
        return [
            self::FULLERIDES => 160.0,
            self::PLATINUM_TECHNITE => 450.0,
            self::HAIL_L => 500.0,
            self::MORPHITE => 10000.0,
            self::RAM_AMMUNITION_TECH => 20000.0,
            self::FERNITE_CARBIDE => 60.0,
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
        ];
    }

    /** @return array<int, float> */
    private function fuelBlockInputJitaPrices(): array
    {
        return [
            self::ENRICHED_URANIUM => 100.0,
            self::OXYGEN => 200.0,
            self::MECHANICAL_PARTS => 300.0,
            self::COOLANT => 400.0,
            self::ROBOTICS => 500.0,
            self::HEAVY_WATER => 10.0,
            self::LIQUID_OZONE => 20.0,
            self::STRONTIUM_CLATHRATES => 30.0,
            self::NITROGEN_ISOTOPES => 45.0,
            self::HYDROGEN_ISOTOPES => 40.0,
        ];
    }

    private function fuelBlockInputAdjustedPrices(): array
    {
        return [
            self::ENRICHED_URANIUM => 95.0,
            self::OXYGEN => 190.0,
            self::MECHANICAL_PARTS => 280.0,
            self::COOLANT => 380.0,
            self::ROBOTICS => 470.0,
            self::HEAVY_WATER => 9.0,
            self::LIQUID_OZONE => 18.0,
            self::STRONTIUM_CLATHRATES => 27.0,
        ];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
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
