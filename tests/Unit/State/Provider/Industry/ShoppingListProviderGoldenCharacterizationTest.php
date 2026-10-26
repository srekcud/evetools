<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\ShoppingListMaterialResource;
use App\ApiResource\Industry\ShoppingListResource;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\IndustryProject;
use App\Entity\IndustryStepPurchase;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\MarketService;
use App\Service\Industry\IndustryShoppingListBuilder;
use App\Service\JitaMarketService;
use App\State\Provider\Industry\ShoppingListProvider;
use App\Tests\Unit\Service\Industry\BuildsShoppingListOnGoldenSde;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Characterization of the priced shopping list of one run of Nitrogen Fuel Block (ME 10, no structure), with the real
 * IndustryShoppingListBuilder on the goldens, before buildProductionTree() is replaced (docs/specs/industry-engine.md, §7 rank 5).
 * Stubbed boundaries only: security, project and SDE repositories, market prices (structure and Jita).
 */
#[CoversClass(ShoppingListProvider::class)]
#[CoversClass(IndustryShoppingListBuilder::class)]
final class ShoppingListProviderGoldenCharacterizationTest extends TestCase
{
    use BuildsShoppingListOnGoldenSde;

    private const string PROJECT_ID = '0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int NITROGEN_ISOTOPES = 17888;
    private const int COOLANT = 9832;
    private const int ENRICHED_URANIUM = 44;
    private const int HEAVY_WATER = 16272;
    private const int LIQUID_OZONE = 16273;
    private const int MECHANICAL_PARTS = 3689;
    private const int OXYGEN = 3683;
    private const int ROBOTICS = 9848;
    private const int STRONTIUM_CLATHRATES = 16275;
    private const int DATACORE = 20410;
    private const int DEFAULT_MARKET_STRUCTURE_ID = 1_035_466_617_946;
    private const int PRICED_MARKET_STRUCTURE_ID = 1_000_000_000_001;
    private const int PREFERRED_MARKET_STRUCTURE_ID = 1_000_000_000_002;
    private const int REQUESTED_MARKET_STRUCTURE_ID = 1_000_000_000_003;
    /** Types of one run of Nitrogen Fuel Block at ME 10, in the order of the shopping list (by type name). */
    private const array LISTED_TYPE_IDS = [9832, 44, 16272, 16273, 3689, 17888, 3683, 9848, 16275];
    private const float TRANSPORT_COST_PER_M3 = 1200.0;
    private const int ISOTOPES_QUANTITY = 405;
    private const int COOLANT_QUANTITY = 9;
    private const float ISOTOPES_VOLUME = 0.5;
    private const float COOLANT_VOLUME = 1.5;

    private User $pilot;
    private IndustryProject $project;

    /** @var array<int, float> */
    private array $jitaPrices = [];

    /** @var array<int, float> */
    private array $structurePrices = [];

    private bool $marketUnavailable = false;

    /** @var array<int, ?float> */
    private array $volumes = [
        self::COOLANT => self::COOLANT_VOLUME,
        self::ENRICHED_URANIUM => 1.25,
        self::HEAVY_WATER => 0.375,
        self::LIQUID_OZONE => 0.25,
        self::MECHANICAL_PARTS => 1.75,
        self::NITROGEN_ISOTOPES => self::ISOTOPES_VOLUME,
        self::OXYGEN => 0.125,
        self::ROBOTICS => 3.0,
        self::STRONTIUM_CLATHRATES => 2.5,
        self::DATACORE => 0.0625,
    ];

    private ?Request $request = null;

    /** @var list<array{typeIds: list<int>, structureId: ?int, token: ?EveToken}> */
    private array $comparePricesCalls = [];

    /** @var list<array<string, mixed>> */
    private array $volumeCriteria = [];

    /** @var list<array<int, int>> */
    private array $weightedPriceQuantities = [];

    protected function setUp(): void
    {
        $this->pilot = new User();
        $this->project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);
        self::addStep($this->project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing')
            ->addPurchase((new IndustryStepPurchase())->setTypeId(self::NITROGEN_ISOTOPES)->setQuantity(100));
    }

    #[Group('issue-8')]
    public function testPricedShoppingListCurrentlyLeavesAMaterialWithoutPriceOutOfTheTotalsIssue8(): void
    {
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0, self::COOLANT => 100.0];
        $this->structurePrices = [self::NITROGEN_ISOTOPES => 1200.0, self::HEAVY_WATER => 200.0];

        $shoppingList = $this->provideShoppingList();

        $robotics = $this->materialOf($shoppingList, self::ROBOTICS);
        self::assertSame(1, $robotics->quantity);
        self::assertNull($robotics->jitaTotal);
        self::assertNull($robotics->structureTotal);
        self::assertNull($robotics->bestLocation);
        self::assertNull($robotics->bestPrice);
        self::assertSame(203400.0, $shoppingList->totals->jita);
        self::assertSame(516600.0, $shoppingList->totals->structure);
        self::assertSame(493200.0, $shoppingList->totals->best);
        self::assertSame(0.0, $shoppingList->totals->savingsVsJitaWithImport);
        self::assertSame(40500.0, $shoppingList->totals->savingsVsStructure);
    }

    public function testPricedShoppingListComparesJitaWithImportToTheStructureForEachMaterial(): void
    {
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0, self::COOLANT => 100.0];
        $this->structurePrices = [self::NITROGEN_ISOTOPES => 1200.0, self::HEAVY_WATER => 200.0];

        $shoppingList = $this->provideShoppingList();

        $isotopes = $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES);
        self::assertSame(405, $isotopes->quantity);
        self::assertSame(0.5, $isotopes->volume);
        self::assertSame(202.5, $isotopes->totalVolume);
        self::assertSame(500.0, $isotopes->jitaUnitPrice);
        self::assertSame(202500.0, $isotopes->jitaTotal);
        self::assertSame(243000.0, $isotopes->importCost);
        self::assertSame(445500.0, $isotopes->jitaWithImport);
        self::assertSame(1200.0, $isotopes->structureUnitPrice);
        self::assertSame(486000.0, $isotopes->structureTotal);
        self::assertSame('jita', $isotopes->bestLocation);
        self::assertSame(445500.0, $isotopes->bestPrice);
        self::assertSame(40500.0, $isotopes->savings);
        self::assertSame(100, $isotopes->purchasedQuantity);
        self::assertSame(0, $isotopes->extraQuantity);
        self::assertSame(510.0, $isotopes->jitaWeightedUnitPrice);
        self::assertSame(206550.0, $isotopes->jitaWeightedTotal);
        self::assertSame(0.75, $isotopes->jitaCoverage);

        $heavyWater = $this->materialOf($shoppingList, self::HEAVY_WATER);
        self::assertSame('structure', $heavyWater->bestLocation);
        self::assertSame(30600.0, $heavyWater->bestPrice);
        self::assertNull($heavyWater->savings);

        $coolant = $this->materialOf($shoppingList, self::COOLANT);
        self::assertSame('jita', $coolant->bestLocation);
        self::assertSame(17100.0, $coolant->bestPrice);
        self::assertSame(16200.0, $coolant->importCost);
        self::assertSame(0, $coolant->purchasedQuantity);

        self::assertSame(259200.0, $shoppingList->totals->import);
        self::assertSame(462600.0, $shoppingList->totals->jitaWithImport);
        self::assertSame(414.625, $shoppingList->totals->volume);
        self::assertSame(40500.0, $shoppingList->totals->savingsVsStructure);
        self::assertSame(self::TRANSPORT_COST_PER_M3, $shoppingList->transportCostPerM3);
        self::assertNull($shoppingList->priceError);
        self::assertSame(self::PRICED_MARKET_STRUCTURE_ID, $shoppingList->structureId);
        self::assertSame('Priced market', $shoppingList->structureName);
        self::assertTrue($shoppingList->structureAccessible);
        self::assertTrue($shoppingList->structureFromCache);
    }

    public function testPricedShoppingListAddsTheImportCostOfEveryMaterialBoughtInJita(): void
    {
        $this->volumes[self::COOLANT] = 0.25;
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0, self::COOLANT => 100.0];

        $shoppingList = $this->provideShoppingList();

        self::assertSame(2700.0, $this->materialOf($shoppingList, self::COOLANT)->importCost);
        self::assertSame(245700.0, $shoppingList->totals->import);
        self::assertSame(403.375, $shoppingList->totals->volume);
    }

    // Jita + import (405 × 500 + 202.5 m³ × 1200 = 445500) égal au prix structure (405 × 1100) : Jita l'emporte.
    public function testPricedShoppingListPrefersJitaWhenJitaWithImportEqualsTheStructurePrice(): void
    {
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0];
        $this->structurePrices = [self::NITROGEN_ISOTOPES => 1100.0];

        $isotopes = $this->materialOf($this->provideShoppingList(), self::NITROGEN_ISOTOPES);

        self::assertSame(445500.0, $isotopes->structureTotal);
        self::assertSame('jita', $isotopes->bestLocation);
        self::assertSame(445500.0, $isotopes->bestPrice);
        self::assertSame(0.0, $isotopes->savings);
    }

    public function testPricedShoppingListWithoutStructurePriceHasNoSavingVersusTheStructure(): void
    {
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0];

        $shoppingList = $this->provideShoppingList();

        self::assertSame(0.0, $shoppingList->totals->structure);
        self::assertSame(0.0, $shoppingList->totals->savingsVsStructure);
        self::assertSame(0.0, $shoppingList->totals->savingsVsJitaWithImport);
    }

    public function testPricedShoppingListWithoutJitaPriceHasNoSavingVersusJita(): void
    {
        $this->structurePrices = [self::NITROGEN_ISOTOPES => 1200.0];

        $shoppingList = $this->provideShoppingList();

        self::assertSame(0.0, $shoppingList->totals->jitaWithImport);
        self::assertSame(0.0, $shoppingList->totals->savingsVsJitaWithImport);
        self::assertSame(0.0, $shoppingList->totals->savingsVsStructure);
    }

    #[Group('issue-6')]
    public function testPricedShoppingListOfAStepAssignedToAStationCurrentlyCarriesTheExtraQuantityIssue6(): void
    {
        $this->pilotStructures = [self::structure($this->pilot, 'raitaru')];
        $this->project->getSteps()->first()->setStructureConfig(self::structure($this->pilot, 'station'));
        $this->project->setInventionMaterials([['typeId' => self::DATACORE, 'typeName' => 'Datacore - Rocket Science', 'quantity' => 8]]);

        $shoppingList = $this->provideShoppingList();

        $isotopes = $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES);
        self::assertSame(401, $isotopes->quantity);
        self::assertSame(4, $isotopes->extraQuantity);
        self::assertSame(0, $this->materialOf($shoppingList, self::DATACORE)->extraQuantity);
    }

    public function testProvideRejectsAProjectOfAnotherPilot(): void
    {
        $this->project = self::projectOf(new User(), self::NITROGEN_FUEL_BLOCK, 1, 10);

        $this->expectException(NotFoundHttpException::class);

        $this->provideShoppingList();
    }

    public function testProvideAsksTheMarketForTheListedTypesAtThePreferredStructureWithTheFirstAvailableToken(): void
    {
        $this->pilot->setPreferredMarketStructureId(self::PREFERRED_MARKET_STRUCTURE_ID);
        $this->pilot->addCharacter(new Character());
        $token = new EveToken();
        $this->pilot->addCharacter((new Character())->setEveToken($token));
        $this->pilot->addCharacter((new Character())->setEveToken(new EveToken()));

        $this->provideShoppingList();

        self::assertSame(
            [['typeIds' => self::LISTED_TYPE_IDS, 'structureId' => self::PREFERRED_MARKET_STRUCTURE_ID, 'token' => $token]],
            $this->comparePricesCalls,
        );
        self::assertSame([['typeId' => self::LISTED_TYPE_IDS]], $this->volumeCriteria);
    }

    public function testProvideAsksTheWeightedJitaPricesForTheQuantityOfEveryListedType(): void
    {
        $this->project->setInventionMaterials([['typeId' => self::DATACORE, 'typeName' => 'Datacore - Rocket Science', 'quantity' => 8]]);

        $this->provideShoppingList();

        self::assertSame(
            [[9832 => 9, 44 => 4, 16272 => 153, 16273 => 315, 3689 => 4, 17888 => 405, 3683 => 20, 9848 => 1, 16275 => 18, self::DATACORE => 8]],
            $this->weightedPriceQuantities,
        );
    }

    public function testProvideReadsTheMarketStructureOfTheRequestAndKeepsTheDefaultTransportCost(): void
    {
        $this->pilot->setPreferredMarketStructureId(self::PREFERRED_MARKET_STRUCTURE_ID);
        $this->request = new Request(['structureId' => (string) self::REQUESTED_MARKET_STRUCTURE_ID]);

        $shoppingList = $this->provideShoppingList();

        self::assertSame(self::REQUESTED_MARKET_STRUCTURE_ID, $this->comparePricesCalls[0]['structureId']);
        self::assertSame(self::TRANSPORT_COST_PER_M3, $shoppingList->transportCostPerM3);
    }

    public function testProvideReadsTheTransportCostOfTheRequest(): void
    {
        $this->request = new Request(['transportCost' => '500']);
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0];

        $shoppingList = $this->provideShoppingList();

        self::assertSame(500.0, $shoppingList->transportCostPerM3);
        // 202.5 m³ × 500
        self::assertSame(101250.0, $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES)->importCost);
    }

    public function testPricedShoppingListSavingsLeaveOutTheMaterialsPricedOnlyInJitaOrOnlyInTheStructure(): void
    {
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0, self::COOLANT => 100.0];
        $this->structurePrices = [self::NITROGEN_ISOTOPES => 1200.0, self::HEAVY_WATER => 200.0];
        $isotopesJitaWithImport = 445500.0;
        $isotopesStructureTotal = 486000.0;
        $bestPriceOfEveryPricedMaterial = 493200.0;

        $shoppingList = $this->provideShoppingList();

        $isotopes = $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES);
        self::assertSame($isotopesJitaWithImport, $isotopes->jitaWithImport);
        self::assertSame($isotopesStructureTotal, $isotopes->structureTotal);
        self::assertSame($isotopesJitaWithImport, $isotopes->bestPrice);
        self::assertSame(0.0, $shoppingList->totals->savingsVsJitaWithImport);
        self::assertSame($isotopesStructureTotal - $isotopesJitaWithImport, $shoppingList->totals->savingsVsStructure);
        self::assertSame(40500.0, $shoppingList->totals->savingsVsStructure);
        self::assertSame($bestPriceOfEveryPricedMaterial, $shoppingList->totals->best);
    }

    public function testPricedShoppingListSavesVersusJitaWhenTheStructureIsCheaperForTheMaterialPricedInBoth(): void
    {
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0, self::COOLANT => 100.0];
        $this->structurePrices = [self::NITROGEN_ISOTOPES => 1000.0, self::HEAVY_WATER => 200.0];
        $isotopesJitaWithImport = 445500.0;
        $isotopesStructureTotal = 405000.0;

        $shoppingList = $this->provideShoppingList();

        $isotopes = $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES);
        self::assertSame('structure', $isotopes->bestLocation);
        self::assertSame($isotopesStructureTotal, $isotopes->bestPrice);
        self::assertSame($isotopesJitaWithImport - $isotopesStructureTotal, $shoppingList->totals->savingsVsJitaWithImport);
        self::assertSame(40500.0, $shoppingList->totals->savingsVsJitaWithImport);
        self::assertSame(0.0, $shoppingList->totals->savingsVsStructure);
    }

    public function testPricedShoppingListSumsTheSavingsOfEveryMaterialPricedInBothWhateverItsBestLocation(): void
    {
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0, self::COOLANT => 100.0];
        $this->structurePrices = [self::NITROGEN_ISOTOPES => 1200.0, self::COOLANT => 1500.0];
        $isotopesJitaWithImport = 445500.0;
        $isotopesStructureTotal = 486000.0;
        $coolantJitaWithImport = 17100.0;
        $coolantStructureTotal = 13500.0;

        $shoppingList = $this->provideShoppingList();

        self::assertSame('jita', $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES)->bestLocation);
        $coolant = $this->materialOf($shoppingList, self::COOLANT);
        self::assertSame($coolantJitaWithImport, $coolant->jitaWithImport);
        self::assertSame($coolantStructureTotal, $coolant->structureTotal);
        self::assertSame('structure', $coolant->bestLocation);
        self::assertSame($coolantJitaWithImport - $coolantStructureTotal, $shoppingList->totals->savingsVsJitaWithImport);
        self::assertSame(3600.0, $shoppingList->totals->savingsVsJitaWithImport);
        self::assertSame($isotopesStructureTotal - $isotopesJitaWithImport, $shoppingList->totals->savingsVsStructure);
        self::assertSame(40500.0, $shoppingList->totals->savingsVsStructure);
    }

    public function testPricedShoppingListLeavesAMaterialWithAnUnknownVolumeOutOfTheSavingsVersusTheStructure(): void
    {
        $this->volumes[self::NITROGEN_ISOTOPES] = null;
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0, self::COOLANT => 100.0];
        $this->structurePrices = [self::NITROGEN_ISOTOPES => 1200.0, self::HEAVY_WATER => 200.0];

        $shoppingList = $this->provideShoppingList();

        self::assertNull($this->materialOf($shoppingList, self::NITROGEN_ISOTOPES)->jitaWithImport);
        self::assertNull($shoppingList->totals->savingsVsJitaWithImport);
        self::assertSame(0.0, $shoppingList->totals->savingsVsStructure);
    }

    public function testPricedShoppingListHasNoSavingWhenJitaWithImportEqualsTheStructurePrice(): void
    {
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0];
        $this->structurePrices = [self::NITROGEN_ISOTOPES => 1100.0];

        $shoppingList = $this->provideShoppingList();

        $isotopes = $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES);
        self::assertSame($isotopes->structureTotal, $isotopes->jitaWithImport);
        self::assertSame('jita', $isotopes->bestLocation);
        self::assertSame(0.0, $shoppingList->totals->savingsVsJitaWithImport);
        self::assertSame(0.0, $shoppingList->totals->savingsVsStructure);
    }

    public function testShoppingListWithoutMarketHasNoSaving(): void
    {
        $this->marketUnavailable = true;

        $shoppingList = $this->provideShoppingList();

        self::assertSame('Impossible de récupérer les prix du marché. Réessayez plus tard.', $shoppingList->priceError);
        self::assertSame(0.0, $shoppingList->totals->savingsVsJitaWithImport);
        self::assertSame(0.0, $shoppingList->totals->savingsVsStructure);
    }

    public function testPricedShoppingListAppendsTheInventionMaterialsOfTheProject(): void
    {
        $this->project->setInventionMaterials([['typeId' => self::DATACORE, 'typeName' => 'Datacore - Rocket Science', 'quantity' => 8]]);

        $shoppingList = $this->provideShoppingList();

        self::assertCount(10, $shoppingList->materials);
        $datacore = $shoppingList->materials[9];
        self::assertSame(self::DATACORE, $datacore->typeId);
        self::assertSame(8, $datacore->quantity);
        self::assertTrue($datacore->isInventionMaterial);
        self::assertFalse($this->materialOf($shoppingList, self::ROBOTICS)->isInventionMaterial);
    }

    #[Group('issue-8')]
    public function testPricedShoppingListWithoutMarketKeepsTheQuantitiesAndCurrentlyTotalsZeroIssue8(): void
    {
        $this->marketUnavailable = true;

        $shoppingList = $this->provideShoppingList();

        self::assertSame('Impossible de récupérer les prix du marché. Réessayez plus tard.', $shoppingList->priceError);
        self::assertSame(405, $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES)->quantity);
        self::assertNull($this->materialOf($shoppingList, self::NITROGEN_ISOTOPES)->bestPrice);
        self::assertSame(0.0, $shoppingList->totals->best);
        self::assertSame(0.0, $shoppingList->totals->jita);
        self::assertSame(414.625, $shoppingList->totals->volume);
        self::assertSame(self::DEFAULT_MARKET_STRUCTURE_ID, $shoppingList->structureId);
        self::assertFalse($shoppingList->structureAccessible);
    }

    public function testPricedShoppingListAddsTheImportCostOfAMaterialWithAKnownSdeVolume(): void
    {
        $isotopesJitaUnitPrice = 500.0;
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => $isotopesJitaUnitPrice];
        $isotopesTotalVolume = self::ISOTOPES_QUANTITY * self::ISOTOPES_VOLUME;
        $isotopesImportCost = $isotopesTotalVolume * self::TRANSPORT_COST_PER_M3;
        $isotopesJitaWithImport = self::ISOTOPES_QUANTITY * $isotopesJitaUnitPrice + $isotopesImportCost;

        $shoppingList = $this->provideShoppingList();

        $isotopes = $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES);
        self::assertSame(0.5, $isotopes->volume);
        self::assertSame(202.5, $isotopesTotalVolume);
        self::assertSame($isotopesTotalVolume, $isotopes->totalVolume);
        self::assertSame(243000.0, $isotopesImportCost);
        self::assertSame($isotopesImportCost, $isotopes->importCost);
        self::assertSame(445500.0, $isotopesJitaWithImport);
        self::assertSame($isotopesJitaWithImport, $isotopes->jitaWithImport);
        self::assertSame('jita', $isotopes->bestLocation);
        self::assertSame($isotopesJitaWithImport, $isotopes->bestPrice);
        self::assertSame([], $shoppingList->missingVolumeTypeIds);
    }

    public function testPricedShoppingListLeavesTheImportOfAMaterialWithANullSdeVolumeUnknown(): void
    {
        $this->volumes[self::NITROGEN_ISOTOPES] = null;
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0];

        $this->assertIsotopesImportIsUnknownWithAJitaPriceOfFiveHundredOnly($this->provideShoppingList());
    }

    public function testPricedShoppingListLeavesTheImportOfAMaterialAbsentFromTheSdeUnknown(): void
    {
        unset($this->volumes[self::NITROGEN_ISOTOPES]);
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0];

        $this->assertIsotopesImportIsUnknownWithAJitaPriceOfFiveHundredOnly($this->provideShoppingList());
    }

    public function testPricedShoppingListBuysAMaterialWithoutSdeVolumeInTheStructureWithoutSaving(): void
    {
        $this->volumes[self::NITROGEN_ISOTOPES] = null;
        $isotopesStructureUnitPrice = 450.0;
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0];
        $this->structurePrices = [self::NITROGEN_ISOTOPES => $isotopesStructureUnitPrice];
        $isotopesStructureTotal = self::ISOTOPES_QUANTITY * $isotopesStructureUnitPrice;

        $shoppingList = $this->provideShoppingList();

        $isotopes = $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES);
        self::assertSame(182250.0, $isotopesStructureTotal);
        self::assertSame($isotopesStructureTotal, $isotopes->structureTotal);
        self::assertSame('structure', $isotopes->bestLocation);
        self::assertSame($isotopesStructureTotal, $isotopes->bestPrice);
        self::assertNull($isotopes->savings);
        self::assertSame($isotopesStructureTotal, $shoppingList->totals->best);
        self::assertSame($isotopesStructureTotal, $shoppingList->totals->structure);
        self::assertSame(0.0, $shoppingList->totals->savingsVsStructure);
        self::assertSame([self::NITROGEN_ISOTOPES], $shoppingList->missingVolumeTypeIds);
    }

    public function testPricedShoppingListNeverPrefersJitaWithoutImportForAMaterialWithoutSdeVolume(): void
    {
        $this->volumes[self::NITROGEN_ISOTOPES] = null;
        $isotopesJitaUnitPrice = 400.0;
        $isotopesStructureUnitPrice = 450.0;
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => $isotopesJitaUnitPrice];
        $this->structurePrices = [self::NITROGEN_ISOTOPES => $isotopesStructureUnitPrice];
        $isotopesJitaTotalCheaperThanStructure = self::ISOTOPES_QUANTITY * $isotopesJitaUnitPrice;
        $isotopesStructureTotal = self::ISOTOPES_QUANTITY * $isotopesStructureUnitPrice;

        $isotopes = $this->materialOf($this->provideShoppingList(), self::NITROGEN_ISOTOPES);

        self::assertSame(162000.0, $isotopesJitaTotalCheaperThanStructure);
        self::assertSame($isotopesJitaTotalCheaperThanStructure, $isotopes->jitaTotal);
        self::assertNull($isotopes->jitaWithImport);
        self::assertSame('structure', $isotopes->bestLocation);
        self::assertSame(182250.0, $isotopesStructureTotal);
        self::assertSame($isotopesStructureTotal, $isotopes->bestPrice);
        self::assertNull($isotopes->savings);
    }

    public function testPricedShoppingListKeepsTheImportOfAMaterialWithAKnownVolumeWhenAnotherVolumeIsUnknown(): void
    {
        $this->volumes[self::NITROGEN_ISOTOPES] = null;
        $isotopesJitaUnitPrice = 500.0;
        $coolantJitaUnitPrice = 100.0;
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => $isotopesJitaUnitPrice, self::COOLANT => $coolantJitaUnitPrice];
        $coolantTotalVolume = self::COOLANT_QUANTITY * self::COOLANT_VOLUME;
        $coolantImportCost = $coolantTotalVolume * self::TRANSPORT_COST_PER_M3;
        $coolantJitaWithImport = self::COOLANT_QUANTITY * $coolantJitaUnitPrice + $coolantImportCost;
        $jitaTotalOfIsotopesAndCoolant = self::ISOTOPES_QUANTITY * $isotopesJitaUnitPrice + self::COOLANT_QUANTITY * $coolantJitaUnitPrice;

        $shoppingList = $this->provideShoppingList();

        $coolant = $this->materialOf($shoppingList, self::COOLANT);
        self::assertSame(1.5, $coolant->volume);
        self::assertSame(13.5, $coolantTotalVolume);
        self::assertSame($coolantTotalVolume, $coolant->totalVolume);
        self::assertSame(16200.0, $coolantImportCost);
        self::assertSame($coolantImportCost, $coolant->importCost);
        self::assertSame(17100.0, $coolantJitaWithImport);
        self::assertSame($coolantJitaWithImport, $coolant->jitaWithImport);
        self::assertSame('jita', $coolant->bestLocation);
        self::assertSame($coolantJitaWithImport, $coolant->bestPrice);
        self::assertNull($shoppingList->totals->volume);
        self::assertNull($shoppingList->totals->import);
        self::assertNull($shoppingList->totals->jitaWithImport);
        self::assertNull($shoppingList->totals->savingsVsJitaWithImport);
        self::assertSame(203400.0, $jitaTotalOfIsotopesAndCoolant);
        self::assertSame($jitaTotalOfIsotopesAndCoolant, $shoppingList->totals->jita);
        self::assertSame($coolantJitaWithImport, $shoppingList->totals->best);
        self::assertSame([self::NITROGEN_ISOTOPES], $shoppingList->missingVolumeTypeIds);
    }

    public function testPricedShoppingListCountsAZeroSdeVolumeAsAKnownVolume(): void
    {
        $this->volumes[self::NITROGEN_ISOTOPES] = 0.0;
        $isotopesJitaUnitPrice = 500.0;
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => $isotopesJitaUnitPrice];
        $isotopesJitaTotalWithoutImport = self::ISOTOPES_QUANTITY * $isotopesJitaUnitPrice;

        $shoppingList = $this->provideShoppingList();

        $isotopes = $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES);
        self::assertSame(0.0, $isotopes->volume);
        self::assertSame(0.0, $isotopes->totalVolume);
        self::assertSame(0.0, $isotopes->importCost);
        self::assertSame(202500.0, $isotopesJitaTotalWithoutImport);
        self::assertSame($isotopesJitaTotalWithoutImport, $isotopes->jitaWithImport);
        self::assertSame([], $shoppingList->missingVolumeTypeIds);
    }

    public function testPricedShoppingListReportsATypeWithoutSdeVolumeOnceWhenItIsBothAManufacturingAndAnInventionMaterial(): void
    {
        $this->volumes[self::NITROGEN_ISOTOPES] = null;
        $isotopesJitaUnitPrice = 500.0;
        $isotopesInventionQuantity = 12;
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => $isotopesJitaUnitPrice];
        $this->project->setInventionMaterials([['typeId' => self::NITROGEN_ISOTOPES, 'typeName' => 'Nitrogen Isotopes', 'quantity' => $isotopesInventionQuantity]]);

        $shoppingList = $this->provideShoppingList();

        $isotopesLines = array_values(array_filter(
            $shoppingList->materials,
            static fn (ShoppingListMaterialResource $material): bool => $material->typeId === self::NITROGEN_ISOTOPES,
        ));
        self::assertCount(2, $isotopesLines);
        [$manufacturingLine, $inventionLine] = $isotopesLines;
        self::assertFalse($manufacturingLine->isInventionMaterial);
        self::assertTrue($inventionLine->isInventionMaterial);
        self::assertSame(self::ISOTOPES_QUANTITY * $isotopesJitaUnitPrice, $manufacturingLine->jitaTotal);
        self::assertSame(6000.0, $isotopesInventionQuantity * $isotopesJitaUnitPrice);
        self::assertSame($isotopesInventionQuantity * $isotopesJitaUnitPrice, $inventionLine->jitaTotal);
        foreach ($isotopesLines as $isotopesLine) {
            self::assertNull($isotopesLine->volume);
            self::assertNull($isotopesLine->totalVolume);
            self::assertNull($isotopesLine->importCost);
            self::assertNull($isotopesLine->jitaWithImport);
            self::assertNull($isotopesLine->bestPrice);
        }
        self::assertSame([self::NITROGEN_ISOTOPES], $shoppingList->missingVolumeTypeIds);
    }

    public function testPricedShoppingListReportsEveryTypeWithoutSdeVolumeInAscendingTypeIdOrder(): void
    {
        $this->volumes[self::NITROGEN_ISOTOPES] = null;
        $this->volumes[self::ENRICHED_URANIUM] = null;
        unset($this->volumes[self::ROBOTICS]);
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0, self::ENRICHED_URANIUM => 3000.0, self::ROBOTICS => 80000.0];

        $shoppingList = $this->provideShoppingList();

        self::assertSame([self::ENRICHED_URANIUM, self::ROBOTICS, self::NITROGEN_ISOTOPES], $shoppingList->missingVolumeTypeIds);
        self::assertSame([44, 9848, 17888], $shoppingList->missingVolumeTypeIds);
        self::assertNull($this->materialOf($shoppingList, self::ENRICHED_URANIUM)->importCost);
        self::assertNull($this->materialOf($shoppingList, self::ROBOTICS)->importCost);
    }

    public function testShoppingListWithoutMarketLeavesTheImportOfAMaterialWithoutSdeVolumeUnknown(): void
    {
        $this->marketUnavailable = true;
        $this->volumes[self::NITROGEN_ISOTOPES] = null;

        $shoppingList = $this->provideShoppingList();

        self::assertSame('Impossible de récupérer les prix du marché. Réessayez plus tard.', $shoppingList->priceError);
        $isotopes = $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES);
        self::assertSame(405, $isotopes->quantity);
        self::assertNull($isotopes->volume);
        self::assertNull($isotopes->totalVolume);
        self::assertNull($isotopes->importCost);
        self::assertNull($isotopes->jitaWithImport);
        self::assertNull($isotopes->bestLocation);
        self::assertNull($isotopes->bestPrice);
        self::assertNull($shoppingList->totals->volume);
        self::assertNull($shoppingList->totals->import);
        self::assertSame([self::NITROGEN_ISOTOPES], $shoppingList->missingVolumeTypeIds);
    }

    public function testPricedShoppingListReportsNoMissingVolumeWhenEveryListedTypeHasAnSdeVolume(): void
    {
        $this->project->setInventionMaterials([['typeId' => self::DATACORE, 'typeName' => 'Datacore - Rocket Science', 'quantity' => 8]]);
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0, self::DATACORE => 95000.0];
        $listedTypesTotalVolume = 13.5 + 5.0 + 57.375 + 78.75 + 7.0 + 202.5 + 2.5 + 3.0 + 45.0;
        $datacoreTotalVolume = 8 * 0.0625;

        $shoppingList = $this->provideShoppingList();

        self::assertSame(414.625, $listedTypesTotalVolume);
        self::assertSame(0.5, $datacoreTotalVolume);
        self::assertSame($listedTypesTotalVolume + $datacoreTotalVolume, $shoppingList->totals->volume);
        self::assertSame(600.0, $this->materialOf($shoppingList, self::DATACORE)->importCost);
        self::assertSame([], $shoppingList->missingVolumeTypeIds);
    }

    public function testPricedShoppingListReportsNoFailedProductWhenEveryRootProductIsBuilt(): void
    {
        $shoppingList = $this->provideShoppingList();

        self::assertSame([], $shoppingList->failedProducts);
    }

    public function testPricedShoppingListReportsTheRootProductWithoutBlueprintNextToTheMaterialsOfTheOtherProduct(): void
    {
        self::addStep($this->project, self::ROBOTICS, 3, 0, 'manufacturing');

        $shoppingList = $this->provideShoppingList();

        self::assertSame(
            [['typeId' => self::ROBOTICS, 'reason' => 'No blueprint or reaction found for product type ID 9848']],
            $shoppingList->failedProducts,
        );
        self::assertSame(self::LISTED_TYPE_IDS, array_map(static fn (ShoppingListMaterialResource $material): int => $material->typeId, $shoppingList->materials));
        self::assertSame(self::ISOTOPES_QUANTITY, $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES)->quantity);
        self::assertSame(1, $this->materialOf($shoppingList, self::ROBOTICS)->quantity);
    }

    private function assertIsotopesImportIsUnknownWithAJitaPriceOfFiveHundredOnly(ShoppingListResource $shoppingList): void
    {
        $isotopesJitaTotal = self::ISOTOPES_QUANTITY * 500.0;
        self::assertSame(202500.0, $isotopesJitaTotal);

        $isotopes = $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES);
        self::assertNull($isotopes->volume);
        self::assertNull($isotopes->totalVolume);
        self::assertNull($isotopes->importCost);
        self::assertNull($isotopes->jitaWithImport);
        self::assertSame(500.0, $isotopes->jitaUnitPrice);
        self::assertSame($isotopesJitaTotal, $isotopes->jitaTotal);
        self::assertNull($isotopes->bestLocation);
        self::assertNull($isotopes->bestPrice);
        self::assertNull($isotopes->savings);
        self::assertNull($shoppingList->totals->volume);
        self::assertNull($shoppingList->totals->import);
        self::assertNull($shoppingList->totals->jitaWithImport);
        self::assertNull($shoppingList->totals->savingsVsJitaWithImport);
        self::assertSame($isotopesJitaTotal, $shoppingList->totals->jita);
        self::assertSame(0.0, $shoppingList->totals->best);
        self::assertSame([self::NITROGEN_ISOTOPES], $shoppingList->missingVolumeTypeIds);
    }

    private function provideShoppingList(): ShoppingListResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->pilot);
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturn($this->project);

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('findBy')->willReturnCallback(function (array $criteria): array {
            $this->volumeCriteria[] = $criteria;
            $types = [];
            foreach ($this->volumes as $typeId => $volume) {
                $types[] = (new InvType())->setTypeId($typeId)->setTypeName('Type '.$typeId)->setVolume($volume);
            }

            return $types;
        });

        $marketService = $this->createStub(MarketService::class);
        $marketService->method('getDefaultStructureId')->willReturn(self::DEFAULT_MARKET_STRUCTURE_ID);
        $marketService->method('getDefaultStructureName')->willReturn('Default market');
        if ($this->marketUnavailable) {
            $marketService->method('comparePrices')->willThrowException(new \RuntimeException('ESI unavailable'));
        } else {
            $marketService->method('comparePrices')->willReturnCallback(function (array $typeIds, ?int $structureId, ?EveToken $token): array {
                $this->comparePricesCalls[] = ['typeIds' => $typeIds, 'structureId' => $structureId, 'token' => $token];

                return [
                    'jita' => array_intersect_key($this->jitaPrices, array_flip($typeIds)),
                    'jitaFromCache' => true,
                    'jitaLastSync' => null,
                    'structure' => array_intersect_key($this->structurePrices, array_flip($typeIds)),
                    'structureId' => self::PRICED_MARKET_STRUCTURE_ID,
                    'structureName' => 'Priced market',
                    'structureAccessible' => true,
                    'structureFromCache' => true,
                    'structureLastSync' => null,
                ];
            });
        }

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getWeightedSellPricesWithFallback')->willReturnCallback(function (array $typeQuantities): array {
            $this->weightedPriceQuantities[] = $typeQuantities;

            return [self::NITROGEN_ISOTOPES => ['weightedPrice' => 510.0, 'coverage' => 0.75, 'ordersUsed' => 2]];
        });

        $requestStack = new RequestStack();
        if ($this->request !== null) {
            $requestStack->push($this->request);
        }

        $provider = new ShoppingListProvider(
            $security,
            $projectRepository,
            $this->shoppingListBuilderOnGoldenSde(),
            $invTypeRepository,
            $marketService,
            $jitaMarketService,
            $requestStack,
            new NullLogger(),
        );

        return $provider->provide(new Get(), ['id' => self::PROJECT_ID]);
    }

    private function materialOf(ShoppingListResource $shoppingList, int $typeId): ShoppingListMaterialResource
    {
        foreach ($shoppingList->materials as $material) {
            if ($material->typeId === $typeId) {
                return $material;
            }
        }
        self::fail("Type {$typeId} is not in the shopping list");
    }
}
