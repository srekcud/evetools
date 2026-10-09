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
    private const int HEAVY_WATER = 16272;
    private const int ROBOTICS = 9848;
    private const int DATACORE = 20410;
    private const int DEFAULT_MARKET_STRUCTURE_ID = 1_035_466_617_946;
    private const int PRICED_MARKET_STRUCTURE_ID = 1_000_000_000_001;
    private const int PREFERRED_MARKET_STRUCTURE_ID = 1_000_000_000_002;
    private const int REQUESTED_MARKET_STRUCTURE_ID = 1_000_000_000_003;
    /** Types of one run of Nitrogen Fuel Block at ME 10, in the order of the shopping list (by type name). */
    private const array LISTED_TYPE_IDS = [9832, 44, 16272, 16273, 3689, 17888, 3683, 9848, 16275];
    private const float TRANSPORT_COST_PER_M3 = 1200.0;

    private User $pilot;
    private IndustryProject $project;

    /** @var array<int, float> */
    private array $jitaPrices = [];

    /** @var array<int, float> */
    private array $structurePrices = [];

    private bool $marketUnavailable = false;

    /** @var array<int, ?float> */
    private array $volumes = [self::NITROGEN_ISOTOPES => 0.5];

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

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #8 (R10) : Robotics, sans prix Jita ni structure,
    // n'a ni prix ni lieu d'achat et sort en silence des totaux, affichés comme des montants connus.
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
        // 405 × 500 + 9 × 100
        self::assertSame(203400.0, $shoppingList->totals->jita);
        // 405 × 1200 + 153 × 200
        self::assertSame(516600.0, $shoppingList->totals->structure);
        // jita + import for Nitrogen Isotopes (445500) + Coolant (900), structure for Heavy Water (30600)
        self::assertSame(477000.0, $shoppingList->totals->best);
        self::assertSame(-30600.0, $shoppingList->totals->savingsVsJitaWithImport);
        self::assertSame(39600.0, $shoppingList->totals->savingsVsStructure);
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
        self::assertSame(900.0, $coolant->bestPrice);
        self::assertSame(0.0, $coolant->importCost);
        self::assertSame(0, $coolant->purchasedQuantity);

        self::assertSame(243000.0, $shoppingList->totals->import);
        self::assertSame(446400.0, $shoppingList->totals->jitaWithImport);
        self::assertSame(202.5, $shoppingList->totals->volume);
        self::assertSame(39600.0, $shoppingList->totals->savingsVsStructure);
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

        // 9 × 0.25 × 1200
        self::assertSame(2700.0, $this->materialOf($shoppingList, self::COOLANT)->importCost);
        // 2700 + 243000
        self::assertSame(245700.0, $shoppingList->totals->import);
        // 2.25 + 202.5
        self::assertSame(204.75, $shoppingList->totals->volume);
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

    // CARACTÉRISATION : comportement actuel, suspecté faux, sans issue : un volume SDE absent devient 0 m³, sans
    // signal ; le matériau sort du volume total et des frais d'import au lieu d'être marqué inconnu.
    public function testPricedShoppingListCurrentlyCountsAMissingSdeVolumeAsZero(): void
    {
        $this->volumes = [self::NITROGEN_ISOTOPES => null];
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0];

        $shoppingList = $this->provideShoppingList();

        $isotopes = $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES);
        self::assertSame(0.0, $isotopes->volume);
        self::assertSame(0.0, $isotopes->totalVolume);
        self::assertSame(0.0, $isotopes->importCost);
        self::assertSame(0.0, $shoppingList->totals->volume);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #6 (R4, D3) : les quantités sont celles du
    // Raitaru, meilleure structure du pilote ; la consommation de la station assignée n'apparaît qu'en extraQuantity.
    public function testPricedShoppingListOfAStepAssignedToAStationCarriesTheExtraQuantityIssue6(): void
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

    // CARACTÉRISATION : comportement actuel, suspecté faux, sans issue : le total « best » compte aussi les matériaux
    // vendus seulement en structure, absents du total Jita + import ; l'économie face à Jita devient négative
    // (446400 − 477000) alors que chaque matériau acheté à Jita y est au moins aussi bon marché.
    public function testPricedShoppingListCurrentlyReportsANegativeSavingVersusJitaWhenAMaterialIsOnlySoldInStructure(): void
    {
        $this->jitaPrices = [self::NITROGEN_ISOTOPES => 500.0, self::COOLANT => 100.0];
        $this->structurePrices = [self::NITROGEN_ISOTOPES => 1200.0, self::HEAVY_WATER => 200.0];

        $shoppingList = $this->provideShoppingList();

        self::assertSame(-30600.0, $shoppingList->totals->savingsVsJitaWithImport);
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

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #8 (R10) : sans marché, la liste garde ses
    // quantités et un message d'erreur, mais les totaux valent 0 au lieu d'être inconnus.
    public function testPricedShoppingListWithoutMarketKeepsTheQuantitiesAndCurrentlyTotalsZeroIssue8(): void
    {
        $this->marketUnavailable = true;

        $shoppingList = $this->provideShoppingList();

        self::assertSame('Impossible de récupérer les prix du marché. Réessayez plus tard.', $shoppingList->priceError);
        self::assertSame(405, $this->materialOf($shoppingList, self::NITROGEN_ISOTOPES)->quantity);
        self::assertNull($this->materialOf($shoppingList, self::NITROGEN_ISOTOPES)->bestPrice);
        self::assertSame(0.0, $shoppingList->totals->best);
        self::assertSame(0.0, $shoppingList->totals->jita);
        self::assertSame(202.5, $shoppingList->totals->volume);
        self::assertSame(self::DEFAULT_MARKET_STRUCTURE_ID, $shoppingList->structureId);
        self::assertFalse($shoppingList->structureAccessible);
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
