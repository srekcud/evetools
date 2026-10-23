<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedCharacterSkill;
use App\Entity\CachedStructure;
use App\Entity\Character;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryBpcPriceRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivitySkillRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\Industry\BatchProfitScannerService;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\FacilityTaxResolver;
use App\Service\Industry\InventionService;
use App\Service\Industry\JobInstallCostEstimator;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;
use App\Industry\Domain\SecurityClass;
use App\Tests\Unit\Industry\Application\Double\InMemoryUserProductionStructures;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(BatchProfitScannerService::class)]
class BatchProfitScanRulesTest extends TestCase
{
    private const int MANUFACTURING = 1;
    private const int REACTION = 11;
    private const int SOLAR_SYSTEM_ID = 30002510;
    private const int REACTION_SOLAR_SYSTEM_ID = 30000144;
    private const string USER_ID = '0192f3a4-7b1c-7d2e-8f3a-4b5c6d7e8f90';

    private const int TRITANIUM_TYPE_ID = 34;
    private const int PYERITE_TYPE_ID = 35;
    private const int MORPHITE_TYPE_ID = 11399;
    private const int HAFNIUM_TYPE_ID = 16649;
    private const int HYDROCARBONS_TYPE_ID = 16633;

    private const int SHIELD_EXTENDER_TYPE_ID = 3831;
    private const int SHIELD_EXTENDER_BLUEPRINT_ID = 3832;
    private const int SHIELD_EXTENDER_II_TYPE_ID = 3841;
    private const int SHIELD_EXTENDER_II_BLUEPRINT_ID = 3842;
    private const int FERROGEL_TYPE_ID = 16683;
    private const int FERROGEL_FORMULA_ID = 46174;

    private const int AMARR_SYSTEM_ID = 30002187;
    private const int DOMAIN_REGION_ID = 10000043;
    private const int AMARR_STRUCTURE_ID = 1035466617946;

    private const int INDUSTRY_SKILL_ID = 3380;
    private const int ADVANCED_INDUSTRY_SKILL_ID = 3388;
    private const int CAPITAL_SHIP_CONSTRUCTION_SKILL_ID = 22242;
    private const int REACTIONS_SKILL_ID = 45746;

    private array $products = [];
    private array $typeRows = [];
    private array $materialVolumeRows = [];
    private array $materialsByActivity = [];
    private array $jitaPrices = [];
    private array $jitaDailyVolumes = [];
    private array $regionDailyVolumes = [];
    private array $structurePrices = [];
    private array $adjustedPrices = [
        self::TRITANIUM_TYPE_ID => 0.0,
        self::PYERITE_TYPE_ID => 0.0,
        self::MORPHITE_TYPE_ID => 0.0,
        self::HAFNIUM_TYPE_ID => 0.0,
        self::HYDROCARBONS_TYPE_ID => 0.0,
    ];
    private array $costIndices = ['manufacturing' => 0.0, 'reaction' => 0.0];
    private array $reactionSystemCostIndices = ['manufacturing' => 0.0, 'reaction' => 0.0];
    private array $t2ProductIds = [];
    private array $factionBlueprintIds = [];
    private array $requiredSkillsByActivity = [];
    private array $skillsByCharacter = [];
    private array $cachedStructures = [];
    private array $solarSystems = [];
    private array $structuresByUserId = [];

    public function testProfitableT1ModuleReportsEveryFigureRoundedToTheCent(): void
    {
        $this->givenProduct(self::SHIELD_EXTENDER_BLUEPRINT_ID, self::SHIELD_EXTENDER_TYPE_ID, self::MANUFACTURING, 1, [
            self::TRITANIUM_TYPE_ID => 19,
            self::PYERITE_TYPE_ID => 1,
        ]);
        $this->givenType(self::SHIELD_EXTENDER_TYPE_ID, '38', 'Shield Extender', '7', '5.0');
        $this->materialVolumeRows = [self::TRITANIUM_TYPE_ID => '0.01', self::PYERITE_TYPE_ID => '0.01'];
        $this->jitaPrices = [self::TRITANIUM_TYPE_ID => 5.3713, self::PYERITE_TYPE_ID => 11.23, self::SHIELD_EXTENDER_TYPE_ID => 6543.219];
        $this->jitaDailyVolumes = [self::SHIELD_EXTENDER_TYPE_ID => 37.0];
        $this->adjustedPrices = [self::TRITANIUM_TYPE_ID => 4.0, self::PYERITE_TYPE_ID => 9.0];
        $this->costIndices = ['manufacturing' => 0.0437, 'reaction' => 0.0711];

        $result = $this->scan(brokerFeeRate: 0.0215, salesTaxRate: 0.036, exportCostPerM3: 812.3456);

        $this->assertSame([[
            'typeId' => self::SHIELD_EXTENDER_TYPE_ID,
            'typeName' => 'Type 3831',
            'groupName' => 'Shield Extender',
            'categoryLabel' => 'T1 Modules',
            'marginPercent' => 42.38,
            'profitPerUnit' => 1835.67,
            'dailyVolume' => 37.0,
            'iskPerDay' => 67919.78,
            'materialCost' => 107.91,
            'importCost' => 154.35,
            'exportCost' => 4061.73,
            'sellPrice' => 6543.22,
            'meUsed' => 10,
            'activityType' => 'manufacturing',
            'isFactionBlueprint' => false,
            'bpcCostPerRun' => null,
            'hasAllSkills' => true,
            'missingSkillCount' => 0,
            'unknownReason' => null,
            'missingPriceTypeIds' => [],
        ]], $result);
    }

    public function testReactionUsesBaseQuantitiesAndTheReactionCostIndex(): void
    {
        $this->givenProduct(self::FERROGEL_FORMULA_ID, self::FERROGEL_TYPE_ID, self::REACTION, 100, [
            self::HAFNIUM_TYPE_ID => 100,
            self::HYDROCARBONS_TYPE_ID => 100,
        ]);
        $this->givenType(self::FERROGEL_TYPE_ID, '429', 'Composite', '4', '1.0');
        $this->jitaPrices = [self::HAFNIUM_TYPE_ID => 1450.0, self::HYDROCARBONS_TYPE_ID => 520.0, self::FERROGEL_TYPE_ID => 2400.0];
        $this->adjustedPrices = [self::HAFNIUM_TYPE_ID => 1400.0, self::HYDROCARBONS_TYPE_ID => 500.0];
        $this->costIndices = ['manufacturing' => 0.0437, 'reaction' => 0.0713];

        $ferrogel = $this->scan()[0];

        $this->assertSame(197000.0, $ferrogel['materialCost']);
        $this->assertSame(213.78, $ferrogel['profitPerUnit']);
        $this->assertSame(0, $ferrogel['meUsed']);
        $this->assertSame('reaction', $ferrogel['activityType']);
        $this->assertSame('Reaction', $ferrogel['categoryLabel']);
    }

    public function testManufacturingInstallCostUsesTheFacilityTaxOfTheUserStructureInTheSystem(): void
    {
        $this->givenProduct(self::SHIELD_EXTENDER_BLUEPRINT_ID, self::SHIELD_EXTENDER_TYPE_ID, self::MANUFACTURING, 1, [
            self::TRITANIUM_TYPE_ID => 19,
            self::PYERITE_TYPE_ID => 1,
        ]);
        $this->givenType(self::SHIELD_EXTENDER_TYPE_ID, '38', 'Shield Extender', '7', '5.0');
        $this->jitaPrices = [self::TRITANIUM_TYPE_ID => 5.3713, self::PYERITE_TYPE_ID => 11.23, self::SHIELD_EXTENDER_TYPE_ID => 1000.0];
        $this->adjustedPrices = [self::TRITANIUM_TYPE_ID => 4.0, self::PYERITE_TYPE_ID => 9.0];
        $this->costIndices = ['manufacturing' => 0.0437, 'reaction' => 0.0711];
        $this->structuresByUserId = [self::USER_ID => [
            ProductionStructures::raitaruWithFacilityTax('raitaru', SecurityClass::NullSec, self::SOLAR_SYSTEM_ID, 0.05),
        ]];

        $shieldExtender = $this->scan(user: $this->userWithCharacterSkills([]))[0];

        $this->assertSame(880.72, $shieldExtender['profitPerUnit']);
    }

    public function testReactionInstallCostUsesTheFacilityTaxOfTheUserRefineryInTheSystem(): void
    {
        $this->givenProduct(self::FERROGEL_FORMULA_ID, self::FERROGEL_TYPE_ID, self::REACTION, 100, [
            self::HAFNIUM_TYPE_ID => 100,
            self::HYDROCARBONS_TYPE_ID => 100,
        ]);
        $this->givenType(self::FERROGEL_TYPE_ID, '429', 'Composite', '4', '1.0');
        $this->jitaPrices = [self::HAFNIUM_TYPE_ID => 1450.0, self::HYDROCARBONS_TYPE_ID => 520.0, self::FERROGEL_TYPE_ID => 2400.0];
        $this->adjustedPrices = [self::HAFNIUM_TYPE_ID => 1400.0, self::HYDROCARBONS_TYPE_ID => 500.0];
        $this->costIndices = ['manufacturing' => 0.0437, 'reaction' => 0.0713];
        $this->structuresByUserId = [self::USER_ID => [
            ProductionStructures::raitaruWithFacilityTax('raitaru', SecurityClass::NullSec, self::SOLAR_SYSTEM_ID, 0.05),
            ProductionStructures::tataraWithFacilityTax('tatara', SecurityClass::NullSec, self::SOLAR_SYSTEM_ID, 0.02),
        ]];

        $ferrogel = $this->scan(user: $this->userWithCharacterSkills([]))[0];

        $this->assertSame(180.53, $ferrogel['profitPerUnit']);
    }

    #[Group('issue-128')]
    public function testReactionUsesTheCostIndexOfTheReactionSolarSystem(): void
    {
        $this->givenFerrogelReactionPricedAsInTheReactionCostIndexExample();
        $this->costIndices = ['manufacturing' => 0.0437, 'reaction' => 0.0211];
        $this->reactionSystemCostIndices = ['manufacturing' => 0.0911, 'reaction' => 0.0713];

        $ferrogel = $this->scan(reactionSolarSystemId: self::REACTION_SOLAR_SYSTEM_ID)[0];

        $this->assertSame(213.78, $ferrogel['profitPerUnit']);
    }

    #[Group('issue-128')]
    public function testReactionInstallCostUsesTheFacilityTaxOfTheUserRefineryInTheReactionSolarSystem(): void
    {
        $this->givenFerrogelReactionPricedAsInTheReactionCostIndexExample();
        $this->costIndices = ['manufacturing' => 0.0437, 'reaction' => 0.0713];
        $this->reactionSystemCostIndices = ['manufacturing' => 0.0911, 'reaction' => 0.0713];
        $this->structuresByUserId = [self::USER_ID => [
            ProductionStructures::tataraWithFacilityTax('tatara-manufacturing-system', SecurityClass::NullSec, self::SOLAR_SYSTEM_ID, 0.07),
            ProductionStructures::tataraWithFacilityTax('tatara-reaction-system', SecurityClass::NullSec, self::REACTION_SOLAR_SYSTEM_ID, 0.02),
        ]];

        $ferrogel = $this->scan(user: $this->userWithCharacterSkills([]), reactionSolarSystemId: self::REACTION_SOLAR_SYSTEM_ID)[0];

        $this->assertSame(180.53, $ferrogel['profitPerUnit']);
    }

    #[Group('issue-128')]
    public function testManufacturingKeepsTheManufacturingSolarSystemWhenTheReactionSolarSystemDiffers(): void
    {
        $this->givenProduct(self::SHIELD_EXTENDER_BLUEPRINT_ID, self::SHIELD_EXTENDER_TYPE_ID, self::MANUFACTURING, 1, [
            self::TRITANIUM_TYPE_ID => 19,
            self::PYERITE_TYPE_ID => 1,
        ]);
        $this->givenType(self::SHIELD_EXTENDER_TYPE_ID, '38', 'Shield Extender', '7', '5.0');
        $this->jitaPrices = [self::TRITANIUM_TYPE_ID => 5.3713, self::PYERITE_TYPE_ID => 11.23, self::SHIELD_EXTENDER_TYPE_ID => 1000.0];
        $this->adjustedPrices = [self::TRITANIUM_TYPE_ID => 4.0, self::PYERITE_TYPE_ID => 9.0];
        $this->costIndices = ['manufacturing' => 0.0437, 'reaction' => 0.0711];
        $this->reactionSystemCostIndices = ['manufacturing' => 0.0911, 'reaction' => 0.0713];
        $this->structuresByUserId = [self::USER_ID => [
            ProductionStructures::raitaruWithFacilityTax('raitaru-manufacturing-system', SecurityClass::NullSec, self::SOLAR_SYSTEM_ID, 0.05),
            ProductionStructures::raitaruWithFacilityTax('raitaru-reaction-system', SecurityClass::NullSec, self::REACTION_SOLAR_SYSTEM_ID, 0.09),
        ]];

        $shieldExtender = $this->scan(user: $this->userWithCharacterSkills([]), reactionSolarSystemId: self::REACTION_SOLAR_SYSTEM_ID)[0];

        $this->assertSame(880.72, $shieldExtender['profitPerUnit']);
    }

    private function givenFerrogelReactionPricedAsInTheReactionCostIndexExample(): void
    {
        $this->givenProduct(self::FERROGEL_FORMULA_ID, self::FERROGEL_TYPE_ID, self::REACTION, 100, [
            self::HAFNIUM_TYPE_ID => 100,
            self::HYDROCARBONS_TYPE_ID => 100,
        ]);
        $this->givenType(self::FERROGEL_TYPE_ID, '429', 'Composite', '4', '1.0');
        $this->jitaPrices = [self::HAFNIUM_TYPE_ID => 1450.0, self::HYDROCARBONS_TYPE_ID => 520.0, self::FERROGEL_TYPE_ID => 2400.0];
        $this->adjustedPrices = [self::HAFNIUM_TYPE_ID => 1400.0, self::HYDROCARBONS_TYPE_ID => 500.0];
    }

    public function testT2ModuleMaterialsUseMaterialEfficiencyTwo(): void
    {
        $this->givenProduct(self::SHIELD_EXTENDER_II_BLUEPRINT_ID, self::SHIELD_EXTENDER_II_TYPE_ID, self::MANUFACTURING, 1, [
            self::TRITANIUM_TYPE_ID => 50,
            self::MORPHITE_TYPE_ID => 7,
        ]);
        $this->givenType(self::SHIELD_EXTENDER_II_TYPE_ID, '38', 'Shield Extender', '7', '5.0');
        $this->t2ProductIds = [self::SHIELD_EXTENDER_II_TYPE_ID => true];
        $this->jitaPrices = [self::TRITANIUM_TYPE_ID => 6.0, self::MORPHITE_TYPE_ID => 9750.0, self::SHIELD_EXTENDER_II_TYPE_ID => 95000.0];

        $shieldExtenderII = $this->scan()[0];

        $this->assertSame(68544.0, $shieldExtenderII['materialCost']);
        $this->assertSame(2, $shieldExtenderII['meUsed']);
        $this->assertSame('T2 Modules', $shieldExtenderII['categoryLabel']);
    }

    public function testSystemWithoutManufacturingCostIndexKeepsTheProductWithUnknownMarginAndKnownMaterialCost(): void
    {
        $this->givenModuleSoldAt(5301, 100.0, 10.0);
        $this->adjustedPrices = [self::TRITANIUM_TYPE_ID => 4.0];
        $this->costIndices = ['manufacturing' => null, 'reaction' => 0.0711];

        $result = $this->scan();

        $this->assertCount(1, $result);
        $this->assertSame(5301, $result[0]['typeId']);
        $this->assertNull($result[0]['marginPercent']);
        $this->assertNull($result[0]['profitPerUnit']);
        $this->assertNull($result[0]['iskPerDay']);
        $this->assertSame('missing_cost_index', $result[0]['unknownReason']);
        $this->assertSame(45.0, $result[0]['materialCost']);
        $this->assertSame([], $result[0]['missingPriceTypeIds']);
    }

    public function testMaterialWithoutAdjustedPriceButWithJitaPriceLeavesMarginUnknownAndMaterialCostKnown(): void
    {
        $this->givenProduct(5401 + 10000, 5401, self::MANUFACTURING, 1, [
            self::TRITANIUM_TYPE_ID => 10,
            self::PYERITE_TYPE_ID => 20,
        ]);
        $this->givenType(5401, '38', 'Shield Extender', '7', '5.0');
        $this->jitaPrices = [self::TRITANIUM_TYPE_ID => 5.0, self::PYERITE_TYPE_ID => 11.0, 5401 => 400.0];
        $this->jitaDailyVolumes = [5401 => 10.0];
        $this->adjustedPrices = [self::TRITANIUM_TYPE_ID => 4.0];
        $this->costIndices = ['manufacturing' => 0.0437, 'reaction' => 0.0711];

        $result = $this->scan();

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['marginPercent']);
        $this->assertNull($result[0]['profitPerUnit']);
        $this->assertNull($result[0]['iskPerDay']);
        $this->assertSame('missing_adjusted_price', $result[0]['unknownReason']);
        $this->assertSame(243.0, $result[0]['materialCost']);
        $this->assertSame([], $result[0]['missingPriceTypeIds']);
    }

    public function testMissingMaterialPriceTakesPrecedenceOverMissingCostIndex(): void
    {
        $this->givenModuleSoldAt(5501, 100.0, 10.0);
        unset($this->jitaPrices[self::TRITANIUM_TYPE_ID]);
        $this->adjustedPrices = [self::TRITANIUM_TYPE_ID => 4.0];
        $this->costIndices = ['manufacturing' => null, 'reaction' => 0.0711];

        $result = $this->scan();

        $this->assertCount(1, $result);
        $this->assertSame('missing_material_price', $result[0]['unknownReason']);
        $this->assertNull($result[0]['materialCost']);
        $this->assertNull($result[0]['marginPercent']);
        $this->assertSame([self::TRITANIUM_TYPE_ID], $result[0]['missingPriceTypeIds']);
    }

    public function testMinimumMarginExcludesAProductOfASystemWithoutCostIndex(): void
    {
        $this->givenModuleSoldAt(5601, 100.0, 10.0);
        $this->adjustedPrices = [self::TRITANIUM_TYPE_ID => 4.0];
        $this->costIndices = ['manufacturing' => null, 'reaction' => 0.0711];

        $withoutFilter = $this->scan();
        $withMinMargin = $this->scan(minMarginPercent: 10.0);

        $this->assertSame([5601], array_column($withoutFilter, 'typeId'));
        $this->assertSame([], $withMinMargin);
    }

    public function testProductsWithKnownMarginsAreRankedByIskPerDayBeforeProductsWithoutSalePrice(): void
    {
        $this->givenModuleSoldAt(5001, 100.0, 10.0);
        $this->givenModuleSoldAt(5002, null, 90.0);
        $this->givenModuleSoldAt(5003, 300.0, 1.0);
        $this->givenModuleSoldAt(5004, 145.0, 4.0);

        $result = $this->scan();

        $this->assertSame([5001, 5004, 5003, 5002], array_column($result, 'typeId'));
        $this->assertSame([550.0, 400.0, 255.0, null], array_column($result, 'iskPerDay'));
    }

    public function testMinimumMarginKeepsAProductExactlyAtTheThresholdAfterExcludingALowerOne(): void
    {
        $this->givenModuleSoldAt(5101, 67.5, 10.0);
        $this->givenModuleSoldAt(5102, 90.0, 10.0);

        $result = $this->scan(minMarginPercent: 100.0);

        $this->assertSame([5102], array_column($result, 'typeId'));
        $this->assertSame([100.0], array_column($result, 'marginPercent'));
    }

    public function testMinimumDailyVolumeKeepsAProductExactlyAtTheThresholdAfterExcludingALowerOne(): void
    {
        $this->givenModuleSoldAt(5201, 70.0, 5.0);
        $this->givenModuleSoldAt(5202, 80.0, 20.0);

        $result = $this->scan(minDailyVolume: 20.0);

        $this->assertSame([5202], array_column($result, 'typeId'));
    }

    #[DataProvider('scannerCategories')]
    public function testCategoryKeepsOnlyItsProducts(string $category, array $expectedTypeIds): void
    {
        $this->givenCatalogOfEveryCategory();

        $typeIds = array_column($this->scan(category: $category), 'typeId');
        sort($typeIds);

        $this->assertSame($expectedTypeIds, $typeIds);
    }

    public static function scannerCategories(): iterable
    {
        yield 'all' => ['all', [601, 602, 603, 604, 605, 606, 607, 608, 609, 610, 611]];
        yield 'T1 ships' => ['t1_ships', [601, 603]];
        yield 'T2 ships' => ['t2_ships', [602]];
        yield 'capitals' => ['capitals', [603]];
        yield 'T1 modules' => ['t1_modules', [604, 606]];
        yield 'T2 modules' => ['t2_modules', [605]];
        yield 'ammo and charges' => ['ammo_charges', [607]];
        yield 'drones' => ['drones', [608]];
        yield 'rigs' => ['rigs', [606]];
        yield 'components' => ['components', [609]];
        yield 'reactions' => ['reactions', [610]];
    }

    public function testEveryProductCarriesTheLabelOfItsCategoryAndItsFactionBlueprintFlag(): void
    {
        $this->givenCatalogOfEveryCategory();
        $this->givenModuleSoldAt(612, 840.0, 3.0);
        $this->givenProductWithoutMetadataSoldAt(613, 910.0);

        $result = $this->scan();

        $this->assertSame([
            601 => ['T1 Ships', true],
            602 => ['T2 Ships', false],
            603 => ['Capitals', false],
            604 => ['T1 Modules', false],
            605 => ['T2 Modules', false],
            606 => ['Rigs', false],
            607 => ['Ammo & Charges', false],
            608 => ['Drones', false],
            609 => ['Components', false],
            610 => ['Reaction', false],
            611 => ['T1', false],
            612 => ['T1 Modules', false],
            613 => ['T1', false],
        ], $this->labelsAndFactionFlagsByTypeId($result));
    }

    public function testStructureVenueSellsAtTheStructurePriceWithTheRegionDailyVolumeAndNoExportCost(): void
    {
        $this->givenStructureMarketScenario();
        $this->givenCachedStructureIn(self::AMARR_SYSTEM_ID);
        $this->givenSolarSystemInRegion(self::AMARR_SYSTEM_ID, self::DOMAIN_REGION_ID);

        $row = $this->scan(sellVenue: 'structure', structureId: self::AMARR_STRUCTURE_ID, exportCostPerM3: 300.0)[0];

        $this->assertSame([1200.0, 8.0, 0.0], [$row['sellPrice'], $row['dailyVolume'], $row['exportCost']]);
    }

    public function testStructureVenueFallsBackToJitaDailyVolumeWhenTheStructureIsNotCached(): void
    {
        $this->givenStructureMarketScenario();

        $row = $this->scan(sellVenue: 'structure', structureId: self::AMARR_STRUCTURE_ID)[0];

        $this->assertSame([1200.0, 30.0], [$row['sellPrice'], $row['dailyVolume']]);
    }

    public function testStructureVenueFallsBackToJitaDailyVolumeWhenTheStructureSolarSystemIsUnknown(): void
    {
        $this->givenStructureMarketScenario();
        $this->givenCachedStructureIn(null);

        $row = $this->scan(sellVenue: 'structure', structureId: self::AMARR_STRUCTURE_ID)[0];

        $this->assertSame([1200.0, 30.0], [$row['sellPrice'], $row['dailyVolume']]);
    }

    public function testStructureVenueFallsBackToJitaDailyVolumeWhenTheSolarSystemIsMissingFromTheSde(): void
    {
        $this->givenStructureMarketScenario();
        $this->givenCachedStructureIn(self::AMARR_SYSTEM_ID);

        $row = $this->scan(sellVenue: 'structure', structureId: self::AMARR_STRUCTURE_ID)[0];

        $this->assertSame([1200.0, 30.0], [$row['sellPrice'], $row['dailyVolume']]);
    }

    public function testJitaVenueIgnoresAGivenStructure(): void
    {
        $this->givenStructureMarketScenario();
        $this->givenCachedStructureIn(self::AMARR_SYSTEM_ID);
        $this->givenSolarSystemInRegion(self::AMARR_SYSTEM_ID, self::DOMAIN_REGION_ID);

        $row = $this->scan(sellVenue: 'jita', structureId: self::AMARR_STRUCTURE_ID, exportCostPerM3: 300.0)[0];

        $this->assertSame([1000.0, 30.0, 1500.0], [$row['sellPrice'], $row['dailyVolume'], $row['exportCost']]);
    }

    public function testStructureVenueWithoutStructureHasNoSalePrice(): void
    {
        $this->givenStructureMarketScenario();

        $row = $this->scan(sellVenue: 'structure')[0];

        $this->assertSame([null, 30.0, 'missing_sale_price'], [$row['sellPrice'], $row['dailyVolume'], $row['unknownReason']]);
    }

    public function testSkillCheckUsesTheBestCharacterOfTheUser(): void
    {
        $this->givenModuleSoldAt(701, 500.0, 2.0);
        $this->givenModuleSoldAt(702, 600.0, 2.0);
        $this->givenModuleSoldAt(704, 650.0, 2.0);
        $this->givenProduct(10703, 703, self::REACTION, 1, [self::HAFNIUM_TYPE_ID => 10]);
        $this->givenType(703, '429', 'Composite', '4', '1.0');
        $this->jitaPrices[self::HAFNIUM_TYPE_ID] = 30.0;
        $this->jitaPrices[703] = 700.0;
        $this->requiredSkillsByActivity = [
            self::MANUFACTURING => [
                10701 => [
                    ['skillId' => self::INDUSTRY_SKILL_ID, 'level' => 5],
                    ['skillId' => self::ADVANCED_INDUSTRY_SKILL_ID, 'level' => 3],
                    ['skillId' => self::CAPITAL_SHIP_CONSTRUCTION_SKILL_ID, 'level' => 1],
                ],
                10704 => [['skillId' => self::INDUSTRY_SKILL_ID, 'level' => 4]],
            ],
            self::REACTION => [
                10703 => [['skillId' => self::REACTIONS_SKILL_ID, 'level' => 1]],
            ],
        ];
        $user = $this->userWithCharacterSkills([
            [self::INDUSTRY_SKILL_ID => 5, self::ADVANCED_INDUSTRY_SKILL_ID => 2, self::REACTIONS_SKILL_ID => 1],
            [self::INDUSTRY_SKILL_ID => 5, self::ADVANCED_INDUSTRY_SKILL_ID => 3],
        ]);

        $result = $this->scan(user: $user);

        $this->assertSame([
            701 => [false, 1],
            702 => [true, 0],
            703 => [true, 0],
            704 => [true, 0],
        ], $this->skillChecksByTypeId($result));
    }

    public function testUserWithoutCharacterIsNotReportedAsMissingSkills(): void
    {
        $this->givenModuleSoldAt(701, 500.0, 2.0);
        $this->requiredSkillsByActivity = [
            self::MANUFACTURING => [10701 => [['skillId' => self::INDUSTRY_SKILL_ID, 'level' => 5]]],
        ];

        $result = $this->scan(user: $this->userWithCharacterSkills([]));

        $this->assertSame([701 => [true, 0]], $this->skillChecksByTypeId($result));
    }

    private function givenStructureMarketScenario(): void
    {
        $this->givenModuleSoldAt(801, 1000.0, 30.0);
        $this->regionDailyVolumes = [self::DOMAIN_REGION_ID => [801 => 8.0]];
        $this->structurePrices = [self::AMARR_STRUCTURE_ID => [801 => 1200.0]];
    }

    private function givenCachedStructureIn(?int $solarSystemId): void
    {
        $structure = new CachedStructure();
        $structure->setStructureId(self::AMARR_STRUCTURE_ID);
        $structure->setName('Amarr - Production Hub');
        $structure->setSolarSystemId($solarSystemId);
        $this->cachedStructures[self::AMARR_STRUCTURE_ID] = $structure;
    }

    private function givenSolarSystemInRegion(int $solarSystemId, int $regionId): void
    {
        $solarSystem = new MapSolarSystem();
        $solarSystem->setSolarSystemId($solarSystemId);
        $solarSystem->setRegionId($regionId);
        $this->solarSystems[$solarSystemId] = $solarSystem;
    }

    private function givenCatalogOfEveryCategory(): void
    {
        $this->givenCatalogProduct(601, self::MANUFACTURING, '25', 'Frigate', '6', 410.0);
        $this->givenCatalogProduct(602, self::MANUFACTURING, '324', 'Assault Frigate', '6', 420.0);
        $this->givenCatalogProduct(603, self::MANUFACTURING, '30', 'Titan', '6', 430.0);
        $this->givenCatalogProduct(604, self::MANUFACTURING, '38', 'Shield Extender', '7', 440.0);
        $this->givenCatalogProduct(605, self::MANUFACTURING, '38', 'Shield Extender', '7', 450.0);
        $this->givenCatalogProduct(606, self::MANUFACTURING, '773', 'Rig Armor', '7', 460.0);
        $this->givenCatalogProduct(607, self::MANUFACTURING, '83', 'Projectile Ammo', '8', 470.0);
        $this->givenCatalogProduct(608, self::MANUFACTURING, '100', 'Combat Drone', '18', 480.0);
        $this->givenCatalogProduct(609, self::MANUFACTURING, '334', 'Construction Components', '17', 490.0);
        $this->givenCatalogProduct(610, self::REACTION, '429', 'Composite', '4', 500.0);
        $this->givenCatalogProduct(611, self::MANUFACTURING, '1246', 'Mobile Depot', '22', 510.0);
        $this->t2ProductIds = [602 => true, 605 => true];
        $this->factionBlueprintIds = [10601 => true, 10610 => true];
    }

    private function givenCatalogProduct(int $typeId, int $activityId, string $groupId, string $groupName, string $categoryId, float $sellPrice): void
    {
        $this->givenProduct($typeId + 10000, $typeId, $activityId, 1, [self::TRITANIUM_TYPE_ID => 10]);
        $this->givenType($typeId, $groupId, $groupName, $categoryId, '10.0');
        $this->jitaPrices[self::TRITANIUM_TYPE_ID] = 5.0;
        $this->jitaPrices[$typeId] = $sellPrice;
    }

    private function givenModuleSoldAt(int $typeId, ?float $sellPrice, float $dailyVolume): void
    {
        $this->givenProduct($typeId + 10000, $typeId, self::MANUFACTURING, 1, [self::TRITANIUM_TYPE_ID => 10]);
        $this->givenType($typeId, '38', 'Shield Extender', '7', '5.0');
        $this->jitaPrices[self::TRITANIUM_TYPE_ID] = 5.0;
        $this->jitaPrices[$typeId] = $sellPrice;
        $this->jitaDailyVolumes[$typeId] = $dailyVolume;
    }

    private function givenProductWithoutMetadataSoldAt(int $typeId, float $sellPrice): void
    {
        $this->givenProduct($typeId + 10000, $typeId, self::MANUFACTURING, 1, [self::TRITANIUM_TYPE_ID => 10]);
        $this->jitaPrices[$typeId] = $sellPrice;
    }

    private function givenProduct(int $blueprintTypeId, int $productTypeId, int $activityId, int $outputPerRun, array $baseQuantities): void
    {
        $this->products[] = [
            'blueprintTypeId' => $blueprintTypeId,
            'productTypeId' => $productTypeId,
            'outputPerRun' => $outputPerRun,
            'activityId' => $activityId,
        ];
        foreach ($baseQuantities as $materialTypeId => $quantity) {
            $this->materialsByActivity[$activityId][$blueprintTypeId][] = ['materialTypeId' => $materialTypeId, 'quantity' => $quantity];
        }
    }

    private function givenType(int $typeId, string $groupId, string $groupName, string $categoryId, ?string $volume): void
    {
        $this->typeRows[$typeId] = ['group_id' => $groupId, 'group_name' => $groupName, 'category_id' => $categoryId, 'volume' => $volume];
    }

    private function userWithCharacterSkills(array $skillLevelsByCharacter): User
    {
        $characters = [];
        foreach ($skillLevelsByCharacter as $skillLevels) {
            $character = new Character();
            $skills = [];
            foreach ($skillLevels as $skillId => $level) {
                $skill = new CachedCharacterSkill();
                $skill->setSkillId($skillId);
                $skill->setLevel($level);
                $skills[$skillId] = $skill;
            }
            $this->skillsByCharacter[spl_object_id($character)] = $skills;
            $characters[] = $character;
        }

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::fromString(self::USER_ID));
        $user->method('getCharacters')->willReturn(new ArrayCollection($characters));

        return $user;
    }

    private function labelsAndFactionFlagsByTypeId(array $result): array
    {
        $labelsAndFlags = [];
        foreach ($result as $row) {
            $labelsAndFlags[$row['typeId']] = [$row['categoryLabel'], $row['isFactionBlueprint']];
        }
        ksort($labelsAndFlags);

        return $labelsAndFlags;
    }

    private function skillChecksByTypeId(array $result): array
    {
        $skillChecks = [];
        foreach ($result as $row) {
            $skillChecks[$row['typeId']] = [$row['hasAllSkills'], $row['missingSkillCount']];
        }
        ksort($skillChecks);

        return $skillChecks;
    }

    private function scan(
        string $category = 'all',
        ?float $minMarginPercent = null,
        ?float $minDailyVolume = null,
        string $sellVenue = 'jita',
        ?int $structureId = null,
        float $brokerFeeRate = 0.0,
        float $salesTaxRate = 0.0,
        float $exportCostPerM3 = 0.0,
        ?User $user = null,
        int $reactionSolarSystemId = self::SOLAR_SYSTEM_ID,
    ): array {
        return $this->scanner()->scan(
            $category,
            $minMarginPercent,
            $minDailyVolume,
            $sellVenue,
            $structureId,
            self::SOLAR_SYSTEM_ID,
            $brokerFeeRate,
            $salesTaxRate,
            $exportCostPerM3,
            $user,
            $reactionSolarSystemId,
        );
    }

    private function scanner(): BatchProfitScannerService
    {
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findAllManufacturableProducts')->willReturn($this->products);
        $productRepository->method('findFactionBlueprintIds')->willReturnCallback(
            fn (array $blueprintTypeIds): array => array_intersect_key($this->factionBlueprintIds, array_flip($blueprintTypeIds)),
        );

        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findMaterialsForBlueprints')->willReturnCallback(
            function (array $blueprintTypeIds, array $activityIds): array {
                $materials = [];
                foreach ($activityIds as $activityId) {
                    $materials += array_intersect_key($this->materialsByActivity[$activityId] ?? [], array_flip($blueprintTypeIds));
                }

                return $materials;
            },
        );

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrices')->willReturnCallback(
            fn (array $typeIds): array => $this->onlyRequested($this->jitaPrices, $typeIds),
        );
        $jitaMarketService->method('getCachedDailyVolumes')->willReturnCallback(
            fn (array $typeIds): array => $this->onlyRequested($this->jitaDailyVolumes, $typeIds),
        );
        $jitaMarketService->method('getAverageDailyVolumesForRegion')->willReturnCallback(
            fn (int $regionId, array $typeIds): array => $this->onlyRequested($this->regionDailyVolumes[$regionId] ?? [], $typeIds),
        );

        $structureMarketService = $this->createStub(StructureMarketService::class);
        $structureMarketService->method('getLowestSellPrices')->willReturnCallback(
            fn (int $structureId, array $typeIds): array => $this->onlyRequested($this->structurePrices[$structureId] ?? [], $typeIds),
        );

        $esiCostIndexService = $this->createStub(EsiCostIndexService::class);
        $esiCostIndexService->method('getAdjustedPrices')->willReturnCallback(
            fn (array $typeIds): array => $this->onlyRequested($this->adjustedPrices, $typeIds),
        );
        $esiCostIndexService->method('getCostIndex')->willReturnCallback(
            fn (int $solarSystemId, string $activity): ?float => match ($solarSystemId) {
                self::SOLAR_SYSTEM_ID => $this->costIndices[$activity],
                self::REACTION_SOLAR_SYSTEM_ID => $this->reactionSystemCostIndices[$activity],
                default => null,
            },
        );

        $typeNameResolver = $this->createStub(TypeNameResolver::class);
        $typeNameResolver->method('findNames')->willReturnCallback(
            static fn (array $typeIds): array => array_combine($typeIds, array_map(static fn (int $typeId): string => 'Type ' . $typeId, $typeIds)),
        );

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            fn (string $sql, array $typeIds): array => str_contains($sql, 'group_id')
                ? $this->typeRowsFor($typeIds)
                : $this->materialVolumeRowsFor($typeIds),
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $cachedStructureRepository = $this->createStub(CachedStructureRepository::class);
        $cachedStructureRepository->method('findByStructureId')->willReturnCallback(
            fn (int $structureId): ?CachedStructure => $this->cachedStructures[$structureId] ?? null,
        );

        $solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        $solarSystemRepository->method('find')->willReturnCallback(
            fn (mixed $solarSystemId): ?MapSolarSystem => $this->solarSystems[$solarSystemId] ?? null,
        );

        $inventionService = $this->createStub(InventionService::class);
        $inventionService->method('identifyT2Products')->willReturnCallback(
            fn (array $products): array => array_intersect_key($this->t2ProductIds, array_flip(array_column($products, 'productTypeId'))),
        );

        $activitySkillRepository = $this->createStub(IndustryActivitySkillRepository::class);
        $activitySkillRepository->method('findSkillsForBlueprints')->willReturnCallback(
            fn (array $blueprintTypeIds, int $activityId): array => array_intersect_key(
                $this->requiredSkillsByActivity[$activityId] ?? [],
                array_flip($blueprintTypeIds),
            ),
        );

        $characterSkillRepository = $this->createStub(CachedCharacterSkillRepository::class);
        $characterSkillRepository->method('findAllSkillsForCharacter')->willReturnCallback(
            fn (Character $character): array => $this->skillsByCharacter[spl_object_id($character)] ?? [],
        );

        return new BatchProfitScannerService(
            $productRepository,
            $materialRepository,
            $jitaMarketService,
            $structureMarketService,
            $esiCostIndexService,
            $typeNameResolver,
            $entityManager,
            $cachedStructureRepository,
            $solarSystemRepository,
            $inventionService,
            $this->createStub(IndustryBpcPriceRepository::class),
            $activitySkillRepository,
            $characterSkillRepository,
            new JobInstallCostEstimator(),
            new FacilityTaxResolver(new InMemoryUserProductionStructures($this->structuresByUserId)),
        );
    }

    private function onlyRequested(array $valuesByTypeId, array $typeIds): array
    {
        $requested = [];
        foreach ($typeIds as $typeId) {
            if (is_int($typeId) && array_key_exists($typeId, $valuesByTypeId)) {
                $requested[$typeId] = $valuesByTypeId[$typeId];
            }
        }

        return $requested;
    }

    private function typeRowsFor(array $typeIds): array
    {
        $rows = [];
        foreach ($this->onlyRequested($this->typeRows, $typeIds) as $typeId => $row) {
            $rows[] = ['type_id' => (string) $typeId] + $row;
        }

        return $rows;
    }

    private function materialVolumeRowsFor(array $typeIds): array
    {
        $rows = [];
        foreach ($this->onlyRequested($this->materialVolumeRows, $typeIds) as $typeId => $volume) {
            $rows[] = ['type_id' => (string) $typeId, 'volume' => $volume];
        }

        return $rows;
    }
}
