<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedStructure;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\IndustryBlueprint;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Industry\Application\Decryptors;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\RigCategories;
use App\Industry\Application\StructureType;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Domain\Decryptor;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\SecurityClass;
use App\Industry\Infrastructure\Esi\EsiAdjustedPrices;
use App\Industry\Infrastructure\Esi\EsiSystemCostIndices;
use App\Industry\Infrastructure\Market\JitaMarketPrices;
use App\Industry\Infrastructure\Sde\SdeBlueprintCatalog;
use App\Industry\Infrastructure\Sde\SdeInventionCatalog;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryBlueprintRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\EsiClient;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\ProfitMarginService;
use App\Service\Industry\PublicContractPriceService;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;

/**
 * Characterization of /profit-margin on the goldens, before ProfitMarginService moves to the industry engine (spec §7, rank 1).
 *
 * The SDE comes from tests/Fixtures/Golden/sde-blueprint-recipes.json.
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
    private const float OTHER_SYSTEM_MANUFACTURING_COST_INDEX = 0.08;
    private const float REACTION_COST_INDEX = 0.04;
    private const float COPYING_COST_INDEX = 0.03;
    private const float INVENTION_COST_INDEX = 0.06;
    private const string USER_ID = '01928c3e-7a00-7000-8000-000000000001';
    private const float RAITARU_MATERIAL_ROLE_BONUS = 0.99;
    private const float RAITARU_COST_ROLE_BONUS = 0.97;
    private const float STRUCTURE_FACILITY_TAX_RATE = 0.20;
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
    private const int MORPHITE = 11399;

    private const int HAIL_L_T1_BLUEPRINT = 2_000_000_001;
    private const int HAIL_L_T1_PRODUCT = 2_000_000_002;
    private const int HAIL_L_T1_OUTPUT_PER_RUN = 5000;
    private const int STUB_DATACORE_A = 2_000_000_011;
    private const int STUB_DATACORE_B = 2_000_000_012;
    private const int DATACORE_QUANTITY_PER_ATTEMPT = 2;
    private const float HAIL_L_INVENTION_PROBABILITY = 0.34;
    private const int HAIL_L_INVENTED_RUNS = 10;
    private const int ACCELERANT_DECRYPTOR = 34201;
    private const int PARITY_DECRYPTOR = 34204;
    private const int PROCESS_DECRYPTOR = 34205;
    /** @var array<int, array{string, float, int, int, int}> typeId => name, probability multiplier, runs, ME, TE modifiers */
    private const array DECRYPTORS = [
        34201 => ['Accelerant Decryptor', 1.2, 1, 2, 10],
        34202 => ['Attainment Decryptor', 1.8, 4, -1, 4],
        34203 => ['Augmentation Decryptor', 0.6, 9, -2, 2],
        34204 => ['Parity Decryptor', 1.5, 3, 1, -2],
        self::PROCESS_DECRYPTOR => ['Process Decryptor', 1.1, 0, 3, 6],
        34206 => ['Symmetry Decryptor', 1.0, 2, 1, 8],
        34207 => ['Optimized Attainment Decryptor', 1.9, 2, 1, -2],
        34208 => ['Optimized Augmentation Decryptor', 0.9, 7, 2, 0],
    ];
    /** Manufacturing materials of the synthetic T1 product, base quantity per run: the EIV of the T1 copy (R5). */
    private const array HAIL_L_T1_MATERIALS = [self::TRITANIUM => 1000, 35 => 500];
    /** The marginal cost mode ignores the BPC run cap and the job duration (R12): any SDE value fits. */
    private const int SYNTHETIC_MAX_PRODUCTION_LIMIT = 100;
    private const int SYNTHETIC_ACTIVITY_TIME = 600;

    private const float NITROGEN_FUEL_BLOCK_ONE_RUN_MATERIAL_COST = 153280.656;
    /** EIV(ME 0) 17 031.184 x (cost index 0.05 + NPC tax 0.25 % + SCC 4 %), no structure (R6). */
    private const float NITROGEN_FUEL_BLOCK_ONE_RUN_INSTALL_COST = 1575.38452;
    private const float NITROGEN_FUEL_BLOCK_ONE_RUN_TOTAL_COST = 154856.04052;
    /** 1 run x 1 Robotics x 0.9 (ME 10), marginal cost mode. */
    private const float ROBOTICS_PER_NITROGEN_FUEL_BLOCK_RUN = 0.9;
    private const float ROBOTICS_JITA_PRICE = 98.48;

    /** 25 runs at ME 10 without structure bonus, marginal cost mode: 25 x base x 0.9, leaf typeId => quantity. */
    private const array NITROGEN_FUEL_BLOCK_TWENTY_FIVE_RUNS_LEAVES = [
        44 => 90.0,
        3683 => 495.0,
        3689 => 90.0,
        9832 => 202.5,
        self::ROBOTICS => 22.5,
        16272 => 3825.0,
        16273 => 7875.0,
        16275 => 450.0,
        self::NITROGEN_ISOTOPES => 10125.0,
    ];

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

    /** Ten root runs at ME 2 without structure, marginal cost mode, Fernite Carbide and Fullerides bought, leaf typeId => quantity. */
    private const array HAIL_L_TEN_RUNS_LEAVES = [
        self::TRITANIUM => 49.0392,
        35 => 39.1608,
        36 => 19.5804,
        37 => 7.2324,
        38 => 3.1752,
        11399 => 147.0,
        self::FERNITE_CARBIDE => 29400.0,
        self::FULLERIDES => 11760.0,
    ];
    /** One run without structure: materials 688 011.024112 + install 6 495.046601 + expected invention. */
    private const float HAIL_L_ONE_RUN_MATERIAL_COST = 688011.024112;
    private const float HAIL_L_ONE_RUN_INVENTION_COST = 42.493532368;
    private const float HAIL_L_ONE_RUN_ENGINE_TOTAL_COST = 694548.564245115;
    /** One run with a Process Decryptor: materials at ME 5 666 949.462149 + install 6 495.045301 + expected invention. */
    private const float HAIL_L_ONE_RUN_PROCESS_MATERIAL_COST = 666949.462149;
    /** Hail L 70 216.261 x 0.0925 + R.A.M. 0.0095 run x 46.838 x 0.0925. */
    private const float HAIL_L_ONE_RUN_PROCESS_INSTALL_COST = 6495.0453013925;
    private const float HAIL_L_ONE_RUN_PROCESS_INVENTION_COST = 130.087703222;
    private const float HAIL_L_ONE_RUN_PROCESS_TOTAL_COST = 673574.595153614;
    /**
     * One run with a Parity Decryptor, BPC ME 2 + 1 = 3:
     * Morphite 15 x 0.97 x 113.99 + Fernite Carbide 3 000 x 0.97 x 166.73 + Fullerides 1 200 x 0.97 x 166.79
     * + R.A.M. 0.0097 run x 0.9 x (556 x 0.34 + 444 x 0.35 + 222 x 0.36 + 82 x 0.37 + 36 x 0.38).
     */
    private const float HAIL_L_ONE_RUN_PARITY_MATERIAL_COST = 680990.5034574;
    /** Hail L 70 216.261 x 0.0925 + R.A.M. 0.0097 run x 46.838 x 0.0925. */
    private const float HAIL_L_ONE_RUN_PARITY_INSTALL_COST = 6495.0461678955;
    /** Expected invention cost of one run per option: no decryptor, then decryptors by typeId (see testHailLListsTheNoDecryptorOption...). */
    private const array HAIL_L_ONE_RUN_INVENTION_COST_BY_OPTION = [
        42.493532368,
        108.397506696,
        56.780813498,
        125.518062448,
        73.38129865,
        130.087703222,
        119.249512267,
        62.764191183,
        93.532873904,
    ];
    private const float HAIL_L_ONE_RUN_ACCELERANT_TOTAL_COST = 680573.42604454;

    /** Fernite Carbide 10 runs: Hydrogen Fuel Block demand 100 units = 2.5 runs x EIV(ME 0) 17 031.634 x 0.0925. */
    private const float FERNITE_CARBIDE_HYDROGEN_FUEL_BLOCK_INSTALL_COST = 3938.5653625;
    private const float FERNITE_CARBIDE_TEN_RUNS_MATERIAL_COST = 715971.765;

    /** @var array<string, array{name: string, activity: string, outputPerRun: int, materials: array<string, int>}> */
    private array $recipes;
    /** @var array<string, string> */
    private array $typeNames;

    /** @var list<int> */
    private array $leavesWithoutJitaPrice = [];
    /** @var list<int> */
    private array $leavesWithZeroJitaPrice = [];
    /** @var list<int> */
    private array $typesWithoutAdjustedPrice = [];
    /** @var list<int> */
    private array $typesMissingFromSde = [];
    private bool $withManufacturingCostIndex = true;
    private bool $withReactionCostIndex = true;
    private ?float $hailLInventionProbability = self::HAIL_L_INVENTION_PROBABILITY;
    /** @var list<ProductionStructure> */
    private array $structures = [];
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
        ] + array_map(static fn (array $decryptor): string => $decryptor[0], self::DECRYPTORS);
    }

    // ===========================================
    // Nitrogen Fuel Block: 40 units per run, ME 10, no structure
    // 1 run is neutral to B1 (issue #2): 1 run read as 1 unit is still ceil(1 / 40) = 1 run.
    // ===========================================

    public function testNitrogenFuelBlockPricesTwentyFiveRunsOfMaterialsIssue2(): void
    {
        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 25, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        // Marginal cost mode (R12, D4b): 25 runs x base x 0.9, no rounding; Robotics 25 x 1 x 0.9 = 22.5.
        $this->assertFalse($result['isT2']);
        $this->assertNull($result['invention']);
        $this->assertSame(40, $result['outputPerRun']);
        $this->assertSame(1000, $result['outputQuantity']);
        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_TWENTY_FIVE_RUNS_LEAVES, $this->leafQuantities($result), 1e-9);
        $this->assertEqualsWithDelta(3832016.4, $result['materialCost'], 1e-6);
        // EIV(ME 0, 25 runs) 425 779.6 x (cost index 0.05 + NPC tax 0.25 % + SCC 4 %), no structure (R6)
        $this->assertJobInstallSteps([[self::NITROGEN_FUEL_BLOCK, 'manufacturing', 25.0, 39384.613]], $result['jobInstallSteps']);
        $this->assertEqualsWithDelta(39384.613, $result['jobInstallCost'], 1e-6);
        $this->assertEqualsWithDelta(3871401.013, $result['totalCost'], 1e-6);
        $this->assertEqualsWithDelta(3871.401013, $result['costPerUnit'], 1e-9);
        $this->assertSame([], $result['missingData']);
    }

    #[Group('issue-7')]
    public function testJobInstallCostAddsFacilityTaxAndSccToTheCostIndexTermIssue7(): void
    {
        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        // R6, NPC station: EIV(ME 0) 17 031.184 x 0.05 + 17 031.184 x 0.25 % + 17 031.184 x 4 % SCC.
        $this->assertEqualsWithDelta(1575.38452, $result['jobInstallCost'], 1e-6);
        $this->assertJobInstallSteps([[self::NITROGEN_FUEL_BLOCK, 'manufacturing', 1.0, 1575.38452, []]], $result['jobInstallSteps']);
        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_ONE_RUN_TOTAL_COST, $result['totalCost'], 1e-6);
        $this->assertSame([], $result['missingData']);
    }

    public function testStructureOutsideTheFavoriteSystemRunsTheJobWithItsTaxAndItsCostIndex(): void
    {
        $this->structures = [$this->raitaru(solarSystemId: self::OTHER_SYSTEM_ID, facilityTaxRate: self::STRUCTURE_FACILITY_TAX_RATE)];

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID, user: $this->user());

        $this->assertEqualsWithDelta(5409.104038, $result['jobInstallCost'], 1e-6);
        $this->assertJobInstallSteps([[self::NITROGEN_FUEL_BLOCK, 'manufacturing', 1.0, 5409.104038]], $result['jobInstallSteps']);
        $this->assertEqualsWithDelta(151747.84944, $result['materialCost'], 1e-6);
        $this->assertSame([], $result['missingData']);
    }

    #[Group('issue-7')]
    public function testMissingCostIndexLeavesInstallCostAndMarginsUnknownIssue7(): void
    {
        $this->withManufacturingCostIndex = false;
        $this->sellNitrogenFuelBlockOnEveryVenue();

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        // R6, R10: an absent cost index is an unknown install cost, never 0; the material cost stays known.
        $this->assertNull($result['jobInstallCost']);
        $this->assertJobInstallSteps([[self::NITROGEN_FUEL_BLOCK, 'manufacturing', 1.0, null, [['cost_index', null]]]], $result['jobInstallSteps']);
        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_ONE_RUN_MATERIAL_COST, $result['materialCost'], 1e-6);
        $this->assertNull($result['totalCost']);
        $this->assertNull($result['costPerUnit']);
        $this->assertSame(['jitaSell' => null, 'structureSell' => null, 'structureBuy' => null, 'contractSell' => null], $result['margins']);
        $this->assertSame(4500.0, $result['sellPrices']['jitaSell']);
        $this->assertMissingData([['cost_index', null]], $result);
    }

    #[Group('issue-7')]
    public function testMaterialWithoutAdjustedPriceLeavesInstallCostUnknownIssue7(): void
    {
        $this->typesWithoutAdjustedPrice = [self::ROBOTICS];
        $this->sellNitrogenFuelBlockOnEveryVenue();

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        // R5, R10: the EIV is unknown with Robotics listed, instead of 17 021.336 without it; its market price stays known.
        $this->assertNull($result['jobInstallCost']);
        $this->assertJobInstallSteps([[self::NITROGEN_FUEL_BLOCK, 'manufacturing', 1.0, null, [['adjusted_price', self::ROBOTICS]]]], $result['jobInstallSteps']);
        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_ONE_RUN_MATERIAL_COST, $result['materialCost'], 1e-6);
        $this->assertPricedMaterialLine(self::ROBOTICS, 'Robotics', self::ROBOTICS_PER_NITROGEN_FUEL_BLOCK_RUN, self::ROBOTICS_JITA_PRICE, $result);
        $this->assertNull($result['totalCost']);
        $this->assertNull($result['costPerUnit']);
        $this->assertSame(['jitaSell' => null, 'structureSell' => null, 'structureBuy' => null, 'contractSell' => null], $result['margins']);
        $this->assertMissingData([['adjusted_price', self::ROBOTICS]], $result);
    }

    #[Group('issue-8')]
    public function testFullyPricedMaterialsKeepTheirCostsWithoutMissingData(): void
    {
        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        // Robotics 0.9 x 98.48 = 88.632; total 153 280.656 + 1 575.38452, 40 units.
        $this->assertPricedMaterialLine(self::ROBOTICS, 'Robotics', self::ROBOTICS_PER_NITROGEN_FUEL_BLOCK_RUN, self::ROBOTICS_JITA_PRICE, $result);
        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_ONE_RUN_MATERIAL_COST, $result['materialCost'], 1e-6);
        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_ONE_RUN_TOTAL_COST, $result['totalCost'], 1e-6);
        $this->assertEqualsWithDelta(3871.401013, $result['costPerUnit'], 1e-9);
        $this->assertSame([], $result['missingData']);
    }

    #[Group('issue-8')]
    public function testMaterialWithoutJitaPriceLeavesMaterialCostUnknownIssue8(): void
    {
        $this->leavesWithoutJitaPrice = [self::ROBOTICS];
        $this->sellNitrogenFuelBlockOnEveryVenue();

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        // R10: the quantity is still computed, the price and every sum containing it are unknown.
        $robotics = $this->materialLine($result, self::ROBOTICS);
        $this->assertSame(['typeId' => self::ROBOTICS, 'typeName' => 'Robotics'], array_intersect_key($robotics, array_flip(['typeId', 'typeName'])));
        $this->assertEqualsWithDelta(self::ROBOTICS_PER_NITROGEN_FUEL_BLOCK_RUN, $robotics['quantity'], 1e-9);
        $this->assertNull($robotics['unitPrice']);
        $this->assertNull($robotics['totalPrice']);
        $this->assertPricedMaterialLine(self::NITROGEN_ISOTOPES, 'Nitrogen Isotopes', 405.0, 178.88, $result);
        $this->assertNull($result['materialCost']);
        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_ONE_RUN_INSTALL_COST, $result['jobInstallCost'], 1e-6);
        $this->assertNull($result['totalCost']);
        $this->assertNull($result['costPerUnit']);
        $this->assertSame(['jitaSell' => null, 'structureSell' => null, 'structureBuy' => null, 'contractSell' => null], $result['margins']);
        $this->assertSame(4500.0, $result['sellPrices']['jitaSell']);
        $this->assertMissingData([['market_price', self::ROBOTICS]], $result);
    }

    #[Group('issue-8')]
    public function testZeroWeightedPriceIsAKnownPrice(): void
    {
        $this->leavesWithZeroJitaPrice = [self::ROBOTICS];

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        // 153 280.656 - 0.9 x 98.48 = 153 192.024; + install 1 575.38452.
        $this->assertPricedMaterialLine(self::ROBOTICS, 'Robotics', self::ROBOTICS_PER_NITROGEN_FUEL_BLOCK_RUN, 0.0, $result);
        $this->assertEqualsWithDelta(153192.024, $result['materialCost'], 1e-6);
        $this->assertEqualsWithDelta(154767.40852, $result['totalCost'], 1e-6);
        $this->assertSame([], $result['missingData']);
    }

    public function testMarginsChargeBrokerFeeAndSalesTaxOnSellOrdersButOnlySalesTaxOnBuyOrders(): void
    {
        $this->jitaSellPriceOfProduct = 4500.0;
        $this->structureSellPriceOfProduct = 4400.0;
        $this->structureBuyPriceOfProduct = 4000.0;
        $this->contractSellPriceOfProduct = 4300.0;
        $this->contractCountOfProduct = 7;

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);
        $totalCost = $result['totalCost'];

        // 40 units; sell orders pay broker 3.6 % + sales tax 3.6 %, the buy order only the sales tax, contracts nothing.
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
        $this->assertMargin(160000.0, 5760.0, $totalCost, $result['margins']['structureBuy']);
        $this->assertMargin(172000.0, 0.0, $totalCost, $result['margins']['contractSell']);
    }

    public function testBuyOrderWithoutSalesTaxHasNoFeeWhileSellOrdersStillPayTheBrokerFee(): void
    {
        $this->brokerFeeRate = 0.05;
        $this->salesTaxRate = 0.0;
        $this->jitaSellPriceOfProduct = 4500.0;
        $this->structureSellPriceOfProduct = 4400.0;
        $this->structureBuyPriceOfProduct = 4000.0;

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        // 40 units: 180 000 x 5 %, 176 000 x 5 %, 160 000 x 0 %.
        $this->assertMargin(180000.0, 9000.0, self::NITROGEN_FUEL_BLOCK_ONE_RUN_TOTAL_COST, $result['margins']['jitaSell']);
        $this->assertMargin(176000.0, 8800.0, self::NITROGEN_FUEL_BLOCK_ONE_RUN_TOTAL_COST, $result['margins']['structureSell']);
        $this->assertMargin(160000.0, 0.0, self::NITROGEN_FUEL_BLOCK_ONE_RUN_TOTAL_COST, $result['margins']['structureBuy']);
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

    #[Group('issue-115')]
    public function testProductAbsentFromTheSdeHasAnUnknownTypeNameAndUnchangedCostsAndMargins(): void
    {
        $this->typesMissingFromSde = [self::NITROGEN_FUEL_BLOCK];
        $this->jitaSellPriceOfProduct = 4500.0;

        $result = $this->analyze(self::NITROGEN_FUEL_BLOCK, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        $this->assertSame(self::NITROGEN_FUEL_BLOCK, $result['typeId']);
        $this->assertNull($result['typeName']);
        $this->assertSame(40, $result['outputQuantity']);
        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_ONE_RUN_MATERIAL_COST, $result['materialCost'], 1e-6);
        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_ONE_RUN_INSTALL_COST, $result['jobInstallCost'], 1e-6);
        $this->assertEqualsWithDelta(self::NITROGEN_FUEL_BLOCK_ONE_RUN_TOTAL_COST, $result['totalCost'], 1e-6);
        $this->assertEqualsWithDelta(3871.401013, $result['costPerUnit'], 1e-9);
        $this->assertMargin(180000.0, 12960.0, self::NITROGEN_FUEL_BLOCK_ONE_RUN_TOTAL_COST, $result['margins']['jitaSell']);
    }

    // ===========================================
    // Hail L: T2, 5000 units per run, ME 2, Raitaru + ammunition rig II nullsec
    // 1 run is neutral to B1: ceil(1 / 5000) = 1 run.
    // ===========================================

    public function testHailLBuildsTenRootRunsIssue2(): void
    {
        $result = $this->analyzeHailL(runs: 10);

        // No structure, marginal cost mode: root 10 x base x 0.98, R.A.M. 9.8 units = 0.098 run at ME 10.
        $this->assertTrue($result['isT2']);
        $this->assertSame(50000, $result['outputQuantity']);
        $this->assertEqualsWithDelta(self::HAIL_L_TEN_RUNS_LEAVES, $this->leafQuantities($result), 1e-9);
        $this->assertEqualsWithDelta(6880110.241116, $result['materialCost'], 1e-6);
        // EIV(ME 0) x (0.05 + 0.25 % + 4 %): Hail L 70 216.261 x 10 runs, R.A.M. 46.838 x 0.098 run
        $this->assertJobInstallSteps(
            [
                [self::HAIL_L, 'manufacturing', 10.0, 64950.041425],
                [self::RAM_AMMUNITION_TECH, 'manufacturing', 0.098, 0.42458647],
            ],
            $result['jobInstallSteps'],
        );
        $this->assertEqualsWithDelta(64950.46601147, $result['jobInstallCost'], 1e-6);
    }

    public function testHailLInventionExpectsFractionalAttemptsAndPaysOnManufacturingEivIssue7(): void
    {
        $result = $this->analyzeHailL(runs: 1);

        // R8, skills at 0: P = 0.34, 1 run / (0.34 x 10 runs per BPC) = 0.294 attempt, not ceil(1 / 0.34) = 3.
        // Per attempt (R5, R6, NPC station): datacores 2 x 0.11 + 2 x 0.12
        // + invention 2 % x EIV(Hail L, ME 0) 70 216.261 x (0.06 + 0.25 % + 4 %) = 143.94333505
        // + 1-run T1 copy 2 % x EIV(T1, ME 0) 51.5 x (0.03 + 0.25 % + 4 %) = 0.074675.
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_INVENTION_COST, $result['inventionCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST, $result['totalCost'], 1e-6);
        $this->assertEqualsWithDelta($result['materialCost'] + $result['jobInstallCost'] + $result['inventionCost'], $result['totalCost'], 1e-6);
        $this->assertSame(self::HAIL_L_INVENTION_PROBABILITY, $result['invention']['baseProbability']);
        $this->assertNull($result['invention']['unknownReason']);
        $this->assertSame(['Stub Datacore A', 'Stub Datacore B'], $result['invention']['datacores']);
        $this->assertNull($result['invention']['selectedDecryptorTypeId']);
        $this->assertSame('No Decryptor', $result['invention']['selectedDecryptorName']);
        $this->assertCount(9, $result['invention']['options']);
        $noDecryptorOption = $result['invention']['options'][0];
        $this->assertNull($noDecryptorOption['decryptorTypeId']);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_INVENTION_COST, $noDecryptorOption['inventionCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST, $noDecryptorOption['totalProductionCost'], 1e-6);
        // Process Decryptor: P = 0.374, materials at ME 5, + 342.05 ISK of decryptor per attempt.
        $processOption = $this->decryptorOption($result, self::PROCESS_DECRYPTOR);
        $this->assertEqualsWithDelta(0.374, $processOption['probability'], 1e-12);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_PROCESS_INVENTION_COST, $processOption['inventionCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_PROCESS_TOTAL_COST, $processOption['totalProductionCost'], 1e-6);
    }

    public function testSelectedDecryptorMeAppliesToManufacturingMaterialsIssue18(): void
    {
        $result = $this->analyzeHailL(runs: 1, decryptorTypeId: self::PROCESS_DECRYPTOR);

        // R8: Process Decryptor, BPC ME 2 + 3 = 5; root 1 x base x 0.95, R.A.M. 0.95 unit = 0.0095 run at ME 10.
        $processOption = $this->decryptorOption($result, self::PROCESS_DECRYPTOR);
        $this->assertSame(5, $processOption['me']);
        $this->assertEqualsWithDelta(
            [
                self::TRITANIUM => 4.7538,
                35 => 3.7962,
                36 => 1.8981,
                37 => 0.7011,
                38 => 0.3078,
                11399 => 14.25,
                self::FERNITE_CARBIDE => 2850.0,
                self::FULLERIDES => 1140.0,
            ],
            $this->leafQuantities($result),
            1e-9,
        );
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_PROCESS_MATERIAL_COST, $result['materialCost'], 1e-6);
        $this->assertSame(self::PROCESS_DECRYPTOR, $result['invention']['selectedDecryptorTypeId']);
        $this->assertSame('Process Decryptor', $result['invention']['selectedDecryptorName']);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_PROCESS_INVENTION_COST, $result['inventionCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_PROCESS_TOTAL_COST, $result['totalCost'], 1e-6);
        $this->assertEqualsWithDelta($processOption['totalProductionCost'], $result['totalCost'], 1e-6);
    }

    public function testHailLListsTheNoDecryptorOptionThenEachDecryptorByTypeIdIssue18(): void
    {
        $result = $this->analyzeHailL(runs: 1);

        $this->assertDecryptorOptions(
            [
                [null, 'No Decryptor', 2, 4, 10, 0.34, 42.493532368, 694548.564245115],
                [34201, 'Accelerant Decryptor', 4, 14, 11, 0.408, 108.397506696, 680573.42604454],
                [34202, 'Attainment Decryptor', 1, 8, 14, 0.612, 56.780813498, 701583.372613696],
                [34203, 'Augmentation Decryptor', 0, 6, 19, 0.204, 125.518062448, 708672.630950098],
                [34204, 'Parity Decryptor', 3, 2, 13, 0.51, 73.38129865, 687558.930923946],
                [self::PROCESS_DECRYPTOR, 'Process Decryptor', 5, 10, 10, 0.374, 130.087703222, 673574.595153614],
                [34206, 'Symmetry Decryptor', 3, 12, 12, 0.34, 119.249512267, 687604.799137563],
                [34207, 'Optimized Attainment Decryptor', 3, 2, 12, 0.646, 62.764191183, 687548.313816478],
                [34208, 'Optimized Augmentation Decryptor', 4, 4, 17, 0.306, 93.532873904, 680558.561411748],
            ],
            $result['invention']['options'],
        );
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
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST, $noDecryptorOption['totalProductionCost'], 1e-6);
        $this->assertEqualsWithDelta($result['totalCost'], $noDecryptorOption['totalProductionCost'], 1e-6);
        $this->assertEqualsWithDelta(-0.654895, $noDecryptorOption['bestMargin'], 1e-6);
        $this->assertEqualsWithDelta($result['margins']['contractSell']['margin'], $noDecryptorOption['bestMargin'], 1e-9);

        $processOption = $this->decryptorOption($result, self::PROCESS_DECRYPTOR);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_PROCESS_TOTAL_COST, $processOption['totalProductionCost'], 1e-6);
        $this->assertEqualsWithDelta(2.438543, $processOption['bestMargin'], 1e-6);
    }

    public function testDecryptorOptionBestMarginIsTheBestMarketVenueWhenContractsSellLower(): void
    {
        $this->jitaSellPriceOfProduct = 142.0;
        $this->structureSellPriceOfProduct = 135.0;
        $this->structureBuyPriceOfProduct = 120.0;
        $this->contractSellPriceOfProduct = 125.0;

        $result = $this->analyzeHailL(runs: 1);

        // Jita: 710 000 - 7.2 % = 658 880, ahead of the structure sell order (626 400), the buy order (556 800) and the contract (625 000).
        $noDecryptorOption = $result['invention']['options'][0];
        $this->assertEqualsWithDelta(-5.135503, $noDecryptorOption['bestMargin'], 1e-6);
        $this->assertEqualsWithDelta($result['margins']['jitaSell']['margin'], $noDecryptorOption['bestMargin'], 1e-9);
        $this->assertEqualsWithDelta(-2.181584, $this->decryptorOption($result, self::PROCESS_DECRYPTOR)['bestMargin'], 1e-6);
    }

    public function testDecryptorOptionWithoutAnySellPriceHasNoBestMarginIssue8(): void
    {
        $result = $this->analyzeHailL(runs: 1);

        // R10: no venue has a price, so no option has a best margin (not 0 %).
        $this->assertSame(array_fill(0, 9, null), array_column($result['invention']['options'], 'bestMargin'));
    }

    public function testDecryptorOptionBestMarginIsTheBuyOrderWhenOnlyTheBrokerFeeMadeItLose(): void
    {
        $this->brokerFeeRate = 0.05;
        $this->salesTaxRate = 0.02;
        $this->jitaSellPriceOfProduct = 4150.0;
        $this->structureSellPriceOfProduct = 4120.0;
        $this->structureBuyPriceOfProduct = 4000.0;

        $result = $this->analyzeHailL(runs: 1);

        // 5 000 units: Jita 20 750 000 - 7 % = 19 297 500, structure 20 600 000 - 7 % = 19 158 000, buy order 20 000 000 - 2 % = 19 600 000.
        $this->assertMargin(20750000.0, 1452500.0, self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST, $result['margins']['jitaSell']);
        $this->assertMargin(20600000.0, 1442000.0, self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST, $result['margins']['structureSell']);
        $this->assertMargin(20000000.0, 400000.0, self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST, $result['margins']['structureBuy']);
        $this->assertEqualsWithDelta(
            (19600000.0 - self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST) / self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST * 100.0,
            $result['invention']['options'][0]['bestMargin'],
            1e-6,
        );
        $this->assertEqualsWithDelta(
            (19600000.0 - self::HAIL_L_ONE_RUN_PROCESS_TOTAL_COST) / self::HAIL_L_ONE_RUN_PROCESS_TOTAL_COST * 100.0,
            $this->decryptorOption($result, self::PROCESS_DECRYPTOR)['bestMargin'],
            1e-6,
        );
    }

    #[Group('issue-108')]
    public function testDecryptorOptionBestMarginKeepsItsOnlyVenueMarginBelowMinus999Percent(): void
    {
        $this->jitaSellPriceOfProduct = 1820.0;
        $this->brokerFeeRate = 1.0;
        $this->salesTaxRate = 1.0;

        $result = $this->analyzeHailL(runs: 1);

        // 5 000 units: revenue 9 100 000, fees 200 % = 18 200 000; no -999 sentinel left to win over a real margin.
        $this->assertMargin(9100000.0, 18200000.0, self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST, $result['margins']['jitaSell']);
        $this->assertEqualsWithDelta(
            (9100000.0 - 18200000.0 - self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST) / self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST * 100.0,
            $result['invention']['options'][0]['bestMargin'],
            1e-6,
        );
        $this->assertEqualsWithDelta(
            (9100000.0 - 18200000.0 - self::HAIL_L_ONE_RUN_PROCESS_TOTAL_COST) / self::HAIL_L_ONE_RUN_PROCESS_TOTAL_COST * 100.0,
            $this->decryptorOption($result, self::PROCESS_DECRYPTOR)['bestMargin'],
            1e-6,
        );
    }

    #[Group('issue-8')]
    public function testMaterialWithoutJitaPriceLeavesTheSelectedDecryptorInventionCostKnownButItsProductionCostAndMarginUnknown(): void
    {
        $this->leavesWithoutJitaPrice = [self::MORPHITE];
        $this->jitaSellPriceOfProduct = 140.0;
        $this->contractSellPriceOfProduct = 138.0;

        $result = $this->analyzeHailL(runs: 1, decryptorTypeId: self::PROCESS_DECRYPTOR);

        $this->assertNull($result['materialCost']);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_PROCESS_INSTALL_COST, $result['jobInstallCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_PROCESS_INVENTION_COST, $result['inventionCost'], 1e-6);
        $this->assertNull($result['totalCost']);
        $this->assertNull($result['costPerUnit']);
        $this->assertSame(['jitaSell' => null, 'structureSell' => null, 'structureBuy' => null, 'contractSell' => null], $result['margins']);
        $this->assertSame(self::PROCESS_DECRYPTOR, $result['invention']['selectedDecryptorTypeId']);
        $this->assertNull($result['invention']['unknownReason']);
        $this->assertOptionsKeepTheirInventionCostButNotTheirProductionCostNorMargin($result['invention']['options']);
        $this->assertMissingData([['market_price', self::MORPHITE]], $result);
    }

    #[Group('issue-8')]
    public function testTwoMaterialsWithoutJitaPriceAreEachListedInMissingData(): void
    {
        $this->leavesWithoutJitaPrice = [self::MORPHITE, self::TRITANIUM];

        $result = $this->analyzeHailL(runs: 1);

        $this->assertNull($this->materialLine($result, self::TRITANIUM)['totalPrice']);
        $this->assertNull($this->materialLine($result, self::MORPHITE)['totalPrice']);
        $this->assertNull($result['materialCost']);
        $this->assertNull($result['totalCost']);
        $this->assertMissingData([['market_price', self::TRITANIUM], ['market_price', self::MORPHITE]], $result);
    }

    #[Group('issue-7')]
    public function testUnknownJobInstallCostLeavesEveryDecryptorOptionInventionCostKnownButItsProductionCostAndMarginUnknown(): void
    {
        $this->withManufacturingCostIndex = false;
        $this->jitaSellPriceOfProduct = 140.0;
        $this->contractSellPriceOfProduct = 138.0;

        $result = $this->analyzeHailL(runs: 1, decryptorTypeId: self::PROCESS_DECRYPTOR);

        // The invention and copy jobs read the invention (0.06) and copying (0.03) indices, still known.
        $this->assertJobInstallSteps(
            [
                [self::HAIL_L, 'manufacturing', 1.0, null, [['cost_index', null]]],
                [self::RAM_AMMUNITION_TECH, 'manufacturing', 0.0095, null, [['cost_index', null]]],
            ],
            $result['jobInstallSteps'],
        );
        $this->assertNull($result['jobInstallCost']);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_PROCESS_MATERIAL_COST, $result['materialCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_PROCESS_INVENTION_COST, $result['inventionCost'], 1e-6);
        $this->assertNull($result['totalCost']);
        $this->assertNull($result['costPerUnit']);
        $this->assertSame(['jitaSell' => null, 'structureSell' => null, 'structureBuy' => null, 'contractSell' => null], $result['margins']);
        $this->assertNull($result['invention']['unknownReason']);
        $this->assertOptionsKeepTheirInventionCostButNotTheirProductionCostNorMargin($result['invention']['options']);
        $this->assertMissingData([['cost_index', null]], $result);
    }

    #[Group('issue-8')]
    public function testDecryptorOptionWithoutMarketPriceHasUnknownInventionCostProductionCostAndMargin(): void
    {
        $this->leavesWithoutJitaPrice = [self::PARITY_DECRYPTOR];
        $this->jitaSellPriceOfProduct = 140.0;

        $result = $this->analyzeHailL(runs: 1);

        // Jita 700 000 - 7.2 % = 649 600 is the only venue.
        $parityOption = $this->decryptorOption($result, self::PARITY_DECRYPTOR);
        $this->assertEqualsWithDelta(0.51, $parityOption['probability'], 1e-12);
        $this->assertNull($parityOption['inventionCost']);
        $this->assertNull($parityOption['totalProductionCost']);
        $this->assertNull($parityOption['bestMargin']);
        $noDecryptorOption = $result['invention']['options'][0];
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_INVENTION_COST, $noDecryptorOption['inventionCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST, $noDecryptorOption['totalProductionCost'], 1e-6);
        $this->assertEqualsWithDelta((649600.0 - self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST) / self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST * 100.0, $noDecryptorOption['bestMargin'], 1e-6);
        $accelerantOption = $this->decryptorOption($result, self::ACCELERANT_DECRYPTOR);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_INVENTION_COST_BY_OPTION[1], $accelerantOption['inventionCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_ACCELERANT_TOTAL_COST, $accelerantOption['totalProductionCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_INVENTION_COST, $result['inventionCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST, $result['totalCost'], 1e-6);
        $this->assertSame([], $result['missingData']);
    }

    #[Group('issue-8')]
    public function testSelectedDecryptorWithoutMarketPriceMakesTheTotalCostAndMarginsUnknown(): void
    {
        $this->leavesWithoutJitaPrice = [self::PARITY_DECRYPTOR];
        $this->jitaSellPriceOfProduct = 140.0;

        $result = $this->analyzeHailL(runs: 1, decryptorTypeId: self::PARITY_DECRYPTOR);

        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_PARITY_MATERIAL_COST, $result['materialCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_PARITY_INSTALL_COST, $result['jobInstallCost'], 1e-6);
        $this->assertNull($result['inventionCost']);
        $this->assertNull($result['totalCost']);
        $this->assertNull($result['costPerUnit']);
        $this->assertSame(['jitaSell' => null, 'structureSell' => null, 'structureBuy' => null, 'contractSell' => null], $result['margins']);
        $this->assertSame(self::PARITY_DECRYPTOR, $result['invention']['selectedDecryptorTypeId']);
        $this->assertSame('missing_market_price', $result['invention']['unknownReason']);
        $this->assertMissingData([['market_price', self::PARITY_DECRYPTOR]], $result);
    }

    #[Group('issue-115')]
    public function testInventionDatacoreMissingFromTheSdeIsRelayedWithANullName(): void
    {
        $this->typesMissingFromSde = [self::STUB_DATACORE_B];

        $result = $this->analyzeHailL(runs: 1);

        $this->assertSame(['Stub Datacore A', null], $result['invention']['datacores']);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_INVENTION_COST, $result['inventionCost'], 1e-6);
        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_ENGINE_TOTAL_COST, $result['totalCost'], 1e-6);
        $this->assertSame([], $result['missingData']);
    }

    public function testUnknownInventionProbabilityLeavesTotalCostAndMarginsUnknown(): void
    {
        $this->hailLInventionProbability = null;
        $this->jitaSellPriceOfProduct = 140.0;
        $this->contractSellPriceOfProduct = 138.0;

        $result = $this->analyzeHailL(runs: 1);

        $this->assertEqualsWithDelta(self::HAIL_L_ONE_RUN_MATERIAL_COST, $result['materialCost'], 1e-6);
        $this->assertNull($result['inventionCost']);
        $this->assertNull($result['totalCost']);
        $this->assertNull($result['costPerUnit']);
        $this->assertSame(['jitaSell' => null, 'structureSell' => null, 'structureBuy' => null, 'contractSell' => null], $result['margins']);
        $this->assertMissingData([['invention_probability', self::HAIL_L_T1_BLUEPRINT]], $result);
        $this->assertNull($result['invention']['baseProbability']);
        $this->assertSame('missing_invention_probability', $result['invention']['unknownReason']);
        $this->assertCount(9, $result['invention']['options']);
        foreach ($result['invention']['options'] as $option) {
            $this->assertNull($option['probability']);
            $this->assertNull($option['inventionCost']);
            $this->assertNull($option['totalProductionCost']);
            $this->assertNull($option['bestMargin']);
        }
    }

    // ===========================================
    // Fernite Carbide: reaction, 10000 units per run, 10 runs, Tatara + reactor rig II nullsec
    // ===========================================

    public function testFerniteCarbideRunsTenReactionsAndAggregatesFuelBlockDemandIssue2Issue16(): void
    {
        $result = $this->analyzeFerniteCarbide();

        // 10 reaction runs; Hydrogen Fuel Block demand 50 (root) + 25 + 25 = 100 units = 2.5 runs at ME 10, one job.
        $this->assertFalse($result['isT2']);
        $this->assertSame(100000, $result['outputQuantity']);
        // EIV(ME 0) x (cost index + 0.25 % + 4 %): reactions at 0.04, the fuel block at 0.05
        $this->assertJobInstallSteps(
            [
                [self::FERNITE_CARBIDE, 'reaction', 10.0, 2766.08475, []],
                [self::CERAMIC_POWDER, 'reaction', 5.0, 1381.186125, []],
                [self::FERNITE_ALLOY, 'reaction', 5.0, 1381.598625, []],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 2.5, self::FERNITE_CARBIDE_HYDROGEN_FUEL_BLOCK_INSTALL_COST, []],
            ],
            $result['jobInstallSteps'],
        );
        $this->assertSame([], $result['missingData']);
        $this->assertEqualsWithDelta(
            [
                44 => 9.0,
                3683 => 49.5,
                3689 => 9.0,
                9832 => 20.25,
                self::ROBOTICS => 2.25,
                16272 => 382.5,
                16273 => 787.5,
                16275 => 45.0,
                16635 => 500.0,
                16636 => 500.0,
                16639 => 500.0,
                16642 => 500.0,
                17889 => 1012.5,
            ],
            $this->leafQuantities($result),
            1e-9,
        );
        $this->assertEqualsWithDelta(self::FERNITE_CARBIDE_TEN_RUNS_MATERIAL_COST, $result['materialCost'], 1e-6);
        $this->assertEqualsWithDelta(9467.4348625, $result['jobInstallCost'], 1e-6);
        $this->assertEqualsWithDelta(725439.1998625, $result['totalCost'], 1e-6);
    }

    #[Group('issue-7')]
    public function testReactionTreeWithoutReactionCostIndexHasOnlyItsReactionStepsUnknownAndTheReasonListedOnce(): void
    {
        $this->withReactionCostIndex = false;

        $result = $this->analyzeFerniteCarbide();

        $this->assertJobInstallSteps(
            [
                [self::FERNITE_CARBIDE, 'reaction', 10.0, null, [['cost_index', null]]],
                [self::CERAMIC_POWDER, 'reaction', 5.0, null, [['cost_index', null]]],
                [self::FERNITE_ALLOY, 'reaction', 5.0, null, [['cost_index', null]]],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 2.5, self::FERNITE_CARBIDE_HYDROGEN_FUEL_BLOCK_INSTALL_COST, []],
            ],
            $result['jobInstallSteps'],
        );
        $this->assertNull($result['jobInstallCost']);
        $this->assertEqualsWithDelta(self::FERNITE_CARBIDE_TEN_RUNS_MATERIAL_COST, $result['materialCost'], 1e-6);
        $this->assertNull($result['totalCost']);
        $this->assertNull($result['costPerUnit']);
        $this->assertSame([['reason' => 'cost_index', 'typeId' => null]], $result['missingData']);
    }

    #[Group('issue-7')]
    public function testUnknownInstallCostsOfDifferentStepsListEachMissingDataOnce(): void
    {
        $this->withReactionCostIndex = false;
        $this->typesWithoutAdjustedPrice = [self::ROBOTICS];

        $result = $this->analyzeFerniteCarbide();

        // Robotics is a material of the Hydrogen Fuel Block only, the reactions miss their cost index only.
        $this->assertJobInstallSteps(
            [
                [self::FERNITE_CARBIDE, 'reaction', 10.0, null, [['cost_index', null]]],
                [self::CERAMIC_POWDER, 'reaction', 5.0, null, [['cost_index', null]]],
                [self::FERNITE_ALLOY, 'reaction', 5.0, null, [['cost_index', null]]],
                [self::HYDROGEN_FUEL_BLOCK, 'manufacturing', 2.5, null, [['adjusted_price', self::ROBOTICS]]],
            ],
            $result['jobInstallSteps'],
        );
        $this->assertNull($result['jobInstallCost']);
        $this->assertMissingData([['cost_index', null], ['adjusted_price', self::ROBOTICS]], $result);
    }

    #[Group('issue-8')]
    public function testMaterialWithoutJitaPriceNeededByThreeFuelBlockConsumersIsListedOnce(): void
    {
        $this->leavesWithoutJitaPrice = [self::ROBOTICS];

        $result = $this->analyzeFerniteCarbide();

        // 2.5 Hydrogen Fuel Block runs x 1 Robotics x 0.9.
        $this->assertEqualsWithDelta(2.25, $this->materialLine($result, self::ROBOTICS)['quantity'], 1e-9);
        $this->assertNull($result['materialCost']);
        $this->assertSame([['reason' => 'market_price', 'typeId' => self::ROBOTICS]], $result['missingData']);
    }

    // ===========================================
    // Nomad: 1 run, ME 10, no structure (Ravworks golden)
    // ===========================================

    public function testNomadAggregatesTheDemandOfEachIntermediateIssue16Issue69(): void
    {
        $result = $this->analyze(self::NOMAD, runs: 1, me: 10, solarSystemId: self::MANUFACTURING_SYSTEM_ID);

        // Marginal cost mode: one job per intermediate (65) plus the Nomad, each paid pro rata of its aggregated demand.
        // Values recomputed from the fixture with R1 without rounding (D4b); the Ravworks plan rounds runs up (plan mode).
        $leafQuantities = $this->leafQuantities($result);
        $this->assertFalse($result['isT2']);
        $this->assertSame(1, $result['outputQuantity']);
        $this->assertCount(63, $leafQuantities);
        $this->assertEqualsWithDelta(23027215.503295, array_sum($leafQuantities), 1e-3);
        $this->assertEqualsWithDelta(41735.580328125, $leafQuantities[self::NITROGEN_ISOTOPES], 1e-6);
        $this->assertEqualsWithDelta(507.7515648375, $leafQuantities[self::ROBOTICS], 1e-6);
        $this->assertEqualsWithDelta(3700426.356, $leafQuantities[self::TRITANIUM], 1e-6);
        $this->assertCount(66, $result['jobInstallSteps']);
        $this->assertEqualsWithDelta(187842750.270022, $result['materialCost'], 1e-3);
        $this->assertEqualsWithDelta(25772625.161982, $result['jobInstallCost'], 1e-3);
        $this->assertEqualsWithDelta(213615375.432004, $result['totalCost'], 1e-3);
    }

    // ===========================================
    // Harness
    // ===========================================

    /**
     * Hail L, Fernite Carbide and Fullerides bought.
     *
     * @return array<string, mixed>
     */
    private function analyzeHailL(int $runs, ?int $decryptorTypeId = null): array
    {
        return $this->analyze(
            self::HAIL_L,
            runs: $runs,
            me: 2,
            solarSystemId: self::MANUFACTURING_SYSTEM_ID,
            decryptorTypeId: $decryptorTypeId,
            user: $this->user(),
            blacklistedTypeIds: [self::FERNITE_CARBIDE, self::FULLERIDES],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeFerniteCarbide(): array
    {
        return $this->analyze(
            self::FERNITE_CARBIDE,
            runs: 10,
            me: 0,
            solarSystemId: self::MANUFACTURING_SYSTEM_ID,
            user: $this->user(),
        );
    }

    private function sellNitrogenFuelBlockOnEveryVenue(): void
    {
        $this->jitaSellPriceOfProduct = 4500.0;
        $this->structureSellPriceOfProduct = 4400.0;
        $this->structureBuyPriceOfProduct = 4000.0;
        $this->contractSellPriceOfProduct = 4300.0;
    }

    /**
     * @param list<int> $blacklistedTypeIds
     * @return array<string, mixed>
     */
    private function analyze(
        int $typeId,
        int $runs,
        int $me,
        int $solarSystemId,
        ?int $decryptorTypeId = null,
        ?User $user = null,
        array $blacklistedTypeIds = [],
    ): array {
        $productRepository = $this->sdeProductRepository();
        $materialRepository = $this->sdeMaterialRepository();
        $costIndexService = new EsiCostIndexService($this->createStub(EsiClient::class), $this->costIndexCache(), new NullLogger());
        $jitaMarketService = $this->jitaMarketService($typeId);
        $typeNameResolver = new TypeNameResolver($this->sdeInvTypeRepository());

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

        $service = new ProfitMarginService(
            new ProductionCostCalculator(
                new SdeBlueprintCatalog($productRepository, $this->sdeActivityTimeRepository(), $materialRepository, $this->sdeBlueprintRepository()),
                new SdeInventionCatalog($productRepository, $materialRepository),
                new JitaMarketPrices($jitaMarketService),
                new EsiAdjustedPrices($costIndexService),
                new EsiSystemCostIndices($costIndexService),
                $this->rigCategories(),
            ),
            $this->userProductionStructures(),
            $this->decryptors(),
            $jitaMarketService,
            $structureMarketService,
            $publicContractPriceService,
            $typeNameResolver,
            $cachedStructureRepository,
            $blacklistService,
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

    private function rigCategories(): RigCategories
    {
        $rigCategories = $this->createStub(RigCategories::class);
        $rigCategories->method('categoryOf')->willReturn(null);

        return $rigCategories;
    }

    private function userProductionStructures(): UserProductionStructures
    {
        $productionStructures = $this->createStub(UserProductionStructures::class);
        $productionStructures->method('configuredBy')->willReturnCallback(
            fn (string $userId): array => self::USER_ID === $userId ? $this->structures : [],
        );

        return $productionStructures;
    }

    private function decryptors(): Decryptors
    {
        $decryptors = $this->createStub(Decryptors::class);
        $decryptors->method('all')->willReturn(array_map(
            static fn (int $typeId, array $decryptor): Decryptor => new Decryptor($typeId, new Multiplier($decryptor[1]), $decryptor[2], $decryptor[3], $decryptor[4]),
            array_keys(self::DECRYPTORS),
            self::DECRYPTORS,
        ));

        return $decryptors;
    }

    private function user(): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, Uuid::fromString(self::USER_ID));

        return $user;
    }

    private function raitaru(int $solarSystemId, float $facilityTaxRate): ProductionStructure
    {
        return new ProductionStructure(
            'raitaru',
            StructureType::EngineeringComplex,
            SecurityClass::HighSec,
            $solarSystemId,
            new \DateTimeImmutable('2026-10-01'),
            new Multiplier(self::RAITARU_MATERIAL_ROLE_BONUS),
            [],
            new Multiplier(self::RAITARU_COST_ROLE_BONUS),
            $facilityTaxRate,
        );
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
            'reaction' => $this->withReactionCostIndex ? self::REACTION_COST_INDEX : null,
            'copying' => self::COPYING_COST_INDEX,
            'invention' => self::INVENTION_COST_INDEX,
        ];
        foreach ($costIndices as $activity => $costIndex) {
            if (null !== $costIndex) {
                $cache->save($cache->getItem('esi_cost_index_'.self::MANUFACTURING_SYSTEM_ID.'_'.$activity)->set($costIndex));
            }
        }
        $cache->save($cache->getItem('esi_cost_index_'.self::OTHER_SYSTEM_ID.'_manufacturing')->set(self::OTHER_SYSTEM_MANUFACTURING_COST_INDEX));
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
                        : ['weightedPrice' => \in_array($typeId, $this->leavesWithZeroJitaPrice, true) ? 0.0 : self::jitaPriceOf($typeId), 'coverage' => 1.0, 'ordersUsed' => 1];
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
                if (self::HAIL_L_T1_PRODUCT === $productTypeId) {
                    return IndustryActivityType::Manufacturing->value === $activityId ? $this->hailLT1Manufacturing() : null;
                }
                $recipe = $this->recipes[(string) $productTypeId] ?? null;
                if (null === $recipe || $this->activityOf($recipe) !== $activityId) {
                    return null;
                }

                return $this->product($this->blueprintOf($productTypeId), $activityId, $productTypeId, $recipe['outputPerRun']);
            },
        );
        $repository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?IndustryActivityProduct => self::HAIL_L_T1_BLUEPRINT === $criteria['typeId'] && IndustryActivityType::Manufacturing->value === $criteria['activityId']
                ? $this->hailLT1Manufacturing()
                : null,
        );
        $repository->method('findBy')->willReturnCallback(
            function (array $criteria): array {
                $blueprintTypeId = (int) $criteria['typeId'];
                if (self::HAIL_L_T1_BLUEPRINT === $blueprintTypeId) {
                    return [$this->hailLT1Manufacturing()];
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
            function (int $blueprintTypeId, int $activityId): array {
                if (self::HAIL_L_T1_BLUEPRINT === $blueprintTypeId) {
                    $materials = match ($activityId) {
                        IndustryActivityType::Invention->value => [self::STUB_DATACORE_A => self::DATACORE_QUANTITY_PER_ATTEMPT, self::STUB_DATACORE_B => self::DATACORE_QUANTITY_PER_ATTEMPT],
                        IndustryActivityType::Manufacturing->value => self::HAIL_L_T1_MATERIALS,
                        default => [],
                    };

                    return array_map(
                        fn (int $materialTypeId, int $quantity): IndustryActivityMaterial => $this->material($blueprintTypeId, $activityId, $materialTypeId, $quantity),
                        array_keys($materials),
                        $materials,
                    );
                }

                return array_map(
                    fn (array $row): IndustryActivityMaterial => $this->material($blueprintTypeId, $activityId, $row['materialTypeId'], $row['quantity']),
                    $this->baseMaterialsOf($blueprintTypeId, $activityId),
                );
            },
        );

        return $repository;
    }

    /**
     * Type names of the fixture, without the types the test removes from the SDE.
     */
    private function sdeInvTypeRepository(): InvTypeRepository
    {
        $knownTypes = [];
        foreach ($this->typeNames as $typeId => $typeName) {
            if (!\in_array((int) $typeId, $this->typesMissingFromSde, true)) {
                $knownTypes[(int) $typeId] = (new InvType())->setTypeId((int) $typeId)->setTypeName($typeName);
            }
        }

        $repository = $this->createStub(InvTypeRepository::class);
        $repository->method('find')->willReturnCallback(
            static fn (mixed $typeId): ?InvType => \is_int($typeId) ? ($knownTypes[$typeId] ?? null) : null,
        );
        $repository->method('findByTypeIds')->willReturnCallback(
            static fn (array $typeIds): array => array_intersect_key($knownTypes, array_flip($typeIds)),
        );

        return $repository;
    }

    private function sdeBlueprintRepository(): IndustryBlueprintRepository
    {
        $repository = $this->createStub(IndustryBlueprintRepository::class);
        $repository->method('find')->willReturnCallback(
            static fn (int $blueprintTypeId): IndustryBlueprint => (new IndustryBlueprint())
                ->setTypeId($blueprintTypeId)
                ->setMaxProductionLimit(self::SYNTHETIC_MAX_PRODUCTION_LIMIT),
        );

        return $repository;
    }

    private function sdeActivityTimeRepository(): IndustryActivityRepository
    {
        $repository = $this->createStub(IndustryActivityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): IndustryActivity => (new IndustryActivity())
                ->setTypeId((int) $criteria['typeId'])
                ->setActivityId((int) $criteria['activityId'])
                ->setTime(self::SYNTHETIC_ACTIVITY_TIME),
        );

        return $repository;
    }

    private function hailLT1Manufacturing(): IndustryActivityProduct
    {
        return $this->product(self::HAIL_L_T1_BLUEPRINT, IndustryActivityType::Manufacturing->value, self::HAIL_L_T1_PRODUCT, self::HAIL_L_T1_OUTPUT_PER_RUN);
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
     * Jobs compared by product, whatever their order; runs are fractional in marginal cost mode (R12).
     * When given, the data missing from the install cost of the job (R10), each once, whatever the order.
     *
     * @param list<array{0: int, 1: string, 2: float, 3: ?float, 4?: list<array{string, ?int}>}> $expected productTypeId, activityType, runs, installCost, missingData
     * @param list<array<string, mixed>>                                                         $steps
     */
    private function assertJobInstallSteps(array $expected, array $steps): void
    {
        $this->assertCount(\count($expected), $steps);
        $stepsByProduct = array_column($steps, null, 'productTypeId');
        foreach ($expected as $expectedStep) {
            [$productTypeId, $activityType, $runs, $installCost] = $expectedStep;
            $this->assertArrayHasKey($productTypeId, $stepsByProduct);
            $step = $stepsByProduct[$productTypeId];
            $this->assertSame($activityType, $step['activityType']);
            $this->assertEqualsWithDelta($runs, $step['runs'], 1e-9);
            if (null === $installCost) {
                $this->assertNull($step['installCost']);
            } else {
                $this->assertNotNull($step['installCost']);
                $this->assertEqualsWithDelta($installCost, $step['installCost'], 1e-6);
            }
            if (isset($expectedStep[4])) {
                $this->assertArrayHasKey('missingData', $step);
                $this->assertEqualsCanonicalizing(self::missingDataOf($expectedStep[4]), $step['missingData']);
            }
        }
    }

    /**
     * A priced material line: totalPrice = quantity x unitPrice.
     *
     * @param array<string, mixed> $result
     */
    private function assertPricedMaterialLine(int $typeId, string $typeName, float $quantity, float $unitPrice, array $result): void
    {
        $line = $this->materialLine($result, $typeId);
        $this->assertSame(['typeId' => $typeId, 'typeName' => $typeName], array_intersect_key($line, array_flip(['typeId', 'typeName'])));
        $this->assertEqualsWithDelta($quantity, $line['quantity'], 1e-9);
        $this->assertNotNull($line['unitPrice']);
        $this->assertEqualsWithDelta($unitPrice, $line['unitPrice'], 1e-9);
        $this->assertNotNull($line['totalPrice']);
        $this->assertEqualsWithDelta($quantity * $unitPrice, $line['totalPrice'], 1e-6);
    }

    /**
     * Hail L, one run: every option keeps its invention cost, but the production cost and the margin of none is known.
     *
     * @param list<array<string, mixed>> $options
     */
    private function assertOptionsKeepTheirInventionCostButNotTheirProductionCostNorMargin(array $options): void
    {
        $this->assertCount(\count(self::HAIL_L_ONE_RUN_INVENTION_COST_BY_OPTION), $options);
        foreach (self::HAIL_L_ONE_RUN_INVENTION_COST_BY_OPTION as $index => $inventionCost) {
            $this->assertNotNull($options[$index]['inventionCost']);
            $this->assertEqualsWithDelta($inventionCost, $options[$index]['inventionCost'], 1e-6);
        }
        $this->assertSame(array_fill(0, 9, null), array_column($options, 'totalProductionCost'));
        $this->assertSame(array_fill(0, 9, null), array_column($options, 'bestMargin'));
    }

    /**
     * Data missing from an unknown cost (R10), each once, whatever the order.
     *
     * @param list<array{string, ?int}> $expected reason, typeId
     * @param array<string, mixed>      $result
     */
    private function assertMissingData(array $expected, array $result): void
    {
        $this->assertArrayHasKey('missingData', $result);
        $this->assertEqualsCanonicalizing(self::missingDataOf($expected), $result['missingData']);
    }

    /**
     * @param list<array{string, ?int}> $missingData reason, typeId
     *
     * @return list<array{reason: string, typeId: ?int}>
     */
    private static function missingDataOf(array $missingData): array
    {
        return array_map(static fn (array $missing): array => ['reason' => $missing[0], 'typeId' => $missing[1]], $missingData);
    }

    /**
     * Profit = revenue - fees - production cost; margin = profit / production cost.
     *
     * @param array{revenue: float, fees: float, profit: float, margin: float|null}|null $actual
     */
    private function assertMargin(float $revenue, float $fees, float $totalCost, ?array $actual): void
    {
        $profit = $revenue - $fees - $totalCost;
        $this->assertNotNull($actual);
        $this->assertEqualsWithDelta($revenue, $actual['revenue'], 1e-6);
        $this->assertEqualsWithDelta($fees, $actual['fees'], 1e-6);
        $this->assertEqualsWithDelta($profit, $actual['profit'], 1e-6);
        $this->assertEqualsWithDelta($profit / $totalCost * 100.0, $actual['margin'], 1e-9);
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
