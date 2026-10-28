<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\User;
use App\Service\Industry\BuyVsBuildService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuyVsBuildService::class)]
final class BuyVsBuildServiceCharacterizationTest extends TestCase
{
    use BuildsBuyVsBuildOnGoldenSde;

    private const int FACTORY_SYSTEM_ID = 30_002_187;
    private const int SELL_STRUCTURE_ID = 1_035_466_617_946;
    private const float BROKER_FEE_RATE = 0.03;
    private const float SALES_TAX_RATE = 0.045;

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

    private const array FACTORY_COST_INDICES = [
        self::FACTORY_SYSTEM_ID => ['manufacturing' => 0.05, 'reaction' => 0.1, 'copying' => 0.03, 'invention' => 0.06],
    ];

    private const float HAIL_L_ONE_RUN_TOTAL_PRODUCTION_COST = 587567.84;
    private const float HAIL_L_ONE_RUN_OPTIMAL_MIX_COST = 388252.5;
    private const float FERNITE_CARBIDE_ONE_RUN_BUILD_COST = 175092.5;
    private const int FERNITE_CARBIDE_ONE_RUN_QUANTITY = 2940;

    #[Group('issue-2')]
    #[Group('issue-101')]
    #[Group('issue-98')]
    public function testHailLTenRunsBuildsTenRootRunsAndSellsTenRuns(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices(), runs: 10);

        $this->assertSame([
            'typeId' => self::HAIL_L,
            'typeName' => 'Hail L',
            'isT2' => true,
            'runs' => 10,
            'totalProductionCost' => 3430885.21,
            'sellPrice' => 500.0,
            'marginPercent' => 574.02,
            'components' => [
                [
                    'typeId' => self::FERNITE_CARBIDE,
                    'typeName' => 'Fernite Carbide',
                    'quantity' => 29400,
                    'stage' => 'Reaction',
                    'buildCost' => 525277.5,
                    'buildMaterials' => [
                        ['typeId' => self::HYDROGEN_FUEL_BLOCK, 'typeName' => 'Hydrogen Fuel Block', 'quantity' => 15, 'unitPrice' => 20000.0, 'totalPrice' => 300000.0],
                        ['typeId' => self::FERNITE_ALLOY, 'typeName' => 'Fernite Alloy', 'quantity' => 300, 'unitPrice' => 300.0, 'totalPrice' => 90000.0],
                        ['typeId' => self::CERAMIC_POWDER, 'typeName' => 'Ceramic Powder', 'quantity' => 300, 'unitPrice' => 250.0, 'totalPrice' => 75000.0],
                    ],
                    'buildJobInstallCost' => 60277.5,
                    'buyCostJita' => 1764000.0,
                    'buyCostStructure' => null,
                    'buyCostJitaCoveragePercent' => null,
                    'verdict' => 'build',
                    'savings' => 1238722.5,
                    'savingsPercent' => 70.22,
                    'meUsed' => 0,
                    'runs' => 3,
                    'missingData' => [],
                    'warnings' => [],
                ],
                [
                    'typeId' => self::FULLERIDES,
                    'typeName' => 'Fullerides',
                    'quantity' => 11760,
                    'stage' => 'Reaction',
                    'buildCost' => 887780.0,
                    'buildMaterials' => [
                        ['typeId' => self::NITROGEN_FUEL_BLOCK, 'typeName' => 'Nitrogen Fuel Block', 'quantity' => 20, 'unitPrice' => 21000.0, 'totalPrice' => 420000.0],
                        ['typeId' => self::CARBON_POLYMERS, 'typeName' => 'Carbon Polymers', 'quantity' => 400, 'unitPrice' => 500.0, 'totalPrice' => 200000.0],
                        ['typeId' => self::PLATINUM_TECHNITE, 'typeName' => 'Platinum Technite', 'quantity' => 400, 'unitPrice' => 450.0, 'totalPrice' => 180000.0],
                    ],
                    'buildJobInstallCost' => 87780.0,
                    'buyCostJita' => 1881600.0,
                    'buyCostStructure' => null,
                    'buyCostJitaCoveragePercent' => null,
                    'verdict' => 'build',
                    'savings' => 993820.0,
                    'savingsPercent' => 52.82,
                    'meUsed' => 0,
                    'runs' => 4,
                    'missingData' => [],
                    'warnings' => [],
                ],
                [
                    'typeId' => self::RAM_AMMUNITION_TECH,
                    'typeName' => 'R.A.M.- Ammunition Tech',
                    'quantity' => 10,
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
                    'buyCostJita' => 200000.0,
                    'buyCostStructure' => 250000.0,
                    'buyCostJitaCoveragePercent' => null,
                    'verdict' => 'build',
                    'savings' => 195325.67,
                    'savingsPercent' => 78.13,
                    'meUsed' => 10,
                    'runs' => 1,
                    'missingData' => [],
                    'warnings' => [],
                ],
            ],
            'buildAllCost' => 1467731.83,
            'buyAllCost' => 3895600.0,
            'optimalMixCost' => 1467731.83,
            'buildTypeIds' => [self::RAM_AMMUNITION_TECH, self::FERNITE_CARBIDE, self::FULLERIDES],
            'buyTypeIds' => [],
            'missingData' => [],
            'warnings' => [],
        ], $result);
    }

    public function testComponentsAreSortedByDescendingSavings(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices());

        $this->assertSame(
            [
                [self::FERNITE_CARBIDE, 1307.5],
                [self::RAM_AMMUNITION_TECH, -29674.33],
                [self::FULLERIDES, -33785.0],
            ],
            array_map(static fn (array $component): array => [$component['typeId'], $component['savings']], $result['components']),
        );
    }

    public function testComponentWithUnknownSavingsIsSortedAfterAComponentWhoseBuildCostEqualsItsBuyCost(): void
    {
        $result = $this->analyzeHailL(
            array_diff_key($this->hailLJitaPrices(), [self::RAM_AMMUNITION_TECH => true]),
            ramStructurePrice: null,
            ferniteCarbideStructurePrice: self::FERNITE_CARBIDE_ONE_RUN_BUILD_COST / self::FERNITE_CARBIDE_ONE_RUN_QUANTITY,
        );

        $this->assertSame(
            [
                [self::FERNITE_CARBIDE, 'build', 0.0],
                [self::FULLERIDES, 'buy', -33785.0],
                [self::RAM_AMMUNITION_TECH, 'build', null],
            ],
            array_map(static fn (array $component): array => [$component['typeId'], $component['verdict'], $component['savings']], $result['components']),
        );
    }

    public function testUserWithoutIdIsRefusedBecauseItHasNoConfiguredStructure(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A user without id has no configured structure.');

        $this->analyzeHailL($this->hailLJitaPrices(), user: new User());
    }

    public function testProductWithoutBlueprintNorReactionCannotBeAnalyzed(): void
    {
        $service = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: $this->hailLJitaPrices(),
            structurePrices: [],
            adjustedPrices: $this->hailLAdjustedPrices(),
            costIndices: self::FACTORY_COST_INDICES,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Product 34 has no blueprint nor reaction: it cannot be built.');

        $service->analyze(self::TRITANIUM, 1, 10, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, self::BROKER_FEE_RATE, self::SALES_TAX_RATE, null);
    }

    #[Group('issue-98')]
    public function testTotalProductionCostIsTheOptimalMixPlusTheRootLeavesTheRootInstallCostAndTheInvention(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices());

        $this->assertSame([self::FERNITE_CARBIDE, self::RAM_AMMUNITION_TECH, self::FULLERIDES], array_column($result['components'], 'typeId'));
        $this->assertSame(self::HAIL_L_ONE_RUN_OPTIMAL_MIX_COST, $result['optimalMixCost']);
        $this->assertSame(self::HAIL_L_ONE_RUN_TOTAL_PRODUCTION_COST, $result['totalProductionCost']);
        $this->assertSame(293.57, $result['marginPercent']);
        $this->assertTrue($result['isT2']);
    }

    public function testInventedProductIgnoresTheRequestedMeAndConsumesWithTheMeOfTheInventedCopy(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices(), meLevel: 10);

        $this->assertSame(
            [self::FERNITE_CARBIDE => 2940, self::RAM_AMMUNITION_TECH => 1, self::FULLERIDES => 1176],
            array_column($result['components'], 'quantity', 'typeId'),
        );
        $this->assertSame(self::HAIL_L_ONE_RUN_TOTAL_PRODUCTION_COST, $result['totalProductionCost']);
    }

    public function testInventedProductWithoutInventionProbabilityHasAnUnknownTotalAndListsTheMissingProbability(): void
    {
        $this->hailLInventionProbability = null;

        $result = $this->analyzeHailL($this->hailLJitaPrices());

        $this->assertTrue($result['isT2']);
        $this->assertSame(self::HAIL_L_ONE_RUN_OPTIMAL_MIX_COST, $result['optimalMixCost']);
        $this->assertNull($result['totalProductionCost']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame([self::inventionProbabilityMissing(self::HAIL_L_T1_BLUEPRINT)], $result['missingData']);
    }

    public function testUserIndustryBlacklistIsIgnoredSoEveryComponentIsStillCompared(): void
    {
        $user = $this->goldenUser()->setIndustryBlacklistTypeIds([self::FERNITE_CARBIDE, self::FULLERIDES]);

        $result = $this->analyzeHailL($this->hailLJitaPrices(), user: $user);

        $this->assertSame([self::FERNITE_CARBIDE, self::RAM_AMMUNITION_TECH, self::FULLERIDES], array_column($result['components'], 'typeId'));
        $this->assertSame(self::HAIL_L_ONE_RUN_TOTAL_PRODUCTION_COST, $result['totalProductionCost']);
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
        $this->assertSame([self::marketPriceMissing(self::PLATINUM_TECHNITE), self::marketPriceMissing(self::FULLERIDES)], $fullerides['missingData']);
        $this->assertSame([self::FERNITE_CARBIDE, self::RAM_AMMUNITION_TECH, self::FULLERIDES], array_column($result['components'], 'typeId'));
        $this->assertNull($result['buildAllCost']);
        $this->assertNull($result['buyAllCost']);
        $this->assertNull($result['optimalMixCost']);
        $this->assertNull($result['totalProductionCost']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame([self::marketPriceMissing(self::PLATINUM_TECHNITE), self::marketPriceMissing(self::FULLERIDES)], $result['missingData']);
    }

    public function testStructurePriceIsPreferredToACheaperJitaPriceForTheBuyCost(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices());

        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame(20000.0, $ram['buyCostJita']);
        $this->assertSame(25000.0, $ram['buyCostStructure']);
        $this->assertSame(-29674.33, $ram['savings']);
        $this->assertSame(54.27, $ram['savingsPercent']);
    }

    public function testComponentWhoseBuildCostExceedsItsBuyCostByAtMostOneIskIsBuilt(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices(), ramStructurePrice: 54673.5);

        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame(54674.33, $ram['buildCost']);
        $this->assertSame(54673.5, $ram['buyCostStructure']);
        $this->assertSame('build', $ram['verdict']);
        $this->assertSame(-0.83, $ram['savings']);
        $this->assertSame(0.0, $ram['savingsPercent']);
        $this->assertSame([self::RAM_AMMUNITION_TECH, self::FERNITE_CARBIDE], $result['buildTypeIds']);
        $this->assertSame([self::FULLERIDES], $result['buyTypeIds']);
        $this->assertSame(417926.83, $result['optimalMixCost']);
    }

    public function testComponentWhoseBuildCostExceedsItsBuyCostByMoreThanOneIskIsBought(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices(), ramStructurePrice: 54673.0);

        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame('buy', $ram['verdict']);
        $this->assertSame(-1.33, $ram['savings']);
        $this->assertSame([self::FERNITE_CARBIDE], $result['buildTypeIds']);
        $this->assertSame([self::RAM_AMMUNITION_TECH, self::FULLERIDES], $result['buyTypeIds']);
        $this->assertSame(417925.5, $result['optimalMixCost']);
    }

    #[Group('issue-8')]
    public function testWithoutAnyJitaPriceEveryComponentHasAnUnknownVerdictAndEveryCostIsUnknown(): void
    {
        $service = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: [],
            structurePrices: [self::SELL_STRUCTURE_ID => [self::HAIL_L => 480.0]],
            adjustedPrices: $this->hailLAdjustedPrices(),
            costIndices: self::FACTORY_COST_INDICES,
        );

        $result = $service->analyze(self::HAIL_L, 1, 2, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, self::BROKER_FEE_RATE, self::SALES_TAX_RATE, null);

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
        $this->assertSame(
            [
                self::marketPriceMissing(self::HYDROGEN_FUEL_BLOCK),
                self::marketPriceMissing(self::FERNITE_ALLOY),
                self::marketPriceMissing(self::CERAMIC_POWDER),
                self::marketPriceMissing(self::FERNITE_CARBIDE),
            ],
            $this->componentOf($result, self::FERNITE_CARBIDE)['missingData'],
        );
        $this->assertNull($result['buildAllCost']);
        $this->assertNull($result['buyAllCost']);
        $this->assertNull($result['optimalMixCost']);
        $this->assertNull($result['totalProductionCost']);
        $this->assertSame(480.0, $result['sellPrice']);
        $this->assertNull($result['marginPercent']);
        $this->assertEqualsCanonicalizing(
            array_map(self::marketPriceMissing(...), [
                self::TRITANIUM, self::PYERITE, self::MEXALLON, self::ISOGEN, self::NOCXIUM, self::RAM_AMMUNITION_TECH,
                self::HYDROGEN_FUEL_BLOCK, self::FERNITE_ALLOY, self::CERAMIC_POWDER, self::FERNITE_CARBIDE,
                self::NITROGEN_FUEL_BLOCK, self::CARBON_POLYMERS, self::PLATINUM_TECHNITE, self::FULLERIDES,
                self::MORPHITE, self::STUB_DATACORE_A, self::STUB_DATACORE_B,
            ]),
            $result['missingData'],
        );
    }

    #[Group('issue-8')]
    public function testZeroStructureSellPriceCurrentlyHidesTheJitaPriceAndLeavesTheMarginUnknownIssue8(): void
    {
        $result = $this->analyzeHailL($this->hailLJitaPrices(), productStructurePrice: 0.0);

        $this->assertSame(0.0, $result['sellPrice']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame(self::HAIL_L_ONE_RUN_TOTAL_PRODUCTION_COST, $result['totalProductionCost']);
    }

    #[Group('issue-8')]
    public function testProductWithoutSellPriceHasAnUnknownSellPriceAndMargin(): void
    {
        $result = $this->analyzeHailL(array_diff_key($this->hailLJitaPrices(), [self::HAIL_L => true]));

        $this->assertNull($result['sellPrice']);
        $this->assertNull($result['marginPercent']);
        $this->assertSame(self::HAIL_L_ONE_RUN_TOTAL_PRODUCTION_COST, $result['totalProductionCost']);
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

        $ram = $this->componentOf($result, self::RAM_AMMUNITION_TECH);
        $this->assertSame(['typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'quantity' => 501, 'unitPrice' => 5.68, 'totalPrice' => 2844.68], $ram['buildMaterials'][0]);
        $this->assertSame(4369.38, $ram['buildJobInstallCost']);
        $this->assertSame(55014.06, $ram['buildCost']);
        $this->assertSame(30000.02, $ram['buyCostJita']);
        $this->assertSame(-25014.04, $ram['savings']);
        $this->assertSame(45.47, $ram['savingsPercent']);
        $ferniteCarbide = $this->componentOf($result, self::FERNITE_CARBIDE);
        $this->assertSame(176397.94, $ferniteCarbide['buyCostStructure']);
        $this->assertSame(1305.44, $ferniteCarbide['savings']);
        $this->assertSame(500.12, $result['sellPrice']);
        $this->assertSame(592567.85, $result['totalProductionCost']);
        $this->assertSame(452051.56, $result['buildAllCost']);
        $this->assertSame(394557.96, $result['buyAllCost']);
        $this->assertSame(393252.52, $result['optimalMixCost']);
        $this->assertSame(290.35, $result['marginPercent']);
    }

    #[Group('issue-115')]
    public function testProductAbsentFromTheSdeHasAnUnknownTypeNameAndUnchangedCosts(): void
    {
        $this->typesMissingFromSde = [self::HAIL_L];

        $result = $this->analyzeHailL($this->hailLJitaPrices());

        $this->assertSame(self::HAIL_L, $result['typeId']);
        $this->assertNull($result['typeName']);
        $this->assertSame(self::HAIL_L_ONE_RUN_TOTAL_PRODUCTION_COST, $result['totalProductionCost']);
    }

    #[Group('issue-115')]
    public function testComponentAbsentFromTheSdeHasAnUnknownTypeNameAndUnchangedVerdictAndSavings(): void
    {
        $this->typesMissingFromSde = [self::RAM_AMMUNITION_TECH];

        $ram = $this->componentOf($this->analyzeHailL($this->hailLJitaPrices()), self::RAM_AMMUNITION_TECH);

        $this->assertNull($ram['typeName']);
        $this->assertSame(54674.33, $ram['buildCost']);
        $this->assertSame('buy', $ram['verdict']);
        $this->assertSame(-29674.33, $ram['savings']);
    }

    #[Group('issue-115')]
    public function testBuildMaterialAbsentFromTheSdeHasAnUnknownTypeNameAndUnchangedQuantityAndPrice(): void
    {
        $this->typesMissingFromSde = [self::TRITANIUM];

        $ram = $this->componentOf($this->analyzeHailL($this->hailLJitaPrices()), self::RAM_AMMUNITION_TECH);

        $this->assertSame(['typeId' => self::TRITANIUM, 'typeName' => null, 'quantity' => 501, 'unitPrice' => 5.0, 'totalPrice' => 2505.0], $ram['buildMaterials'][0]);
        $this->assertSame(54674.33, $ram['buildCost']);
    }

    #[Group('issue-98')]
    public function testRootLeavesArePricedAtJitaSoNitrogenFuelBlockCostsItsMaterials(): void
    {
        $service = $this->buyVsBuildServiceOnGoldenSde(
            jitaPrices: [self::NITROGEN_FUEL_BLOCK => 14000.0] + $this->fuelBlockInputJitaPrices(),
            structurePrices: [self::SELL_STRUCTURE_ID => [self::NITROGEN_FUEL_BLOCK => 15000.0]],
            adjustedPrices: [self::NITROGEN_ISOTOPES => 36.0] + $this->fuelBlockInputAdjustedPrices(),
            costIndices: self::FACTORY_COST_INDICES,
        );

        $result = $service->analyze(self::NITROGEN_FUEL_BLOCK, 1, 10, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, 0.01, 0.02, null);

        $this->assertSame([
            'typeId' => self::NITROGEN_FUEL_BLOCK,
            'typeName' => 'Nitrogen Fuel Block',
            'isT2' => false,
            'runs' => 1,
            'totalProductionCost' => 39452.95,
            'sellPrice' => 15000.0,
            'marginPercent' => 1375.17,
            'components' => [],
            'buildAllCost' => 0.0,
            'buyAllCost' => 0.0,
            'optimalMixCost' => 0.0,
            'buildTypeIds' => [],
            'buyTypeIds' => [],
            'missingData' => [],
            'warnings' => [],
        ], $result);
    }

    #[Group('issue-98')]
    public function testRootLeafIsPricedAtJitaWhenNoComponentConsumesIt(): void
    {
        $withMorphiteAtJita = $this->analyzeHailL($this->hailLJitaPrices());
        $withDearerMorphite = $this->analyzeHailL([self::MORPHITE => 10400.0] + $this->hailLJitaPrices());

        $this->assertSame(self::HAIL_L_ONE_RUN_TOTAL_PRODUCTION_COST, $withMorphiteAtJita['totalProductionCost']);
        $this->assertSame(593567.84, $withDearerMorphite['totalProductionCost']);
        $this->assertSame(self::HAIL_L_ONE_RUN_OPTIMAL_MIX_COST, $withDearerMorphite['optimalMixCost']);
    }

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

        $this->assertSame(213_773_432.15, $result['optimalMixCost']);
        $this->assertSame(547_438_954.65, $result['totalProductionCost']);
        $this->assertSame(-45.2, $result['marginPercent']);
        $this->assertSame([self::CAPITAL_JUMP_DRIVE], $result['buyTypeIds']);
    }

    #[Group('issue-2')]
    #[Group('issue-101')]
    public function testFerniteCarbideTenRunsBuildsTenReactionRunsAndPricesEachComponentOnTheRootDemandOnly(): void
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

        $this->assertSame(815297.65, $result['totalProductionCost']);
        $this->assertSame(60.0, $result['sellPrice']);
        $this->assertSame(635.93, $result['marginPercent']);
        $this->assertSame(1298595.15, $result['buildAllCost']);
        $this->assertSame(1540000.0, $result['buyAllCost']);
        $this->assertSame(614372.65, $result['optimalMixCost']);
        $this->assertSame([self::HYDROGEN_FUEL_BLOCK], $result['buildTypeIds']);
        $this->assertSame([self::FERNITE_ALLOY, self::CERAMIC_POWDER], $result['buyTypeIds']);
        $this->assertSame(
            [
                [self::HYDROGEN_FUEL_BLOCK, 'Hydrogen Fuel Block', 50, 'T1 Component', 10, 2, 74372.65, 6232.65, 1000000.0, null, 'build', 925627.35, 92.56],
                [self::FERNITE_ALLOY, 'Fernite Alloy', 1000, 'Reaction', 0, 5, 631962.5, 71962.5, 300000.0, null, 'buy', -331962.5, 52.53],
                [self::CERAMIC_POWDER, 'Ceramic Powder', 1000, 'Reaction', 0, 5, 592260.0, 67260.0, 250000.0, 240000.0, 'buy', -352260.0, 59.48],
            ],
            array_map(static fn (array $component): array => [
                $component['typeId'], $component['typeName'], $component['quantity'], $component['stage'], $component['meUsed'], $component['runs'],
                $component['buildCost'], $component['buildJobInstallCost'], $component['buyCostJita'], $component['buyCostStructure'],
                $component['verdict'], $component['savings'], $component['savingsPercent'],
            ], $result['components']),
        );
        $this->assertSame(
            [
                ['typeId' => self::HYDROGEN_FUEL_BLOCK, 'typeName' => 'Hydrogen Fuel Block', 'quantity' => 25, 'unitPrice' => 20000.0, 'totalPrice' => 500000.0],
                ['typeId' => self::EVAPORITE_DEPOSITS, 'typeName' => 'Evaporite Deposits', 'quantity' => 500, 'unitPrice' => 20.0, 'totalPrice' => 10000.0],
                ['typeId' => self::SILICATES, 'typeName' => 'Silicates', 'quantity' => 500, 'unitPrice' => 30.0, 'totalPrice' => 15000.0],
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
        $this->assertSame('Reaction', $this->componentOf($result, self::FERNITE_ALLOY)['stage']);
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

    private function analyzeHailL(
        array $jitaPrices,
        ?float $ramStructurePrice = 25000.0,
        ?float $productStructurePrice = null,
        ?float $ferniteCarbideStructurePrice = null,
        float $tritaniumAdjustedPrice = 4.0,
        int $runs = 1,
        int $meLevel = 2,
        ?User $user = null,
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
            adjustedPrices: [self::TRITANIUM => $tritaniumAdjustedPrice] + $this->hailLAdjustedPrices(),
            costIndices: self::FACTORY_COST_INDICES,
        );

        return $service->analyze(self::HAIL_L, $runs, $meLevel, self::FACTORY_SYSTEM_ID, self::SELL_STRUCTURE_ID, self::BROKER_FEE_RATE, self::SALES_TAX_RATE, $user);
    }

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
            self::STUB_DATACORE_A => 3500.0,
            self::STUB_DATACORE_B => 4200.0,
        ];
    }

    private function hailLAdjustedPrices(): array
    {
        return [
            self::TRITANIUM => 4.0,
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
        ];
    }

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
