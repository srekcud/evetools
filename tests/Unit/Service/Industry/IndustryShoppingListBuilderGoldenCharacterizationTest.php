<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryStepPurchase;
use App\Entity\User;
use App\Service\Industry\IndustryShoppingListBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Characterization of the shopping list of an industry project on the goldens, before buildProductionTree()
 * is replaced by src/Industry/Application (docs/specs/industry-engine.md, §7 rank 5).
 *
 * Root steps carry the runs the user asked for, the way the spec reads them (25 runs of Nitrogen Fuel Block = 1000 units).
 */
#[CoversClass(IndustryShoppingListBuilder::class)]
final class IndustryShoppingListBuilderGoldenCharacterizationTest extends TestCase
{
    use BuildsShoppingListOnGoldenSde;

    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int NITROGEN_ISOTOPES = 17888;
    private const int HEAVY_WATER = 16272;
    private const int LIQUID_OZONE = 16273;
    private const int ROBOTICS = 9848;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int NOMAD = 28846;
    private const int CONSTRUCTION_BLOCKS = 3828;
    private const int MORPHITE = 11399;
    private const int COOLANT = 9832;
    private const int CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE = 29049;
    private const int SYLRAMIC_FIBERS = 16678;
    private const int HELIUM_FUEL_BLOCK = 4247;
    private const int HEXITE = 16665;
    private const int CAPITAL_CORE_TEMPERATURE_REGULATOR = 57487;
    private const int CORE_TEMPERATURE_REGULATOR = 57479;
    private const int PRESSURIZED_OXIDIZERS = 57456;
    private const int REINFORCED_CARBON_FIBER = 57457;

    /** One run of Hydrogen Fuel Block at ME 10, no structure: the subtree of one Fernite Carbide branch. */
    private const array ONE_RUN_OF_HYDROGEN_FUEL_BLOCK = [
        44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18, 17889 => 405,
    ];

    private const array ELEVEN_RUNS_OF_CAPITAL_ARMOR_PLATE_WITH_ONE_RUN_OF_FERNITE_ALLOY = [
        self::HYDROGEN_FUEL_BLOCK => 15 + 10 + 5, 16635 => 200, 16636 => 200, 16639 => 100, 16642 => 100, self::SYLRAMIC_FIBERS => 16504,
    ];

    private IndustryShoppingListBuilder $builder;
    private User $pilot;

    protected function setUp(): void
    {
        $this->builder = $this->shoppingListBuilderOnGoldenSde();
        $this->pilot = new User();
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2 (B1, double division) : les runs de l'étape
    // racine sont déjà des runs, et buildProductionTree() les relit comme une quantité puis les divise encore par les
    // unités par run. 25 runs (1000 unités) deviennent ceil(25 / 40) = 1 run : Nitrogen Isotopes 405 au lieu de 10125,
    // Robotics 1 au lieu de 25 (golden nitrogen-fuel-block-25runs-me10-npc, currentEvetoolsSimulated).
    public function testShoppingListOfTwentyFiveRunsOfNitrogenFuelBlockCurrentlyBuysForOneRunIssue2(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 25, 0, 'manufacturing');

        self::assertSame([
            ['typeId' => 9832, 'typeName' => 'Coolant', 'quantity' => 9],
            ['typeId' => 44, 'typeName' => 'Enriched Uranium', 'quantity' => 4],
            ['typeId' => 16272, 'typeName' => 'Heavy Water', 'quantity' => 153],
            ['typeId' => 16273, 'typeName' => 'Liquid Ozone', 'quantity' => 315],
            ['typeId' => 3689, 'typeName' => 'Mechanical Parts', 'quantity' => 4],
            ['typeId' => 17888, 'typeName' => 'Nitrogen Isotopes', 'quantity' => 405],
            ['typeId' => 3683, 'typeName' => 'Oxygen', 'quantity' => 20],
            ['typeId' => 9848, 'typeName' => 'Robotics', 'quantity' => 1],
            ['typeId' => 16275, 'typeName' => 'Strontium Clathrates', 'quantity' => 18],
        ], $this->materialsOf($project));
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2 : sans étape, les runs du projet sont aussi
    // lus comme une quantité.
    public function testShoppingListOfAProjectWithoutStepsCurrentlyReadsTheProjectRunsAsAQuantityIssue2(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(405, $quantities[self::NITROGEN_ISOTOPES]);
        self::assertSame(1, $quantities[self::ROBOTICS]);
    }

    public function testShoppingListOfAProjectWithoutStepsUsesTheProjectMeLevel(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 0);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(450, $quantities[self::NITROGEN_ISOTOPES]);
        self::assertSame(22, $quantities[3683]);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2 : les runs de deux étapes racines découpées
    // (30 + 20) s'additionnent en 50 « unités », soit ceil(50 / 40) = 2 runs au lieu de 50.
    public function testShoppingListAddsTheRunsOfSplitRootStepsAndCurrentlyReadsTheSumAsAQuantityIssue2(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 50, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 30, 0, 'manufacturing');
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 20, 0, 'manufacturing');

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(810, $quantities[self::NITROGEN_ISOTOPES]);
        self::assertSame(2, $quantities[self::ROBOTICS]);
    }

    public function testShoppingListTakesTheMeLevelOfTheFirstRootStepAndIgnoresCopySteps(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 100, 0, 'copy');
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing', 0);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing', 10);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(450 * $quantities[self::ROBOTICS], $quantities[self::NITROGEN_ISOTOPES]);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issues #2 et #16 : 10 runs de Fernite Carbide
    // (10000 unités par run) deviennent 1 run ; le Hydrogen Fuel Block, consommé par la réaction racine et par ses deux
    // réactions intermédiaires (5 unités chacune), est fabriqué en trois jobs d'un run au lieu d'un seul : sa BOM est
    // achetée trois fois.
    public function testShoppingListOfTenRunsOfFerniteCarbideCurrentlyBuysOneRunAndThreeFuelBlockJobsIssues2And16(): void
    {
        $project = self::projectOf($this->pilot, self::FERNITE_CARBIDE, 10, 0);
        self::addStep($project, self::FERNITE_CARBIDE, 10, 0, 'reaction', 0);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        $expected = array_map(static fn (int $quantity): int => 3 * $quantity, self::ONE_RUN_OF_HYDROGEN_FUEL_BLOCK)
            + [16635 => 100, 16636 => 100, 16639 => 100, 16642 => 100];
        ksort($expected);
        self::assertSame($expected, $quantities);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #69 (R3) : la demande d'un intermédiaire n'est
    // pas agrégée entre branches avant l'arrondi en runs. Sur le golden Nomad (Ravworks, fait foi), 29 feuilles sur 63
    // sont surestimées dans la liste d'achats (mêmes écarts que le stockpile).
    public function testShoppingListOfOneNomadCurrentlyOverestimatesTheNonAggregatedLeavesIssue69(): void
    {
        $golden = json_decode((string) file_get_contents(self::GOLDEN_DIR.'nomad-1run-me10-no-structure.ravworks.json'), true, flags: \JSON_THROW_ON_ERROR);
        $goldenLeaves = array_column($golden['expected']['leaves'], 'quantity', 'typeId');
        $project = self::projectOf($this->pilot, self::NOMAD, 1, 10);
        self::addStep($project, self::NOMAD, 1, 0, 'manufacturing');

        $shoppingList = $this->materialsOf($project);

        $currentLeaves = array_replace($goldenLeaves, [
            17887 => 26730, 16634 => 75100, 16633 => 89700, 44 => 2358, 3689 => 2358, 9832 => 5273, 3683 => 12713,
            16272 => 97920, 16273 => 201600, 9848 => 640, 16275 => 11520, 17888 => 46170, 16274 => 46575, 17889 => 139725,
            16652 => 6800, 16650 => 6000, 16643 => 10300, 16653 => 3600, 16651 => 11400, 16648 => 3900, 16646 => 12200,
            16647 => 5900, 16635 => 92900, 16649 => 17200, 16642 => 59400, 16644 => 40100, 16636 => 107500, 16639 => 55500,
            16641 => 23700,
        ]);
        ksort($currentLeaves);
        self::assertSame($currentLeaves, $this->quantitiesByTypeId($shoppingList));
        self::assertCount(63, $shoppingList);
    }

    // Contrairement à BuyVsBuildService, les matériaux consommés directement par le produit final sont dans la liste.
    public function testShoppingListOfOneNomadKeepsTheMaterialsConsumedDirectlyByTheFinalProduct(): void
    {
        $project = self::projectOf($this->pilot, self::NOMAD, 1, 10);
        self::addStep($project, self::NOMAD, 1, 0, 'manufacturing');

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(2025, $quantities[self::CONSTRUCTION_BLOCKS]);
        self::assertSame(3375, $quantities[self::MORPHITE]);
    }

    public function testShoppingListBuysAPurchasedIntermediateInsteadOfItsSubtree(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);
        $project = $this->oneRunOfFerniteCarbide();
        self::addStep($project, self::FERNITE_ALLOY, 1, 1, 'reaction', 0)->setPurchased(true);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertSame([self::HYDROGEN_FUEL_BLOCK => 10, 16635 => 100, 16636 => 100, self::FERNITE_ALLOY => 100], $quantities);
    }

    public function testShoppingListBuysABlacklistedIntermediateAsALeaf(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($this->oneRunOfFerniteCarbide()));

        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 15, 16635 => 100, 16636 => 100, 16639 => 100, 16642 => 100],
            $quantities,
        );
    }

    public function testShoppingListDropsTheSubtreeOfAnIntermediateFullyInStock(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);
        $project = $this->oneRunOfFerniteCarbide();
        self::addStep($project, self::FERNITE_ALLOY, 1, 1, 'reaction', 0)->setInStockQuantity(100);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertSame([self::HYDROGEN_FUEL_BLOCK => 10, 16635 => 100, 16636 => 100], $quantities);
    }

    // Le stock est consommé une seule fois, dans l'ordre de parcours : 10 Hydrogen Fuel Block couvrent la racine (5)
    // puis la branche Fernite Alloy (5) ; la branche Ceramic Powder fabrique le sien.
    public function testShoppingListConsumesTheStockOfAnIntermediateOnceAcrossBranches(): void
    {
        $project = $this->oneRunOfFerniteCarbide();
        self::addStep($project, self::HYDROGEN_FUEL_BLOCK, 1, 2, 'manufacturing')->setInStockQuantity(10);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        $expected = self::ONE_RUN_OF_HYDROGEN_FUEL_BLOCK + [16635 => 100, 16636 => 100, 16639 => 100, 16642 => 100];
        ksort($expected);
        self::assertSame($expected, $quantities);
    }

    public function testShoppingListAddsTheInStockQuantitiesOfSeveralStepsOfTheSameProduct(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);
        $project = $this->oneRunOfFerniteCarbide();
        self::addStep($project, self::FERNITE_ALLOY, 1, 1, 'reaction', 0)->setInStockQuantity(60);
        self::addStep($project, self::FERNITE_ALLOY, 1, 1, 'reaction', 0)->setInStockQuantity(40);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertArrayNotHasKey(16639, $quantities);
        self::assertSame(10, $quantities[self::HYDROGEN_FUEL_BLOCK]);
    }

    public function testShoppingListBuysTheUncoveredPartOfAPurchasedIntermediateInStockInsteadOfItsSubtree(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);
        $project = $this->oneRunOfFerniteCarbide();
        self::addStep($project, self::FERNITE_ALLOY, 1, 1, 'reaction', 0)->setPurchased(true)->setInStockQuantity(30);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertSame([self::HYDROGEN_FUEL_BLOCK => 10, 16635 => 100, 16636 => 100, self::FERNITE_ALLOY => 70], $quantities);
    }

    public function testShoppingListBuysNothingForAPurchasedIntermediateFullyInStock(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);
        $project = $this->oneRunOfFerniteCarbide();
        self::addStep($project, self::FERNITE_ALLOY, 1, 1, 'reaction', 0)->setPurchased(true)->setInStockQuantity(100);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertSame([self::HYDROGEN_FUEL_BLOCK => 10, 16635 => 100, 16636 => 100], $quantities);
    }

    public function testShoppingListBuysTheUncoveredPartOfABlacklistedIntermediateInStock(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);
        $project = $this->oneRunOfFerniteCarbide();
        self::addStep($project, self::HYDROGEN_FUEL_BLOCK, 1, 2, 'manufacturing')->setInStockQuantity(7);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(8, $quantities[self::HYDROGEN_FUEL_BLOCK]);
    }

    public function testShoppingListBuildsTheSubtreeOfAnIntermediatePartlyInStockForTheRemainingQuantityOnly(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK, self::SYLRAMIC_FIBERS]);
        $project = $this->elevenRunsOfCapitalArmorPlate();
        self::addStep($project, self::FERNITE_ALLOY, 2, 2, 'reaction', 0)->setInStockQuantity(150);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(self::ELEVEN_RUNS_OF_CAPITAL_ARMOR_PLATE_WITH_ONE_RUN_OF_FERNITE_ALLOY, $quantities);
    }

    public function testShoppingListWithAnIntermediateStepWithoutStockEqualsTheListWithoutThatStep(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK, self::SYLRAMIC_FIBERS]);
        $withoutStock = $this->builder->getShoppingList($this->elevenRunsOfCapitalArmorPlate());
        $project = $this->elevenRunsOfCapitalArmorPlate();
        self::addStep($project, self::FERNITE_ALLOY, 2, 2, 'reaction', 0)->setInStockQuantity(0);

        $shoppingList = $this->builder->getShoppingList($project);

        self::assertSame($withoutStock, $shoppingList);
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 35, 16635 => 200, 16636 => 200, 16639 => 200, 16642 => 200, self::SYLRAMIC_FIBERS => 16504],
            $this->quantitiesByTypeId($shoppingList['materials']),
        );
    }

    public function testShoppingListBuysNothingBelowAnIntermediateWhoseDemandIsFullyInStock(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK, self::SYLRAMIC_FIBERS]);
        $project = $this->elevenRunsOfCapitalArmorPlate();
        self::addStep($project, self::FERNITE_ALLOY, 2, 2, 'reaction', 0)->setInStockQuantity(300);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertSame([self::HYDROGEN_FUEL_BLOCK => 25, 16635 => 200, 16636 => 200, self::SYLRAMIC_FIBERS => 16504], $quantities);
    }

    public function testShoppingListBuildsTheRemainingQuantityOfAnIntermediateWhosePartialStockIsSplitOverTwoSteps(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK, self::SYLRAMIC_FIBERS]);
        $project = $this->elevenRunsOfCapitalArmorPlate();
        self::addStep($project, self::FERNITE_ALLOY, 1, 2, 'reaction', 0)->setInStockQuantity(100);
        self::addStep($project, self::FERNITE_ALLOY, 1, 2, 'reaction', 0)->setInStockQuantity(50);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(self::ELEVEN_RUNS_OF_CAPITAL_ARMOR_PLATE_WITH_ONE_RUN_OF_FERNITE_ALLOY, $quantities);
    }

    public function testShoppingListConsumesThePartialStockOfAnIntermediateAtItsFirstBranchAndBuildsTheWholeDemandOfTheSecond(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK, self::HELIUM_FUEL_BLOCK, self::HEXITE]);
        $project = $this->elevenRunsOfCapitalArmorPlate();
        self::addStep($project, self::CERAMIC_POWDER, 2, 2, 'reaction', 0)->setInStockQuantity(150);
        $hydrogenFuelBlocks = 15 + 10 + 5 + 10;
        $evaporiteDepositsOfOneCeramicPowderRunThenTwo = 100 + 200;
        $silicatesOfOneCeramicPowderRunThenTwo = 100 + 200;

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        self::assertSame([
            self::HYDROGEN_FUEL_BLOCK => $hydrogenFuelBlocks,
            self::HELIUM_FUEL_BLOCK => 15,
            16635 => $evaporiteDepositsOfOneCeramicPowderRunThenTwo,
            16636 => $silicatesOfOneCeramicPowderRunThenTwo,
            16639 => 200,
            16642 => 200,
            self::HEXITE => 300,
        ], $quantities);
    }

    public function testShoppingListReportsARootProductWithoutBlueprintAndKeepsTheListOfTheOtherProduct(): void
    {
        $nitrogenFuelBlockOnly = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);
        self::addStep($nitrogenFuelBlockOnly, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing');
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);
        self::addStep($project, self::ROBOTICS, 3, 0, 'manufacturing');
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing');

        $shoppingList = $this->builder->getShoppingList($project);

        self::assertSame($this->materialsOf($nitrogenFuelBlockOnly), $shoppingList['materials']);
        self::assertCount(9, $shoppingList['materials']);
        self::assertSame(
            [['typeId' => self::ROBOTICS, 'reason' => 'No blueprint or reaction found for product type ID 9848']],
            $shoppingList['failedProducts'],
        );
    }

    public function testShoppingListOfAProjectWhoseOnlyProductHasNoBlueprintHasNoMaterialAndReportsTheProduct(): void
    {
        $project = self::projectOf($this->pilot, self::ROBOTICS, 10, 10);

        self::assertSame([
            'materials' => [],
            'failedProducts' => [['typeId' => self::ROBOTICS, 'reason' => 'No blueprint or reaction found for product type ID 9848']],
        ], $this->builder->getShoppingList($project));
    }

    public function testShoppingListReportsARootProductWithATypeMissingFromTheSde(): void
    {
        $this->typeIdsMissingFromSde = [self::ROBOTICS];
        $this->builder = $this->shoppingListBuilderOnGoldenSde();
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing');

        self::assertSame([
            'materials' => [],
            'failedProducts' => [['typeId' => self::NITROGEN_FUEL_BLOCK, 'reason' => 'Types missing from the SDE: 9848']],
        ], $this->builder->getShoppingList($project));
    }

    public function testShoppingListReportsTheFailedProductsInAscendingTypeIdOrder(): void
    {
        $project = self::projectOf($this->pilot, self::ROBOTICS, 1, 10);
        self::addStep($project, self::ROBOTICS, 4, 0, 'manufacturing');
        self::addStep($project, self::COOLANT, 6, 0, 'manufacturing');

        self::assertSame([
            ['typeId' => self::COOLANT, 'reason' => 'No blueprint or reaction found for product type ID 9832'],
            ['typeId' => self::ROBOTICS, 'reason' => 'No blueprint or reaction found for product type ID 9848'],
        ], $this->builder->getShoppingList($project)['failedProducts']);
    }

    public function testShoppingListComputesTheExtraQuantityOfASuboptimalStructureOnTheSubtreeOfTheRemainingQuantity(): void
    {
        $this->pilotStructures = [self::structure($this->pilot, 'raitaru')];
        $this->pilot->setIndustryBlacklistTypeIds([self::PRESSURIZED_OXIDIZERS, self::REINFORCED_CARBON_FIBER]);
        $project = self::projectOf($this->pilot, self::CAPITAL_CORE_TEMPERATURE_REGULATOR, 1, 10);
        self::addStep($project, self::CAPITAL_CORE_TEMPERATURE_REGULATOR, 1, 0, 'manufacturing');
        self::addStep($project, self::CORE_TEMPERATURE_REGULATOR, 32, 1, 'manufacturing')
            ->setStructureConfig(self::structure($this->pilot, 'station'))
            ->setInStockQuantity(12);
        $chiralStructuresOfTwentyRunsInARaitaru = 1782;
        $chiralStructuresSavedByTheRaitaru = 18;
        $reactionInputOfTwentyRunsInARaitaru = 8910;
        $reactionInputSavedByTheRaitaru = 90;

        self::assertSame([
            ['typeId' => 2401, 'typeName' => 'Chiral Structures', 'quantity' => $chiralStructuresOfTwentyRunsInARaitaru, 'extraQuantity' => $chiralStructuresSavedByTheRaitaru],
            ['typeId' => 2868, 'typeName' => 'Integrity Response Drones', 'quantity' => 18, 'extraQuantity' => 0],
            ['typeId' => self::PRESSURIZED_OXIDIZERS, 'typeName' => 'Pressurized Oxidizers', 'quantity' => $reactionInputOfTwentyRunsInARaitaru, 'extraQuantity' => $reactionInputSavedByTheRaitaru],
            ['typeId' => self::REINFORCED_CARBON_FIBER, 'typeName' => 'Reinforced Carbon Fiber', 'quantity' => $reactionInputOfTwentyRunsInARaitaru, 'extraQuantity' => $reactionInputSavedByTheRaitaru],
            ['typeId' => 2872, 'typeName' => 'Self-Harmonizing Power Core', 'quantity' => 18, 'extraQuantity' => 0],
            ['typeId' => 3645, 'typeName' => 'Water', 'quantity' => $chiralStructuresOfTwentyRunsInARaitaru, 'extraQuantity' => $chiralStructuresSavedByTheRaitaru],
        ], $this->materialsOf($project));
    }

    public function testShoppingListHasNoExtraQuantityWhenEveryStepUsesTheBestStructure(): void
    {
        $this->pilotStructures = [self::structure($this->pilot, 'raitaru')];
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing');

        $shoppingList = $this->materialsOf($project);

        self::assertArrayNotHasKey('extraQuantity', $shoppingList[0]);
        self::assertSame(401, $this->quantitiesByTypeId($shoppingList)[self::NITROGEN_ISOTOPES]);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #6 (R4, D3) : les quantités sont celles de la
    // meilleure structure du pilote (Raitaru, 1 %) alors que l'étape est assignée à une station NPC ; la quantité
    // réellement consommée n'apparaît qu'en écart, dans extraQuantity (Nitrogen Isotopes 401 + 4 au lieu de 405).
    public function testShoppingListOfAStepAssignedToAStationCurrentlyListsTheBestStructureQuantitiesIssue6(): void
    {
        $this->pilotStructures = [self::structure($this->pilot, 'raitaru')];
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing')
            ->setStructureConfig(self::structure($this->pilot, 'station'));

        $shoppingList = $this->materialsOf($project);

        self::assertSame([
            ['typeId' => 9832, 'typeName' => 'Coolant', 'quantity' => 9, 'extraQuantity' => 0],
            ['typeId' => 44, 'typeName' => 'Enriched Uranium', 'quantity' => 4, 'extraQuantity' => 0],
            ['typeId' => self::HEAVY_WATER, 'typeName' => 'Heavy Water', 'quantity' => 152, 'extraQuantity' => 1],
            ['typeId' => self::LIQUID_OZONE, 'typeName' => 'Liquid Ozone', 'quantity' => 312, 'extraQuantity' => 3],
            ['typeId' => 3689, 'typeName' => 'Mechanical Parts', 'quantity' => 4, 'extraQuantity' => 0],
            ['typeId' => self::NITROGEN_ISOTOPES, 'typeName' => 'Nitrogen Isotopes', 'quantity' => 401, 'extraQuantity' => 4],
            ['typeId' => 3683, 'typeName' => 'Oxygen', 'quantity' => 20, 'extraQuantity' => 0],
            ['typeId' => self::ROBOTICS, 'typeName' => 'Robotics', 'quantity' => 1, 'extraQuantity' => 0],
            ['typeId' => 16275, 'typeName' => 'Strontium Clathrates', 'quantity' => 18, 'extraQuantity' => 0],
        ], $shoppingList);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #6 (R4, D3) : sans structure, la meilleure
    // structure du pilote est l'absence de bonus ; l'étape assignée à un Raitaru (1 %) consomme 401 Nitrogen Isotopes,
    // mais la liste en achète 405 et l'économie de 4 n'apparaît nulle part (extraQuantity borné à 0).
    public function testShoppingListOfAStepInABetterStructureThanThePilotsBestCurrentlyHidesTheSaving(): void
    {
        $this->pilotStructures = [];
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing')
            ->setStructureConfig(self::structure($this->pilot, 'raitaru'));

        $shoppingList = $this->materialsOf($project);

        self::assertContains(
            ['typeId' => self::NITROGEN_ISOTOPES, 'typeName' => 'Nitrogen Isotopes', 'quantity' => 405, 'extraQuantity' => 0],
            $shoppingList,
        );
    }

    public function testShoppingListReadsTheStockAndPurchasesOfTheStepsAfterACopyStep(): void
    {
        $project = $this->oneRunOfFerniteCarbide();
        self::addStep($project, self::FERNITE_CARBIDE, 1, 0, 'copy');
        self::addStep($project, self::FERNITE_ALLOY, 1, 1, 'reaction', 0)->setPurchased(true);
        self::addStep($project, self::CERAMIC_POWDER, 1, 1, 'reaction', 0)->setInStockQuantity(100);

        $quantities = $this->quantitiesByTypeId($this->materialsOf($project));

        $expected = self::ONE_RUN_OF_HYDROGEN_FUEL_BLOCK + [self::FERNITE_ALLOY => 100];
        ksort($expected);
        self::assertSame($expected, $quantities);
    }

    // Le contrôle de structure sous-optimale parcourt toutes les étapes : une copie et un produit déjà vu ne l'arrêtent pas.
    public function testShoppingListDetectsASuboptimalStructureOnAStepAfterACopyAndARepeatedProduct(): void
    {
        $this->pilotStructures = [self::structure($this->pilot, 'raitaru')];
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'copy');
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing');
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing');
        self::addStep($project, self::HYDROGEN_FUEL_BLOCK, 1, 0, 'manufacturing')
            ->setStructureConfig(self::structure($this->pilot, 'station'));

        $extraQuantities = array_column($this->materialsOf($project), 'extraQuantity', 'typeId');

        self::assertSame(4, $extraQuantities[17889]);
        self::assertSame(0, $extraQuantities[self::NITROGEN_ISOTOPES]);
        self::assertSame(1, $extraQuantities[self::HEAVY_WATER]);
    }

    public function testGetPurchasedQuantitiesAddsThePurchasesOfEveryStepByType(): void
    {
        $project = $this->oneRunOfFerniteCarbide();
        $ferniteAlloyStep = self::addStep($project, self::FERNITE_ALLOY, 1, 1, 'reaction', 0);
        $ceramicPowderStep = self::addStep($project, self::CERAMIC_POWDER, 1, 1, 'reaction', 0);
        $ferniteAlloyStep->addPurchase((new IndustryStepPurchase())->setTypeId(16639)->setQuantity(60));
        $ceramicPowderStep->addPurchase((new IndustryStepPurchase())->setTypeId(16639)->setQuantity(40));
        $ceramicPowderStep->addPurchase((new IndustryStepPurchase())->setTypeId(16635)->setQuantity(100));

        self::assertSame([16639 => 100, 16635 => 100], $this->builder->getPurchasedQuantities($project));
    }

    private function materialsOf(IndustryProject $project): array
    {
        return $this->builder->getShoppingList($project)['materials'];
    }

    private function elevenRunsOfCapitalArmorPlate(): IndustryProject
    {
        $project = self::projectOf($this->pilot, self::CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE, 11, 10);
        self::addStep($project, self::CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE, 11, 0, 'manufacturing');

        return $project;
    }

    private function oneRunOfFerniteCarbide(): IndustryProject
    {
        $project = self::projectOf($this->pilot, self::FERNITE_CARBIDE, 1, 0);
        self::addStep($project, self::FERNITE_CARBIDE, 1, 0, 'reaction', 0);

        return $project;
    }

    /**
     * @param list<array<string, mixed>> $shoppingList
     *
     * @return array<int, int> typeId => quantity, sorted by typeId
     */
    private function quantitiesByTypeId(array $shoppingList): array
    {
        $quantities = array_column($shoppingList, 'quantity', 'typeId');
        ksort($quantities);

        return $quantities;
    }
}
