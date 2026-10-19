<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedStructure;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\EsiClient;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\InventionService;
use App\Service\Industry\ProfitMarginService;
use App\Service\Industry\PublicContractPriceService;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Characterization of /profit-margin on the goldens, before ProfitMarginService moves to the industry engine (spec §7, rank 1).
 *
 * Real IndustryTreeService, EsiCostIndexService and InventionService; the SDE comes from tests/Fixtures/Golden/sde-blueprint-recipes.json.
 * Boundaries are stubbed deterministically, with a distinct price per typeId so that a price attached to the wrong type shows:
 * Jita price = (typeId mod 100 000) / 100 ISK (Tritanium 0.34, Nitrogen Isotopes 178.88), adjusted price = (typeId mod 100 000) / 1 000 ISK.
 * Blueprint typeIds and invention data (Hail L) are synthetic: the fixture carries neither.
 * Only the tests named after B1 (issue #2) ask for more than 1 run of a product made by the hundreds: 1 run gives the same tree before and after B1.
 */
#[CoversClass(ProfitMarginService::class)]
class ProfitMarginGoldenCharacterizationTest extends TestCase
{
    private const string SDE_RECIPES_FIXTURE = __DIR__.'/../../../Fixtures/Golden/sde-blueprint-recipes.json';

    private const int SYNTHETIC_BLUEPRINT_OFFSET = 1_000_000_000;
    private const int MANUFACTURING_SYSTEM_ID = 30000142;
    private const int OTHER_SYSTEM_ID = 30002510;
    private const int SELL_STRUCTURE_ID = 1035466617946;
    private const int PRICE_TYPE_ID_MODULO = 100_000;
    private const float JITA_PRICE_DIVISOR = 100.0;
    private const float ADJUSTED_PRICE_DIVISOR = 1_000.0;
    private const float MANUFACTURING_COST_INDEX = 0.05;
    private const float REACTION_COST_INDEX = 0.04;
    private const float COPYING_COST_INDEX = 0.03;
    private const float INVENTION_COST_INDEX = 0.06;
    private const float FACILITY_TAX_PERCENT = 10.0;
    private const float BROKER_FEE_RATE = 0.036;
    private const float SALES_TAX_RATE = 0.036;

    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int HAIL_L = 12779;
    private const int RAM_AMMUNITION_TECH = 11476;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;
    private const int FULLERIDES = 16679;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int NOMAD = 28846;
    private const int ROBOTICS = 9848;
    private const int TRITANIUM = 34;
    private const int NITROGEN_ISOTOPES = 17888;

    private const int HAIL_L_T1_BLUEPRINT = 2_000_000_001;
    private const int HAIL_L_T1_PRODUCT = 2_000_000_002;
    private const int HAIL_L_T1_OUTPUT_PER_RUN = 5000;
    private const int STUB_DATACORE_A = 2_000_000_011;
    private const int STUB_DATACORE_B = 2_000_000_012;
    private const int DATACORE_QUANTITY_PER_ATTEMPT = 2;
    private const float HAIL_L_INVENTION_PROBABILITY = 0.34;
    private const int HAIL_L_INVENTED_RUNS = 10;
    private const int PROCESS_DECRYPTOR = 34205;

    /** One run at ME 10 without structure bonus, leaf typeId => quantity. */
    private const array NITROGEN_FUEL_BLOCK_ONE_RUN_LEAVES = [
        44 => 4,
        3683 => 20,
        3689 => 4,
        9832 => 9,
        self::ROBOTICS => 1,
        16272 => 153,
        16273 => 315,
        16275 => 18,
        self::NITROGEN_ISOTOPES => 405,
    ];
    private const float NITROGEN_FUEL_BLOCK_ONE_RUN_MATERIAL_COST = 153401.29;

    /** One root run at ME 2, Fernite Carbide and Fullerides blacklisted, leaf typeId => quantity. */
    private const array HAIL_L_ONE_RUN_LEAVES = [
        self::TRITANIUM => 496,
        35 => 396,
        36 => 198,
        37 => 74,
        38 => 33,
        11399 => 14,
        self::FERNITE_CARBIDE => 2764,
        self::FULLERIDES => 1106,
    ];
    private const float HAIL_L_ONE_RUN_MATERIAL_COST = 647325.76;
    private const float HAIL_L_ONE_RUN_JOB_INSTALL_COST = 3864.470445;
    private const float HAIL_L_INVENTION_COST = 254.424;
    private const float HAIL_L_COPY_COST = 42.928116;
    private const float HAIL_L_ONE_RUN_TOTAL_COST = 651487.582561;

    /** @var array<string, array{name: string, activity: string, outputPerRun: int, materials: array<string, int>}> */
    private array $recipes;
    /** @var array<string, string> */
    private array $typeNames;

    /** @var list<int> */
    private array $leavesWithoutJitaPrice = [];
    /** @var list<int> */
    private array $typesWithoutAdjustedPrice = [];
    private bool $withManufacturingCostIndex = true;
    private ?float $hailLInventionProbability = self::HAIL_L_INVENTION_PROBABILITY;
    /** @var list<IndustryStructureConfig> */
    private array $structureConfigs;
    /** @var array<int, float> */
    private array $dailyVolumes = [];
    private ?string $cachedSellStructureName = null;
    private ?float $jitaSellPriceOfProduct = null;
    private ?float $structureSellPriceOfProduct = null;
    private ?float $structureBuyPriceOfProduct = null;
    private ?float $contractSellPriceOfProduct = null;
    private int $contractCountOfProduct = 0;
    private float $brokerFeeRate = self::BROKER_FEE_RATE;
    private float $salesTaxRate = self::SALES_TAX_RATE;

    protected function setUp(): void
    {
        /** @var array{recipes: array<string, array{name: string, activity: string, outputPerRun: int, materials: array<string, int>}>, typeNames: array<string, string>} $fixture */
        $fixture = json_decode((string) file_get_contents(self::SDE_RECIPES_FIXTURE), true, flags: \JSON_THROW_ON_ERROR);
        $this->recipes = $fixture['recipes'];
        $this->typeNames = $fixture['typeNames'] + [
            (string) self::HAIL_L_T1_PRODUCT => 'Stub T1 ammunition',
            (string) self::STUB_DATACORE_A => 'Stub Datacore A',
            (string) self::STUB_DATACORE_B => 'Stub Datacore B',
        ];
        $this->structureConfigs = [
            $this->structure(solarSystemId: self::MANUFACTURING_SYSTEM_ID, facilityTaxPercent: self::FACILITY_TAX_PERCENT, isDefault: false),
        ];
    }

    // ===========================================
    // Nitrogen Fuel Block: 40 units per run, ME 10, no structure
    // 1 run is neutral to B1 (issue #2): 1 run read as 1 unit is still ceil(1 / 40) = 1 run.
    // ===========================================

    public function testNitrogenFuelBlockCurrentlyPricesOneRunOfMaterialsForTwentyFiveRunsOfOutput(): void
    {
        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 25, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2 (B1)
        // The tree reads the 25 runs as 25 units: ceil(25 / 40) = 1 run of materials, while the output counts 25 runs.
        // Golden: Robotics 25, Nitrogen Isotopes 10125, Heavy Water 3825, Liquid Ozone 7875.
        $this->assertSame(40, $result['outputPerRun']);
        $this->assertSame(1000, $result['outputQuantity']);
        $this->assertSame(self::NITROGEN_FUEL_BLOCK_ONE_RUN_LEAVES, $this->leafQuantities($result));
        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_ONE_RUN_MATERIAL_COST, $result['materialCost'], 1e-6);
        $this->assertSame(
            [[self::NITROGEN_FUEL_BLOCK, 'Nitrogen Fuel Block', 'manufacturing', 1]],
            array_map(static fn (array $step): array => [$step['productTypeId'], $step['productName'], $step['activityType'], $step['runs']], $result['jobInstallSteps']),
        );
        // EIV(ME 0, 1 run) 17 031.184 x 0.05, no user so no facility tax
        $this->assertEqualsWithDelta(851.5592, $result['jobInstallSteps'][0]['installCost'], 1e-6);
        $this->assertEqualsWithDelta(154252.8492, $result['totalCost'], 1e-6);
        $this->assertEqualsWithDelta(154.2528492, $result['costPerUnit'], 1e-9);
    }

    public function testJobInstallCostCurrentlyMultipliesCostIndexByFacilityTaxWithoutSccNorRoleBonus(): void
    {
        $result = $this->analyze(
            self::NITROGEN_FUEL_BLOCK,
            runs: 1,
            me: 10,
            solarSystemId: self::MANUFACTURING_SYSTEM_ID,
            user: $this->userWithTaxedStructureInManufacturingSystem(),
        );

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #7 (R6)
        // Current: EIV(ME 0) x 1 run x cost index x (1 + tax %) = 17 031.184 x 0.05 x 1.10.
        // R6: tax added to the cost index term, SCC 4 % on the base.
        $this->assertEqualsWithDelta(936.71512, $result['jobInstallCost'], 1e-6);
        $this->assertSame(1, $result['jobInstallSteps'][0]['runs']);
    }

    public function testFacilityTaxFallsBackToDefaultStructure(): void
    {
        $this->structureConfigs = [
            $this->structure(solarSystemId: self::OTHER_SYSTEM_ID, facilityTaxPercent: 5.0, isDefault: false),
            $this->structure(solarSystemId: self::OTHER_SYSTEM_ID, facilityTaxPercent: 20.0, isDefault: true),
        ];

        $result = $this->analyze(
            self::NITROGEN_FUEL_BLOCK,
            runs: 1,
            me: 10,
            solarSystemId: self::MANUFACTURING_SYSTEM_ID,
            user: $this->userWithTaxedStructureInManufacturingSystem(),
        );

        // No structure in the manufacturing system: the default structure's 20 % applies, not the first one's 5 %.
        $this->assertEqualsWithDelta(17031.184 * 0.05 * 1.20, $result['jobInstallCost'], 1e-6);
    }

    public function testMissingCostIndexCurrentlyYieldsZeroInstallCostInsteadOfUnknown(): void
    {
        $this->withManufacturingCostIndex = false;

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #7 (R6, R10)
        $this->assertSame(0.0, $result['jobInstallCost']);
        $this->assertSame(0.0, $result['jobInstallSteps'][0]['installCost']);
        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_ONE_RUN_MATERIAL_COST, $result['totalCost'], 1e-6);
    }

    public function testMaterialWithoutAdjustedPriceIsCurrentlyLeftOutOfEiv(): void
    {
        $this->typesWithoutAdjustedPrice = [self::ROBOTICS];

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #7 (R5, R10)
        // EIV = 17 031.184 - 1 Robotics x 9.848 = 17 021.336 instead of an unknown cost.
        $this->assertEqualsWithDelta(851.0668, $result['jobInstallCost'], 1e-6);
        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_ONE_RUN_MATERIAL_COST + 851.0668, $result['totalCost'], 1e-6);
    }

    public function testMaterialWithoutJitaPriceCurrentlyCountsAsFreeInMaterialCost(): void
    {
        $this->leavesWithoutJitaPrice = [self::ROBOTICS];

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #8 (R10)
        $robotics = $this->materialLine($result, self::ROBOTICS);
        $this->assertSame(1, $robotics['quantity']);
        $this->assertSame(0.0, $robotics['unitPrice']);
        $this->assertSame(0.0, $robotics['totalPrice']);
        $this->assertSame(
            ['typeId' => self::NITROGEN_ISOTOPES, 'typeName' => 'Nitrogen Isotopes', 'quantity' => 405, 'unitPrice' => 178.88, 'totalPrice' => 405 * 178.88],
            $this->materialLine($result, self::NITROGEN_ISOTOPES),
        );
        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_ONE_RUN_MATERIAL_COST - 98.48, $result['materialCost'], 1e-6);
        $this->assertEqualsWithDelta($result['materialCost'] + $result['jobInstallCost'], $result['totalCost'], 1e-6);
    }

    public function testMarginsCurrentlyDivideProfitByProductionCostAndChargeBrokerFeeOnStructureBuyOrders(): void
    {
        $this->jitaSellPriceOfProduct = 4500.0;
        $this->structureSellPriceOfProduct = 4400.0;
        $this->structureBuyPriceOfProduct = 4000.0;
        $this->contractSellPriceOfProduct = 4300.0;
        $this->contractCountOfProduct = 7;

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);
        $totalCost = $result['totalCost'];

        // 40 units; fees = revenue x (broker 3.6 % + sales tax 3.6 %), none on contracts.
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #19 (margin = profit / cost, a markup)
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue à ouvrir (selling into a buy order pays no broker fee)
        $this->assertSame(self::NITROGEN_FUEL_BLOCK, $result['typeId']);
        $this->assertSame('Nitrogen Fuel Block', $result['typeName']);
        $this->assertSame(1, $result['runs']);
        $this->assertSame(40, $result['outputQuantity']);
        $this->assertSame(self::BROKER_FEE_RATE, $result['brokerFeeRate']);
        $this->assertSame(self::SALES_TAX_RATE, $result['salesTaxRate']);
        $this->assertSame([
            'jitaSell' => 4500.0,
            'structureSell' => 4400.0,
            'structureBuy' => 4000.0,
            'contractSell' => 4300.0,
            'contractCount' => 7,
            'structureId' => self::SELL_STRUCTURE_ID,
            'structureName' => 'Structure #'.self::SELL_STRUCTURE_ID,
        ], $result['sellPrices']);
        $this->assertMargin(180000.0, 12960.0, $totalCost, $result['margins']['jitaSell']);
        $this->assertMargin(176000.0, 12672.0, $totalCost, $result['margins']['structureSell']);
        $this->assertMargin(160000.0, 11520.0, $totalCost, $result['margins']['structureBuy']);
        $this->assertMargin(172000.0, 0.0, $totalCost, $result['margins']['contractSell']);
    }

    public function testContractWithoutPriceHasNoMargin(): void
    {
        $this->jitaSellPriceOfProduct = 4500.0;

        $this->contractSellPriceOfProduct = null;
        $withoutContract = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);
        $this->contractSellPriceOfProduct = 0.0;
        $withZeroPricedContract = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        $this->assertNull($withoutContract['margins']['contractSell']);
        $this->assertNull($withZeroPricedContract['margins']['contractSell']);
        $this->assertNotNull($withZeroPricedContract['margins']['jitaSell']);
    }

    public function testMarketVenueWithZeroPriceHasNoMargin(): void
    {
        $this->jitaSellPriceOfProduct = 0.0;
        $this->structureSellPriceOfProduct = 0.0;
        $this->structureBuyPriceOfProduct = 0.0;
        $this->contractSellPriceOfProduct = 4300.0;

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        $this->assertNull($result['margins']['jitaSell']);
        $this->assertNull($result['margins']['structureSell']);
        $this->assertNull($result['margins']['structureBuy']);
        $this->assertNotNull($result['margins']['contractSell']);
    }

    #[Group('issue-108')]
    public function testZeroProductionCostLeavesVenueMarginsUnknownButKeepsRevenueFeesAndProfit(): void
    {
        $this->leavesWithoutJitaPrice = array_keys(self::NITROGEN_FUEL_BLOCK_ONE_RUN_LEAVES);
        $this->jitaSellPriceOfProduct = 4500.0;
        $this->contractSellPriceOfProduct = 4300.0;

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: null);

        $this->assertSame(0.0, $result['totalCost']);
        $this->assertSame(0.0, $result['costPerUnit']);
        $this->assertUnknownMargin(180000.0, 12960.0, 167040.0, $result['margins']['jitaSell']);
        $this->assertUnknownMargin(172000.0, 0.0, 172000.0, $result['margins']['contractSell']);
    }

    public function testDailyVolumeIsTheJitaAverageDailyVolumeOfTheProduct(): void
    {
        $this->dailyVolumes = [self::NITROGEN_FUEL_BLOCK => 1234.5, self::ROBOTICS => 99.0];

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        $this->assertSame(1234.5, $result['dailyVolume']);
    }

    #[Group('issue-108')]
    public function testProductWithoutDailyVolumeHasUnknownDailyVolume(): void
    {
        $this->dailyVolumes = [self::ROBOTICS => 99.0];

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        $this->assertNull($result['dailyVolume']);
    }

    public function testStructureNameComesFromCachedStructure(): void
    {
        $this->cachedSellStructureName = 'Perimeter - Tranquility Trading Tower';

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        $this->assertSame('Perimeter - Tranquility Trading Tower', $result['sellPrices']['structureName']);
        $this->assertSame(self::SELL_STRUCTURE_ID, $result['sellPrices']['structureId']);
    }

    // ===========================================
    // Hail L: T2, 5000 units per run, ME 2, Raitaru + ammunition rig II nullsec
    // 1 run is neutral to B1: ceil(1 / 5000) = 1 run.
    // ===========================================

    public function testHailLCurrentlyBuildsOneRootRunWithStructureAndRigBonusesForTenRuns(): void
    {
        $result = $this->analyzeHailL(runs: 10);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2 (B1)
        // ceil(10 / 5000) = 1 root run; golden: Fernite Carbide 27 640, Fullerides 11 056, Morphite 139.
        $this->assertTrue($result['isT2']);
        $this->assertSame(50000, $result['outputQuantity']);
        $this->assertSame(self::HAIL_L_ONE_RUN_LEAVES, $this->leafQuantities($result));
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_MATERIAL_COST, $result['materialCost'], 1e-6);
        $this->assertSame(
            [[self::HAIL_L, 'manufacturing', 1], [self::RAM_AMMUNITION_TECH, 'manufacturing', 1]],
            array_map(static fn (array $step): array => [$step['productTypeId'], $step['activityType'], $step['runs']], $result['jobInstallSteps']),
        );
        // EIV(ME 0) x 0.05 x 1.10: Hail L 70 216.261, R.A.M. 46.838
        $this->assertEqualsWithDelta(3861.894355, $result['jobInstallSteps'][0]['installCost'], 1e-6);
        $this->assertEqualsWithDelta(2.57609, $result['jobInstallSteps'][1]['installCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_JOB_INSTALL_COST, $result['jobInstallCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_TOTAL_COST, $result['totalCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_TOTAL_COST / 50000, $result['costPerUnit'], 1e-12);
    }

    public function testHailLInventionCurrentlyRoundsAttemptsUpForOneBpcAndUsesProductAdjustedPriceAsEiv(): void
    {
        $result = $this->analyzeHailL(runs: 1);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #7 (R5, R6) et R8
        // Copy and invention EIV = adjusted price of the blueprint's product x output per run, not its manufacturing materials.
        // Per attempt: datacores 2 x 0.11 + 2 x 0.12 + T1 copy 0.02 x 10 x 0.03 x 1.10 + invention 0.02 x 63 895 x 0.06 x 1.10 = 84.808.
        // Attempts: ceil(1 / 0.34) = 3 for a single BPC (R8: 1 / (0.34 x 10), no rounding).
        $this->assertEqualsWithDelta(self::HAIL_L_INVENTION_COST, $result['inventionCost'], 1e-6);
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #7
        // A copy of every tree blueprint with a copy activity (Hail L 42.1707 + R.A.M. 0.757416), on top of the T1 copy already in the invention cost.
        $this->assertEqualsWithDelta(self::HAIL_L_COPY_COST, $result['copyCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_TOTAL_COST, $result['totalCost'], 1e-6);
        $this->assertSame(self::HAIL_L_INVENTION_PROBABILITY, $result['invention']['baseProbability']);
        $this->assertNull($result['invention']['unknownReason']);
        $this->assertSame(['Stub Datacore A', 'Stub Datacore B'], $result['invention']['datacores']);
        $this->assertNull($result['invention']['selectedDecryptorTypeId']);
        $this->assertSame('No Decryptor', $result['invention']['selectedDecryptorName']);
        // Decryptor price = Jita price of its typeId; attempts = ceil(1 / (0.34 x probability multiplier)).
        $this->assertDecryptorOptions([
            [null, 'No Decryptor', 2, 4, 10, 0.34, 254.424, 651487.582561],
            [34201, 'Accelerant Decryptor', 4, 14, 11, 0.408, 1280.454, 652513.612561],
            [34202, 'Attainment Decryptor', 1, 8, 14, 0.612, 853.656, 652086.814561],
            [34203, 'Augmentation Decryptor', 0, 6, 19, 0.204, 2134.19, 653367.348561],
            [34204, 'Parity Decryptor', 3, 2, 13, 0.51, 853.696, 652086.854561],
            [34205, 'Process Decryptor', 5, 10, 10, 0.374, 1280.574, 652513.732561],
            [34206, 'Symmetry Decryptor', 3, 12, 12, 0.34, 1280.604, 652513.762561],
            [34207, 'Optimized Attainment Decryptor', 3, 2, 12, 0.646, 853.756, 652086.914561],
            [34208, 'Optimized Augmentation Decryptor', 4, 4, 17, 0.306, 1707.552, 652940.710561],
        ], $result['invention']['options']);
    }

    public function testSelectedDecryptorMeCurrentlyLeavesManufacturingMaterialsAtRequestedMe(): void
    {
        $withoutDecryptor = $this->analyzeHailL(runs: 1);
        $withProcessDecryptor = $this->analyzeHailL(runs: 1, decryptorTypeId: self::PROCESS_DECRYPTOR);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #18 (R8)
        // Process Decryptor: BPC ME 2 + 3 = 5, yet the materials stay those of the ME passed in (2).
        $processOption = $this->decryptorOption($withProcessDecryptor, self::PROCESS_DECRYPTOR);
        $this->assertSame(5, $processOption['me']);
        $this->assertSame($withoutDecryptor['materials'], $withProcessDecryptor['materials']);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_MATERIAL_COST, $withProcessDecryptor['materialCost'], 1e-6);
        $this->assertSame(self::PROCESS_DECRYPTOR, $withProcessDecryptor['invention']['selectedDecryptorTypeId']);
        $this->assertSame('Process Decryptor', $withProcessDecryptor['invention']['selectedDecryptorName']);
        // ceil(1 / (0.34 x 1.1)) = 3 attempts x (84.808 + 342.05)
        $this->assertEqualsWithDelta(1280.574, $withProcessDecryptor['inventionCost'], 1e-6);
        $this->assertEqualsWithDelta(1280.574, $processOption['inventionCost'], 1e-6);
        $this->assertEqualsWithDelta($processOption['totalProductionCost'], $withProcessDecryptor['totalCost'], 1e-6);
    }

    public function testDecryptorOptionCarriesTotalCostAndBestVenueMargin(): void
    {
        $this->jitaSellPriceOfProduct = 140.0;
        $this->structureSellPriceOfProduct = 135.0;
        $this->structureBuyPriceOfProduct = 120.0;
        $this->contractSellPriceOfProduct = 138.0;

        $result = $this->analyzeHailL(runs: 1);

        // 5 000 units: contract 690 000 with no fee beats Jita 700 000 - 7.2 % = 649 600.
        $noDecryptorOption = $result['invention']['options'][0];
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_TOTAL_COST, $noDecryptorOption['totalProductionCost'], 1e-6);
        $this->assertEqualsWithDelta($result['totalCost'], $noDecryptorOption['totalProductionCost'], 1e-6);
        $this->assertEqualsWithDelta($result['margins']['contractSell']['margin'], $noDecryptorOption['bestMargin'], 1e-9);
        $this->assertEqualsWithDelta((690000.0 - self::HAIL_L_ONE_RUN_TOTAL_COST) / self::HAIL_L_ONE_RUN_TOTAL_COST * 100.0, $noDecryptorOption['bestMargin'], 1e-9);

        $processOption = $this->decryptorOption($result, self::PROCESS_DECRYPTOR);
        $this->assertEqualsWithDelta(652513.732561, $processOption['totalProductionCost'], 1e-6);
        $this->assertEqualsWithDelta((690000.0 - 652513.732561) / 652513.732561 * 100.0, $processOption['bestMargin'], 1e-9);
    }

    public function testDecryptorOptionBestMarginIsTheBestMarketVenueWhenContractsSellLower(): void
    {
        $this->jitaSellPriceOfProduct = 142.0;
        $this->structureSellPriceOfProduct = 135.0;
        $this->structureBuyPriceOfProduct = 120.0;
        $this->contractSellPriceOfProduct = 125.0;

        $result = $this->analyzeHailL(runs: 1);

        // Jita: 710 000 - 7.2 % = 658 880, ahead of the structure sell order (626 400), the buy order (556 800) and the contract (625 000).
        $this->assertEqualsWithDelta($result['margins']['jitaSell']['margin'], $result['invention']['options'][0]['bestMargin'], 1e-9);
        $this->assertEqualsWithDelta((658880.0 - 652513.732561) / 652513.732561 * 100.0, $this->decryptorOption($result, self::PROCESS_DECRYPTOR)['bestMargin'], 1e-9);
    }

    #[Group('issue-108')]
    public function testDecryptorOptionWithoutAnySellPriceHasUnknownBestMargin(): void
    {
        $result = $this->analyzeHailL(runs: 1);

        $this->assertSame(array_fill(0, 9, null), array_column($result['invention']['options'], 'bestMargin'));
    }

    #[Group('issue-108')]
    public function testDecryptorOptionBestMarginKeepsItsOnlyVenueMarginBelowMinus999Percent(): void
    {
        $this->jitaSellPriceOfProduct = 1820.0;
        $this->brokerFeeRate = 1.0;
        $this->salesTaxRate = 1.0;

        $result = $this->analyzeHailL(runs: 1);

        $this->assertEqualsWithDelta(-1496.8032919718694, $result['invention']['options'][0]['bestMargin'], 1e-9);
        $this->assertEqualsWithDelta(-1494.6066643354957, $this->decryptorOption($result, self::PROCESS_DECRYPTOR)['bestMargin'], 1e-9);
        $this->assertMargin(9100000.0, 18200000.0, self::HAIL_L_ONE_RUN_TOTAL_COST, $result['margins']['jitaSell']);
    }

    public function testUnknownInventionProbabilityLeavesTotalCostAndMarginsUnknown(): void
    {
        $this->hailLInventionProbability = null;
        $this->jitaSellPriceOfProduct = 140.0;
        $this->contractSellPriceOfProduct = 138.0;

        $result = $this->analyzeHailL(runs: 1);

        $this->assertNull($result['inventionCost']);
        $this->assertNull($result['totalCost']);
        $this->assertNull($result['costPerUnit']);
        $this->assertSame(['jitaSell' => null, 'structureSell' => null, 'structureBuy' => null, 'contractSell' => null], $result['margins']);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_MATERIAL_COST, $result['materialCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_COPY_COST, $result['copyCost'], 1e-6);
        $this->assertNull($result['invention']['baseProbability']);
        $this->assertSame('missing_invention_probability', $result['invention']['unknownReason']);
        $this->assertCount(9, $result['invention']['options']);
        foreach ($result['invention']['options'] as $option) {
            $this->assertNull($option['inventionCost']);
            $this->assertNull($option['totalProductionCost']);
            $this->assertNull($option['bestMargin']);
        }
    }

    public function testT2WithoutSolarSystemCurrentlyCountsNoInventionNorCopyNorInstallCost(): void
    {
        $result = $this->analyzeHailL(runs: 1, solarSystemId: null);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #18 (R8, R10)
        // The provider always sends a system (Jita fallback): only a direct service caller reaches this path.
        $this->assertTrue($result['isT2']);
        $this->assertSame(0.0, $result['inventionCost']);
        $this->assertSame(0.0, $result['copyCost']);
        $this->assertSame(0.0, $result['jobInstallCost']);
        $this->assertSame([], $result['jobInstallSteps']);
        $this->assertNull($result['invention']);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_MATERIAL_COST, $result['totalCost'], 1e-6);
    }

    // ===========================================
    // Fernite Carbide: reaction, 10000 units per run, 10 runs, Tatara + reactor rig II nullsec
    // ===========================================

    public function testFerniteCarbideCurrentlyRunsOneReactionAndChargesAFuelBlockJobPerConsumer(): void
    {
        $result = $this->analyze(
            self::FERNITE_CARBIDE,
            runs: 10,
            me: 0,
            solarSystemId: self::MANUFACTURING_SYSTEM_ID,
            user: $this->userWithTaxedStructureInManufacturingSystem(),
        );

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2 (B1) et #16 (R3)
        // ceil(10 / 10000) = 1 root run (golden: 10 runs, 974 Fernite Alloy); Hydrogen Fuel Block built once per consumer, never aggregated.
        $this->assertFalse($result['isT2']);
        $this->assertSame(100000, $result['outputQuantity']);
        $this->assertSame(
            [
                [self::FERNITE_CARBIDE, 'reaction', 1],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 1],
                [self::FERNITE_ALLOY, 'reaction', 1],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 1],
                [self::CERAMIC_POWDER, 'reaction', 1],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 1],
            ],
            array_map(static fn (array $step): array => [$step['productTypeId'], $step['activityType'], $step['runs']], $result['jobInstallSteps']),
        );
        $this->assertSame([
            44 => 12,
            3683 => 60,
            3689 => 12,
            9832 => 27,
            self::ROBOTICS => 3,
            16272 => 456,
            16273 => 936,
            16275 => 54,
            16635 => 98,
            16636 => 98,
            16639 => 98,
            16642 => 98,
            17889 => 1203,
        ], $this->leafQuantities($result));
        $this->assertEqualsWithDelta(521337.57, $result['materialCost'], 1e-6);
        // 3 reactions x EIV x 0.04 x 1.10 + 3 fuel block jobs x EIV x 0.05 x 1.10
        $this->assertEqualsWithDelta(3252.44117, $result['jobInstallCost'], 1e-6);
        $this->assertSame(0.0, $result['inventionCost']);
        $this->assertSame(0.0, $result['copyCost']);
        $this->assertEqualsWithDelta(521337.57 + 3252.44117, $result['totalCost'], 1e-6);
    }

    // ===========================================
    // Nomad: 1 run, ME 10, no structure (Ravworks golden)
    // ===========================================

    public function testNomadCurrentlyRoundsEveryTreeNodeSeparatelyInsteadOfAggregatingDemand(): void
    {
        $result = $this->analyze(self::NOMAD, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #16 et #69 (R3)
        // Golden (Ravworks): 65 jobs, 24 895 737 leaf units, Nitrogen Isotopes 42 930, Robotics 579.
        // The Nomad invention is not in the fixture: the product is not seen as T2 here.
        $leafQuantities = $this->leafQuantities($result);
        $this->assertFalse($result['isT2']);
        $this->assertSame(1, $result['outputQuantity']);
        $this->assertCount(63, $leafQuantities);
        $this->assertSame(24987319, array_sum($leafQuantities));
        $this->assertSame(46170, $leafQuantities[self::NITROGEN_ISOTOPES]);
        $this->assertSame(640, $leafQuantities[self::ROBOTICS]);
        $this->assertSame(4008301, $leafQuantities[self::TRITANIUM]);
        $this->assertEqualsWithDelta(211767371.14, $result['materialCost'], 1e-3);
        $this->assertCount(286, $result['jobInstallSteps']);
        $this->assertEqualsWithDelta(14013719.46501, $result['jobInstallCost'], 1e-3);
        $this->assertSame(0.0, $result['copyCost']);
        $this->assertEqualsWithDelta(225781090.60501, $result['totalCost'], 1e-3);
    }

    // ===========================================
    // Harness
    // ===========================================

    /**
     * Hail L, user with a taxed structure in the manufacturing system, Fernite Carbide and Fullerides bought.
     *
     * @return array<string, mixed>
     */
    private function analyzeHailL(int $runs, ?int $decryptorTypeId = null, ?int $solarSystemId = self::MANUFACTURING_SYSTEM_ID): array
    {
        return $this->analyze(
            self::HAIL_L,
            runs: $runs,
            me: 2,
            solarSystemId: $solarSystemId,
            decryptorTypeId: $decryptorTypeId,
            user: $this->userWithTaxedStructureInManufacturingSystem(),
            blacklistedTypeIds: [self::FERNITE_CARBIDE, self::FULLERIDES],
        );
    }

    /**
     * @param list<int> $blacklistedTypeIds
     * @return array<string, mixed>
     */
    private function analyze(
        int $typeId,
        int $runs,
        int $me,
        ?int $solarSystemId,
        ?int $decryptorTypeId = null,
        ?User $user = null,
        array $blacklistedTypeIds = [],
    ): array {
        $productRepository = $this->sdeProductRepository();
        $materialRepository = $this->sdeMaterialRepository();
        $invTypeRepository = $this->sdeInvTypeRepository();
        $costIndexService = new EsiCostIndexService($this->createStub(EsiClient::class), $this->costIndexCache(), new NullLogger());
        $jitaMarketService = $this->jitaMarketService($typeId);
        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('resolve')->willReturnCallback(fn (int $id): string => $this->typeNames[(string) $id]);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(1);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $activityRepository = $this->createStub(IndustryActivityRepository::class);
        $activityRepository->method('findOneBy')->willReturn(null);

        $structureMarketService = $this->createStub(StructureMarketService::class);
        $structureMarketService->method('getLowestSellPrice')->willReturn($this->structureSellPriceOfProduct);
        $structureMarketService->method('getHighestBuyPrice')->willReturn($this->structureBuyPriceOfProduct);
        $publicContractPriceService = $this->createStub(PublicContractPriceService::class);
        $publicContractPriceService->method('getLowestUnitPrice')->willReturn($this->contractSellPriceOfProduct);
        $publicContractPriceService->method('getContractCount')->willReturn($this->contractCountOfProduct);
        $cachedStructureRepository = $this->createStub(CachedStructureRepository::class);
        $cachedStructureRepository->method('findByStructureId')->willReturnCallback(
            fn (int $structureId): ?CachedStructure => self::SELL_STRUCTURE_ID === $structureId && null !== $this->cachedSellStructureName
                ? (new CachedStructure())->setStructureId($structureId)->setName($this->cachedSellStructureName)
                : null,
        );
        $blacklistService = $this->createStub(IndustryBlacklistService::class);
        $blacklistService->method('resolveBlacklistedTypeIds')->willReturn($blacklistedTypeIds);
        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('findByUser')->willReturn($this->structureConfigs);

        $service = new ProfitMarginService(
            new IndustryTreeService($productRepository, $materialRepository, $invTypeRepository, $entityManager, $this->goldenStructureBonuses()),
            $jitaMarketService,
            $structureMarketService,
            $costIndexService,
            new InventionService($productRepository, $materialRepository, $activityRepository, $invTypeRepository, $jitaMarketService, $costIndexService, $typeNameResolver),
            $publicContractPriceService,
            $typeNameResolver,
            $cachedStructureRepository,
            $blacklistService,
            $structureConfigRepository,
            $materialRepository,
        );

        return $service->analyze(
            $typeId,
            $runs,
            $me,
            0,
            self::SELL_STRUCTURE_ID,
            $solarSystemId,
            $decryptorTypeId,
            $this->brokerFeeRate,
            $this->salesTaxRate,
            $user,
        );
    }

    /**
     * Raitaru (1 %) with an ammunition rig II in nullsec (2.4 % x 2.1) for Hail L, Tatara with a reactor rig II in nullsec (2.4 % x 1.1) for reactions, Raitaru without rig otherwise.
     */
    private function goldenStructureBonuses(): IndustryBonusService
    {
        $raitaruWithoutRig = ['base' => 1.0, 'rig' => 0.0];
        $bonusByProduct = [
            self::HAIL_L => ['base' => 1.0, 'rig' => 5.04],
            self::RAM_AMMUNITION_TECH => $raitaruWithoutRig,
            self::HYDROGEN_FUEL_BLOCK => $raitaruWithoutRig,
            self::NITROGEN_FUEL_BLOCK => ['base' => 0.0, 'rig' => 0.0],
            self::FERNITE_CARBIDE => ['base' => 0.0, 'rig' => 2.64],
            self::FERNITE_ALLOY => ['base' => 0.0, 'rig' => 2.64],
            self::CERAMIC_POWDER => ['base' => 0.0, 'rig' => 2.64],
        ];

        $bonusService = $this->createStub(IndustryBonusService::class);
        $bonusService->method('getCategoryForProduct')->willReturn(null);
        $bonusService->method('findBestStructureForProduct')->willReturnCallback(
            static function (User $user, int $typeId) use ($bonusByProduct): array {
                $bonus = $bonusByProduct[$typeId] ?? ['base' => 0.0, 'rig' => 0.0];

                return [
                    'structure' => null,
                    'bonus' => $bonus + ['total' => $bonus['base'] + $bonus['rig']],
                    'category' => null,
                ];
            },
        );

        return $bonusService;
    }

    private function userWithTaxedStructureInManufacturingSystem(): User
    {
        return new User();
    }

    private function structure(int $solarSystemId, float $facilityTaxPercent, bool $isDefault): IndustryStructureConfig
    {
        return (new IndustryStructureConfig())
            ->setSolarSystemId($solarSystemId)
            ->setFacilityTaxRate($facilityTaxPercent)
            ->setIsDefault($isDefault);
    }

    private static function jitaPriceOf(int $typeId): float
    {
        return ($typeId % self::PRICE_TYPE_ID_MODULO) / self::JITA_PRICE_DIVISOR;
    }

    private static function adjustedPriceOf(int $typeId): float
    {
        return ($typeId % self::PRICE_TYPE_ID_MODULO) / self::ADJUSTED_PRICE_DIVISOR;
    }

    private function costIndexCache(): ArrayAdapter
    {
        $cache = new ArrayAdapter();
        $costIndices = [
            'manufacturing' => $this->withManufacturingCostIndex ? self::MANUFACTURING_COST_INDEX : null,
            'reaction' => self::REACTION_COST_INDEX,
            'copying' => self::COPYING_COST_INDEX,
            'invention' => self::INVENTION_COST_INDEX,
        ];
        foreach ($costIndices as $activity => $costIndex) {
            if (null !== $costIndex) {
                $cache->save($cache->getItem('esi_cost_index_'.self::MANUFACTURING_SYSTEM_ID.'_'.$activity)->set($costIndex));
            }
        }
        foreach (array_keys($this->typeNames) as $typeId) {
            if (!\in_array((int) $typeId, $this->typesWithoutAdjustedPrice, true)) {
                $cache->save($cache->getItem('esi_adjusted_price_'.$typeId)->set(self::adjustedPriceOf((int) $typeId)));
            }
        }

        return $cache;
    }

    private function jitaMarketService(int $productTypeId): JitaMarketService
    {
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getWeightedSellPricesWithFallback')->willReturnCallback(
            function (array $typeQuantities): array {
                $prices = [];
                foreach (array_keys($typeQuantities) as $typeId) {
                    $prices[$typeId] = \in_array($typeId, $this->leavesWithoutJitaPrice, true)
                        ? null
                        : ['weightedPrice' => self::jitaPriceOf($typeId), 'coverage' => 1.0, 'ordersUsed' => 1];
                }

                return $prices;
            },
        );
        $jitaMarketService->method('getWeightedSellPrice')->willReturnCallback(
            fn (int $typeId): ?array => $typeId === $productTypeId && null !== $this->jitaSellPriceOfProduct
                ? ['weightedPrice' => $this->jitaSellPriceOfProduct, 'coverage' => 1.0, 'ordersUsed' => 1]
                : null,
        );
        $jitaMarketService->method('getPricesWithFallback')->willReturnCallback(
            static fn (array $typeIds): array => array_combine($typeIds, array_map(self::jitaPriceOf(...), $typeIds)),
        );
        $jitaMarketService->method('getPrice')->willReturnCallback(
            static fn (int $typeId): float => self::jitaPriceOf($typeId),
        );
        $jitaMarketService->method('getAverageDailyVolumes')->willReturnCallback(
            fn (array $typeIds): array => array_intersect_key($this->dailyVolumes, array_flip($typeIds)),
        );

        return $jitaMarketService;
    }

    private function sdeProductRepository(): IndustryActivityProductRepository
    {
        $repository = $this->createStub(IndustryActivityProductRepository::class);
        $repository->method('findBlueprintForProduct')->willReturnCallback(
            function (int $productTypeId, int $activityId): ?IndustryActivityProduct {
                if (IndustryActivityType::Invention->value === $activityId) {
                    return $productTypeId === $this->blueprintOf(self::HAIL_L)
                        ? $this->product(self::HAIL_L_T1_BLUEPRINT, $activityId, $productTypeId, self::HAIL_L_INVENTED_RUNS, $this->hailLInventionProbability)
                        : null;
                }
                $recipe = $this->recipes[(string) $productTypeId] ?? null;
                if (null === $recipe || $this->activityOf($recipe) !== $activityId) {
                    return null;
                }

                return $this->product($this->blueprintOf($productTypeId), $activityId, $productTypeId, $recipe['outputPerRun']);
            },
        );
        $repository->method('findBy')->willReturnCallback(
            function (array $criteria): array {
                $blueprintTypeId = (int) $criteria['typeId'];
                if (self::HAIL_L_T1_BLUEPRINT === $blueprintTypeId) {
                    return [$this->product($blueprintTypeId, IndustryActivityType::Manufacturing->value, self::HAIL_L_T1_PRODUCT, self::HAIL_L_T1_OUTPUT_PER_RUN)];
                }
                $productTypeId = $blueprintTypeId - self::SYNTHETIC_BLUEPRINT_OFFSET;
                $recipe = $this->recipes[(string) $productTypeId] ?? null;
                if (null === $recipe || $this->activityOf($recipe) !== $criteria['activityId']) {
                    return [];
                }

                return [$this->product($blueprintTypeId, $criteria['activityId'], $productTypeId, $recipe['outputPerRun'])];
            },
        );

        return $repository;
    }

    private function sdeMaterialRepository(): IndustryActivityMaterialRepository
    {
        $repository = $this->createStub(IndustryActivityMaterialRepository::class);
        $repository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => array_map(
                fn (array $row): IndustryActivityMaterial => $this->material((int) $criteria['typeId'], (int) $criteria['activityId'], $row['materialTypeId'], $row['quantity']),
                $this->baseMaterialsOf((int) $criteria['typeId'], (int) $criteria['activityId']),
            ),
        );
        $repository->method('findMaterialsForBlueprints')->willReturnCallback(
            fn (array $blueprintTypeIds, array $activityIds): array => [
                $blueprintTypeIds[0] => $this->baseMaterialsOf($blueprintTypeIds[0], $activityIds[0]),
            ],
        );
        $repository->method('findByBlueprintAndActivity')->willReturnCallback(
            fn (int $blueprintTypeId, int $activityId): array => self::HAIL_L_T1_BLUEPRINT === $blueprintTypeId && IndustryActivityType::Invention->value === $activityId
                ? [
                    $this->material($blueprintTypeId, $activityId, self::STUB_DATACORE_A, self::DATACORE_QUANTITY_PER_ATTEMPT),
                    $this->material($blueprintTypeId, $activityId, self::STUB_DATACORE_B, self::DATACORE_QUANTITY_PER_ATTEMPT),
                ]
                : [],
        );

        return $repository;
    }

    private function sdeInvTypeRepository(): InvTypeRepository
    {
        $repository = $this->createStub(InvTypeRepository::class);
        $repository->method('find')->willReturnCallback(fn (int $typeId): InvType => $this->invType($typeId));
        $repository->method('findByTypeIds')->willReturnCallback(
            function (array $typeIds): array {
                $types = [];
                foreach ($typeIds as $typeId) {
                    $types[$typeId] = $this->invType($typeId);
                }

                return $types;
            },
        );

        return $repository;
    }

    /**
     * @return list<array{materialTypeId: int, quantity: int}>
     */
    private function baseMaterialsOf(int $blueprintTypeId, int $activityId): array
    {
        $recipe = $this->recipes[(string) ($blueprintTypeId - self::SYNTHETIC_BLUEPRINT_OFFSET)] ?? null;
        if (null === $recipe || $this->activityOf($recipe) !== $activityId) {
            return [];
        }
        $materials = [];
        foreach ($recipe['materials'] as $materialTypeId => $quantity) {
            $materials[] = ['materialTypeId' => (int) $materialTypeId, 'quantity' => $quantity];
        }

        return $materials;
    }

    /**
     * @param array{activity: string} $recipe
     */
    private function activityOf(array $recipe): int
    {
        return 'reaction' === $recipe['activity'] ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }

    private function blueprintOf(int $productTypeId): int
    {
        return $productTypeId + self::SYNTHETIC_BLUEPRINT_OFFSET;
    }

    private function product(int $blueprintTypeId, int $activityId, int $productTypeId, int $quantity, ?float $probability = null): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activityId)
            ->setProductTypeId($productTypeId)
            ->setQuantity($quantity)
            ->setProbability($probability);
    }

    private function material(int $blueprintTypeId, int $activityId, int $materialTypeId, int $quantity): IndustryActivityMaterial
    {
        return (new IndustryActivityMaterial())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activityId)
            ->setMaterialTypeId($materialTypeId)
            ->setQuantity($quantity);
    }

    private function invType(int $typeId): InvType
    {
        $type = $this->createStub(InvType::class);
        $type->method('getTypeName')->willReturn($this->typeNames[(string) $typeId] ?? "Type #{$typeId}");

        return $type;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<int, int> leaf typeId => quantity, sorted by typeId
     */
    private function leafQuantities(array $result): array
    {
        $quantities = array_column($result['materials'], 'quantity', 'typeId');
        ksort($quantities);

        return $quantities;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function materialLine(array $result, int $typeId): array
    {
        foreach ($result['materials'] as $line) {
            if ($line['typeId'] === $typeId) {
                return $line;
            }
        }

        $this->fail("No material line for type {$typeId}");
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function decryptorOption(array $result, int $decryptorTypeId): array
    {
        foreach ($result['invention']['options'] as $option) {
            if ($option['decryptorTypeId'] === $decryptorTypeId) {
                return $option;
            }
        }

        $this->fail("No decryptor option {$decryptorTypeId}");
    }

    /**
     * Profit = revenue - fees - production cost; margin = profit / production cost.
     *
     * @param array{revenue: float, fees: float, profit: float, margin: float|null}|null $actual
     */
    private function assertMargin(float $revenue, float $fees, float $totalCost, ?array $actual): void
    {
        $profit = $revenue - $fees - $totalCost;
        $this->assertRevenueFeesAndProfit($revenue, $fees, $profit, $actual);
        $this->assertEqualsWithDelta($profit / $totalCost * 100.0, $actual['margin'], 1e-9);
    }

    private function assertUnknownMargin(float $revenue, float $fees, float $profit, ?array $actual): void
    {
        $this->assertRevenueFeesAndProfit($revenue, $fees, $profit, $actual);
        $this->assertNull($actual['margin']);
    }

    private function assertRevenueFeesAndProfit(float $revenue, float $fees, float $profit, ?array $actual): void
    {
        $this->assertNotNull($actual);
        $this->assertEqualsWithDelta($revenue, $actual['revenue'], 1e-6);
        $this->assertEqualsWithDelta($fees, $actual['fees'], 1e-6);
        $this->assertEqualsWithDelta($profit, $actual['profit'], 1e-6);
    }

    /**
     * @param list<array{?int, string, int, int, int, float, float, float}> $expected decryptorTypeId, name, ME, TE, runs, probability, invention cost, total production cost
     * @param list<array<string, mixed>> $options
     */
    private function assertDecryptorOptions(array $expected, array $options): void
    {
        $this->assertCount(\count($expected), $options);
        foreach ($expected as $index => [$decryptorTypeId, $decryptorName, $me, $te, $runs, $probability, $inventionCost, $totalProductionCost]) {
            $option = $options[$index];
            $this->assertSame(
                ['decryptorTypeId' => $decryptorTypeId, 'decryptorName' => $decryptorName, 'me' => $me, 'te' => $te, 'runs' => $runs],
                array_intersect_key($option, array_flip(['decryptorTypeId', 'decryptorName', 'me', 'te', 'runs'])),
            );
            $this->assertEqualsWithDelta($probability, $option['probability'], 1e-12);
            $this->assertEqualsWithDelta($inventionCost, $option['inventionCost'], 1e-6);
            $this->assertEqualsWithDelta($totalProductionCost, $option['totalProductionCost'], 1e-6);
        }
    }
}
