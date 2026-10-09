<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\BuyVsBuildService;
use PHPUnit\Framework\Attributes\CoversClass;
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
    private const int UNINDEXED_SYSTEM_ID = 30_000_001;
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

    public function testHailLTenRunsCurrentlyWrongBuildsTheTreeForOneRunButSellsTenRuns(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices());

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2.
        // buildProductionTree() reads the 10 runs as 10 units: ceil(10 / 5000) = 1 run. Materials are those of one run
        // (Fernite Carbide 2940 = ceil(3000 × 0.98) instead of 29400 for 10 runs), while the revenue counts
        // 10 runs × 5000 units and the final job install cost counts 10 runs.
        // totalProductionCost = optimal mix 341800 + root leaves 0 (see the root leaf test) + final job 307000 × 10 × 0.05.
        $this->assertSame([
            'typeId' => self::HAIL_L,
            'typeName' => 'Hail L',
            'isT2' => true,
            'runs' => 10,
            'totalProductionCost' => 495300.0,
            'sellPrice' => 500.0,
            'marginPercent' => 4568.89,
            'components' => [
                [
                    'typeId' => self::FERNITE_CARBIDE,
                    'typeName' => 'Fernite Carbide',
                    'quantity' => 2940,
                    'stage' => 'Reaction',
                    'buildCost' => 166800.0,
                    'buildMaterials' => [
                        ['typeId' => self::HYDROGEN_FUEL_BLOCK, 'typeName' => 'Hydrogen Fuel Block', 'quantity' => 5, 'unitPrice' => 20000.0, 'totalPrice' => 100000.0],
                        ['typeId' => self::FERNITE_ALLOY, 'typeName' => 'Fernite Alloy', 'quantity' => 100, 'unitPrice' => 300.0, 'totalPrice' => 30000.0],
                        ['typeId' => self::CERAMIC_POWDER, 'typeName' => 'Ceramic Powder', 'quantity' => 100, 'unitPrice' => 250.0, 'totalPrice' => 25000.0],
                    ],
                    'buildJobInstallCost' => 11800.0,
                    'buyCostJita' => 176400.0,
                    'buyCostStructure' => null,
                    'verdict' => 'build',
                    'savings' => 9600.0,
                    'savingsPercent' => 5.44,
                    'meUsed' => 0,
                    'runs' => 1,
                ],
                [
                    'typeId' => self::RAM_AMMUNITION_TECH,
                    'typeName' => 'R.A.M.- Ammunition Tech',
                    'quantity' => 1,
                    'stage' => 'T2 Component',
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
                [
                    'typeId' => self::FULLERIDES,
                    'typeName' => 'Fullerides',
                    'quantity' => 1176,
                    'stage' => 'Reaction',
                    'buildCost' => 150000.0,
                    'buildMaterials' => [
                        ['typeId' => self::NITROGEN_FUEL_BLOCK, 'typeName' => 'Nitrogen Fuel Block', 'quantity' => 5, 'unitPrice' => 20000.0, 'totalPrice' => 100000.0],
                        ['typeId' => self::CARBON_POLYMERS, 'typeName' => 'Carbon Polymers', 'quantity' => 100, 'unitPrice' => 500.0, 'totalPrice' => 50000.0],
                        ['typeId' => 16662, 'typeName' => 'Platinum Technite', 'quantity' => 100, 'unitPrice' => 0.0, 'totalPrice' => 0.0],
                    ],
                    'buildJobInstallCost' => 0.0,
                    'buyCostJita' => null,
                    'buyCostStructure' => null,
                    'verdict' => 'build',
                    'savings' => -150000.0,
                    'savingsPercent' => 100.0,
                    'meUsed' => 0,
                    'runs' => 1,
                ],
            ],
            'buildAllCost' => 369466.8,
            'buyAllCost' => 201400.0,
            'optimalMixCost' => 341800.0,
            'buildTypeIds' => [self::FERNITE_CARBIDE, self::FULLERIDES],
            'buyTypeIds' => [self::RAM_AMMUNITION_TECH],
        ], $result);
    }

    public function testComponentWithoutAnyMarketPriceCurrentlyWrongCostsNothingToBuyAndIsBuilt(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices());

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #8 (R10).
        // Fullerides has no Jita nor structure price: its buy cost counts 0 in buyAllCost and the verdict
        // falls back to "build". Platinum Technite has no price either and is a free build material.
        $fullerides = $this->componentOf($result, self::FULLERIDES);
        $this->assertSame('build', $fullerides['verdict']);
        $this->assertNull($fullerides['buyCostJita']);
        $this->assertSame(0.0, $fullerides['buildMaterials'][2]['totalPrice']);
        $this->assertSame(201400.0, $result['buyAllCost']);
    }

    public function testMissingAdjustedPriceCurrentlyWrongLowersTheJobInstallCostSilently(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices());

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #7 (R5, R6, R10).
        // Ceramic Powder has no adjusted price: Fernite Carbide EIV = 5 × 18000 + 100 × 280 = 118000, job = EIV × 0.1.
        // Fullerides inputs have none: EIV 0, job install cost 0. No SCC surcharge nor facility tax is added.
        $this->assertSame(11800.0, $this->componentOf($result, self::FERNITE_CARBIDE)['buildJobInstallCost']);
        $this->assertSame(0.0, $this->componentOf($result, self::FULLERIDES)['buildJobInstallCost']);
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
        $this->assertSame([self::RAM_AMMUNITION_TECH, self::FERNITE_CARBIDE, self::FULLERIDES], $result['buildTypeIds']);
        $this->assertSame([], $result['buyTypeIds']);
        $this->assertSame(369466.8, $result['optimalMixCost']);
    }

    public function testWithoutAnyPriceCurrentlyWrongEveryComponentIsBoughtForFreeAndTheMarginIsUnknown(): void
    {
        $service = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: [],
            structurePrices: [self::SELL_STRUCTURE_ID => [self::HAIL_L => 480.0]],
            adjustedPrices: [],
            costIndices: self::FACTORY_COST_INDICES,
            isT2: true,
        );

        $result = $service->analyze(self::HAIL_L, 10, 2, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.03, 0.045, null);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issues #8 et #7 (R10).
        // Every cost is 0: the verdict falls back to "buy", the total production cost is 0 and the margin is null.
        $this->assertSame(0.0, $result['totalProductionCost']);
        $this->assertSame(480.0, $result['sellPrice']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame([], $result['buildTypeIds']);
        $this->assertSame([self::RAM_AMMUNITION_TECH, self::FERNITE_CARBIDE, self::FULLERIDES], $result['buyTypeIds']);
        foreach ($result['components'] as $component) {
            $this->assertSame('buy', $component['verdict']);
            $this->assertSame(0.0, $component['buildCost']);
            $this->assertSame(0.0, $component['savings']);
            $this->assertSame(0.0, $component['savingsPercent']);
        }
    }

    public function testZeroStructureSellPriceHidesTheJitaPriceAndLeavesTheMarginUnknown(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices(), productStructurePrice: 0.0);

        $this->assertSame(0.0, $result['sellPrice']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame(495300.0, $result['totalProductionCost']);
    }

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
        $this->assertSame(9597.94, $ferniteCarbide['savings']);
        $this->assertSame(500.12, $result['sellPrice']);
        $this->assertSame(500300.02, $result['totalProductionCost']);
        $this->assertSame(369806.51, $result['buildAllCost']);
        $this->assertSame(206397.96, $result['buyAllCost']);
        $this->assertSame(346800.02, $result['optimalMixCost']);
        $this->assertSame(4523.36, $result['marginPercent']);
    }

    // ===========================================
    // Nitrogen Fuel Block: 40 units per run, no intermediate
    // ===========================================

    public function testRootLeavesCurrentlyWrongAreNeverPricedSoNitrogenFuelBlockCostsNothing(): void
    {
        $service = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: [self::NITROGEN_FUEL_BLOCK => 14000.0] + $this->fuelBlockInputJitaPrices(),
            structurePrices: [self::SELL_STRUCTURE_ID => [self::NITROGEN_FUEL_BLOCK => 15000.0]],
            adjustedPrices: [self::NITROGEN_ISOTOPES => 35.0],
            costIndices: self::FACTORY_COST_INDICES,
        );

        $result = $service->analyze(self::NITROGEN_FUEL_BLOCK, 25, 10, self::UNINDEXED_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.01, 0.02, null);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issues #8 et #7 (R10), sans issue dédiée.
        // The Jita batch only asks for the product, the components and their build materials: the leaves of the
        // root job (here every input of the fuel block) get no price and cost 0, although Jita prices them.
        // The system has no cost index: final job install cost 0. Total 0, so the margin is unknown.
        // (#2 also applies, ceil(25 / 40) = 1 run, but no quantity of the root job reaches the output.)
        $this->assertSame([
            'typeId' => self::NITROGEN_FUEL_BLOCK,
            'typeName' => 'Nitrogen Fuel Block',
            'isT2' => false,
            'runs' => 25,
            'totalProductionCost' => 0.0,
            'sellPrice' => 15000.0,
            'marginPercent' => null,
            'components' => [],
            'buildAllCost' => 0.0,
            'buyAllCost' => 0.0,
            'optimalMixCost' => 0.0,
            'buildTypeIds' => [],
            'buyTypeIds' => [],
        ], $result);
    }

    public function testRootLeafCurrentlyWrongIsFreeWhenNoComponentConsumesIt(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices());

        // CARACTÉRISATION : comportement actuel, suspecté faux, sans issue dédiée (proche de #8).
        // Morphite (15 units, 10000 ISK at Jita) is a leaf of the root job: it adds 0 instead of 150000.
        $this->assertSame(341800.0, $result['optimalMixCost']);
        $this->assertSame(495300.0, $result['totalProductionCost']);
    }

    // ===========================================
    // Fernite Carbide: reaction root, 10000 units per run
    // ===========================================

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
            adjustedPrices: [self::HYDROGEN_ISOTOPES => 35.0, self::HYDROGEN_FUEL_BLOCK => 18000.0, self::FERNITE_ALLOY => 280.0],
            costIndices: self::FACTORY_COST_INDICES,
        );

        $result = $service->analyze(self::FERNITE_CARBIDE, 10, 10, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.0, 0.0, null);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issues #2, #16 et #69.
        // ceil(10 / 10000) = 1 reaction run (golden §4.3: 10 runs). Hydrogen Fuel Block is consumed at depth 1 (root)
        // and depth 2 (Fernite Alloy, Ceramic Powder): only the depth-1 demand is analyzed, the deeper ones are
        // bought at Jita inside the reaction build cost. The final reaction job counts 10 runs: 118000 × 10 × 0.1.
        $this->assertSame(207057.5, $result['totalProductionCost']);
        $this->assertSame(60.0, $result['sellPrice']);
        $this->assertSame(2797.75, $result['marginPercent']);
        $this->assertSame(270057.5, $result['buildAllCost']);
        $this->assertSame(154000.0, $result['buyAllCost']);
        $this->assertSame(89057.5, $result['optimalMixCost']);
        $this->assertSame([self::HYDROGEN_FUEL_BLOCK], $result['buildTypeIds']);
        $this->assertSame([self::FERNITE_ALLOY, self::CERAMIC_POWDER], $result['buyTypeIds']);

        $this->assertSame(
            [
                [self::HYDROGEN_FUEL_BLOCK, 5, 'T2 Component', 10, 1, 35057.5, 787.5, 100000.0, null, 'build', 64942.5, 64.94],
                [self::CERAMIC_POWDER, 100, 'Reaction', 0, 1, 114000.0, 9000.0, 25000.0, 24000.0, 'buy', -90000.0, 78.95],
                [self::FERNITE_ALLOY, 100, 'Reaction', 0, 1, 121000.0, 9000.0, 30000.0, null, 'buy', -91000.0, 75.21],
            ],
            array_map(static fn (array $component): array => [
                $component['typeId'], $component['quantity'], $component['stage'], $component['meUsed'], $component['runs'],
                $component['buildCost'], $component['buildJobInstallCost'], $component['buyCostJita'], $component['buyCostStructure'],
                $component['verdict'], $component['savings'], $component['savingsPercent'],
            ], $result['components']),
        );
    }

    public function testFuelBlockConsumedByAReactionCurrentlyWrongIsLabelledT2Component(): void
    {
        $service = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: $this->fuelBlockInputJitaPrices(),
            structurePrices: [],
            adjustedPrices: [],
            costIndices: self::FACTORY_COST_INDICES,
        );

        $result = $service->analyze(self::FERNITE_CARBIDE, 10, 10, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.0, 0.0, null);

        // CARACTÉRISATION : comportement actuel, suspecté faux (libellé), sans issue : tout composant fabriqué
        // de profondeur 1 est « T2 Component », « T1 Component » n'est jamais produit.
        $this->assertSame('T2 Component', $this->componentOf($result, self::HYDROGEN_FUEL_BLOCK)['stage']);
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
            ],
            costIndices: self::FACTORY_COST_INDICES,
            isT2: true,
        );

        return $service->analyze(self::HAIL_L, 10, 2, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.03, 0.045, null);
    }

    /** @return array<int, float> Fullerides and Platinum Technite have no Jita price */
    private function hailLJitaPrices(): array
    {
        return [
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
            self::NITROGEN_FUEL_BLOCK => 20000.0,
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
            self::NITROGEN_ISOTOPES => 40.0,
            self::HYDROGEN_ISOTOPES => 40.0,
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
