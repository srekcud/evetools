<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryStepPurchase;
use App\Entity\User;
use App\Service\Industry\IndustryShoppingListBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
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

    private const float SHORT_MAX_JOB_DURATION_DAYS = 0.01;
    private const float DEFAULT_MAX_JOB_DURATION_DAYS = 2.0;

    private IndustryShoppingListBuilder $builder;
    private User $pilot;

    protected function setUp(): void
    {
        $this->builder = $this->shoppingListBuilderOnGoldenSde();
        $this->pilot = new User();
    }

    public function testShoppingListAddsTheMaterialsOfEachJobOfSplitRootSteps(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 50, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 30, 0, 'manufacturing');
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 20, 0, 'manufacturing');

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(12150 + 8100, $quantities[self::NITROGEN_ISOTOPES]);
        self::assertSame(30 + 20, $quantities[self::ROBOTICS]);
    }

    public function testShoppingListAppliesTheMeLevelOfEachStepAndIgnoresCopySteps(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 100, 0, 'copy');
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing', 0);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing', 10);

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(450 + 405, $quantities[self::NITROGEN_ISOTOPES]);
        self::assertSame(1 + 1, $quantities[self::ROBOTICS]);
    }

    #[Group('issue-16')]
    public function testShoppingListOfTenRunsOfFerniteCarbideBuildsTheFuelBlocksOfTheRootAndOfBothReactionsInOneStep(): void
    {
        $project = self::projectOf($this->pilot, self::FERNITE_CARBIDE, 10, 0);
        self::addStep($project, self::FERNITE_CARBIDE, 10, 0, 'reaction', 0);
        self::addStep($project, self::FERNITE_ALLOY, 5, 1, 'reaction', 0, 1000);
        self::addStep($project, self::CERAMIC_POWDER, 5, 1, 'reaction', 0, 1000);
        self::addStep($project, self::HYDROGEN_FUEL_BLOCK, 3, 2, 'manufacturing', 10, 50 + 25 + 25);

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

        self::assertSame([
            44 => 11, 3683 => 60, 3689 => 11, self::COOLANT => 25, self::ROBOTICS => 3, self::HEAVY_WATER => 459, self::LIQUID_OZONE => 945,
            16275 => 54, 16635 => 500, 16636 => 500, 16639 => 500, 16642 => 500, 17889 => 1215,
        ], $quantities);
    }

    // Contrairement à BuyVsBuildService, les matériaux consommés directement par le produit final sont dans la liste.
    public function testShoppingListOfOneNomadKeepsTheMaterialsConsumedDirectlyByTheFinalProduct(): void
    {
        $project = self::projectOf($this->pilot, self::NOMAD, 1, 10);
        self::addStep($project, self::NOMAD, 1, 0, 'manufacturing');

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(2025, $quantities[self::CONSTRUCTION_BLOCKS]);
        self::assertSame(3375, $quantities[self::MORPHITE]);
    }

    public function testShoppingListBuysAPurchasedIntermediateInsteadOfItsMaterials(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);
        $project = $this->oneRunOfFerniteCarbide();
        $this->onlyStepOf($project, self::FERNITE_ALLOY)->setPurchased(true);

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

        self::assertSame([self::HYDROGEN_FUEL_BLOCK => 5 + 5, 16635 => 100, 16636 => 100, self::FERNITE_ALLOY => 100], $quantities);
    }

    public function testShoppingListBuysAnIntermediateBlacklistedAfterTheCreationOfTheProjectAsALeaf(): void
    {
        $project = $this->oneRunOfFerniteCarbide();
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 15, 16635 => 100, 16636 => 100, 16639 => 100, 16642 => 100],
            $quantities,
        );
    }

    public function testShoppingListDropsTheMaterialsOfAnIntermediateFullyInStock(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);
        $project = $this->oneRunOfFerniteCarbide();
        $this->onlyStepOf($project, self::FERNITE_ALLOY)->setInStockQuantity(100);

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

        self::assertSame([self::HYDROGEN_FUEL_BLOCK => 5 + 5, 16635 => 100, 16636 => 100], $quantities);
    }

    public function testShoppingListAddsTheInStockQuantitiesOfEveryJobOfAnIntermediate(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK, self::SYLRAMIC_FIBERS]);
        $project = $this->projectOnGoldenSde($this->pilot, self::CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE, 11, 10, 20, self::SHORT_MAX_JOB_DURATION_DAYS);
        [$firstFerniteAlloyJob, $secondFerniteAlloyJob] = $this->stepsOf($project, self::FERNITE_ALLOY);
        $firstFerniteAlloyJob->setInStockQuantity(120);
        $secondFerniteAlloyJob->setInStockQuantity(80);

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 15 + 5 + 10, 16635 => 200, 16636 => 200, 16639 => 100, 16642 => 100, self::SYLRAMIC_FIBERS => 11 * 1501],
            $quantities,
        );
    }

    public function testShoppingListBuysTheUncoveredPartOfAPurchasedIntermediateInStockInsteadOfItsMaterials(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);
        $project = $this->oneRunOfFerniteCarbide();
        $this->onlyStepOf($project, self::FERNITE_ALLOY)->setPurchased(true)->setInStockQuantity(30);

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

        self::assertSame([self::HYDROGEN_FUEL_BLOCK => 10, 16635 => 100, 16636 => 100, self::FERNITE_ALLOY => 70], $quantities);
    }

    public function testShoppingListBuysNothingForAPurchasedIntermediateFullyInStock(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);
        $project = $this->oneRunOfFerniteCarbide();
        $this->onlyStepOf($project, self::FERNITE_ALLOY)->setPurchased(true)->setInStockQuantity(100);

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

        self::assertSame([self::HYDROGEN_FUEL_BLOCK => 10, 16635 => 100, 16636 => 100], $quantities);
    }

    public function testShoppingListBuysTheUncoveredPartOfAnIntermediateBlacklistedAfterTheCreationOfTheProject(): void
    {
        $project = $this->oneRunOfFerniteCarbide();
        $this->onlyStepOf($project, self::HYDROGEN_FUEL_BLOCK)->setInStockQuantity(7);
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(15 - 7, $quantities[self::HYDROGEN_FUEL_BLOCK]);
    }

    public function testShoppingListBuildsAnIntermediatePartlyInStockForTheRemainingQuantityOnly(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK, self::SYLRAMIC_FIBERS]);
        $project = $this->elevenRunsOfCapitalArmorPlate();
        $this->onlyStepOf($project, self::FERNITE_ALLOY)->setInStockQuantity(150);

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

        self::assertSame(self::ELEVEN_RUNS_OF_CAPITAL_ARMOR_PLATE_WITH_ONE_RUN_OF_FERNITE_ALLOY, $quantities);
    }

    public function testShoppingListRoundsTheRemainingQuantityOfAnIntermediateUpToWholeRuns(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK, self::SYLRAMIC_FIBERS]);
        $project = $this->elevenRunsOfCapitalArmorPlate();
        $this->onlyStepOf($project, self::FERNITE_ALLOY)->setInStockQuantity(50);
        $hydrogenFuelBlocksWithTwoRunsOfFerniteAlloy = 15 + 10 + 10;
        $scandiumOfTwoRunsOfFerniteAlloy = 200;
        $vanadiumOfTwoRunsOfFerniteAlloy = 200;

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

        self::assertSame([
            self::HYDROGEN_FUEL_BLOCK => $hydrogenFuelBlocksWithTwoRunsOfFerniteAlloy,
            16635 => 200,
            16636 => 200,
            16639 => $scandiumOfTwoRunsOfFerniteAlloy,
            16642 => $vanadiumOfTwoRunsOfFerniteAlloy,
            self::SYLRAMIC_FIBERS => 16504,
        ], $quantities);
    }

    public function testShoppingListBuysNothingBelowAnIntermediateWhoseNeedIsFullyInStock(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK, self::SYLRAMIC_FIBERS]);
        $project = $this->elevenRunsOfCapitalArmorPlate();
        $this->onlyStepOf($project, self::FERNITE_ALLOY)->setInStockQuantity(300);

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

        self::assertSame([self::HYDROGEN_FUEL_BLOCK => 15 + 10, 16635 => 200, 16636 => 200, self::SYLRAMIC_FIBERS => 16504], $quantities);
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

    #[Group('issue-6')]
    public function testShoppingListComputesTheExtraQuantityOfASuboptimalStructureOnTheRunsLeftByTheStockAndCurrentlyListsTheBestStructureQuantitiesIssue6(): void
    {
        $this->pilotStructures = [self::structure($this->pilot, 'raitaru')];
        $this->pilot->setIndustryBlacklistTypeIds([self::PRESSURIZED_OXIDIZERS, self::REINFORCED_CARBON_FIBER]);
        $project = $this->projectOnGoldenSde($this->pilot, self::CAPITAL_CORE_TEMPERATURE_REGULATOR, 1, 10, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);
        [$firstRegulatorJob, $secondRegulatorJob] = $this->stepsOf($project, self::CORE_TEMPERATURE_REGULATOR);
        $firstRegulatorJob->setStructureConfig(self::structure($this->pilot, 'station'))->setInStockQuantity(12);
        $secondRegulatorJob->setStructureConfig(self::structure($this->pilot, 'station'));
        $chiralStructuresOfTwoJobsOfTenRunsInARaitaru = 891 + 891;
        $chiralStructuresSavedByTheRaitaru = 9 + 9;
        $reactionInputOfTwoJobsOfTenRunsInARaitaru = 4455 + 4455;
        $reactionInputSavedByTheRaitaru = 45 + 45;

        self::assertSame([16, 16], [$firstRegulatorJob->getRuns(), $secondRegulatorJob->getRuns()]);
        self::assertSame([
            ['typeId' => 2401, 'typeName' => 'Chiral Structures', 'quantity' => $chiralStructuresOfTwoJobsOfTenRunsInARaitaru, 'extraQuantity' => $chiralStructuresSavedByTheRaitaru],
            ['typeId' => 2868, 'typeName' => 'Integrity Response Drones', 'quantity' => 18, 'extraQuantity' => 0],
            ['typeId' => self::PRESSURIZED_OXIDIZERS, 'typeName' => 'Pressurized Oxidizers', 'quantity' => $reactionInputOfTwoJobsOfTenRunsInARaitaru, 'extraQuantity' => $reactionInputSavedByTheRaitaru],
            ['typeId' => self::REINFORCED_CARBON_FIBER, 'typeName' => 'Reinforced Carbon Fiber', 'quantity' => $reactionInputOfTwoJobsOfTenRunsInARaitaru, 'extraQuantity' => $reactionInputSavedByTheRaitaru],
            ['typeId' => 2872, 'typeName' => 'Self-Harmonizing Power Core', 'quantity' => 18, 'extraQuantity' => 0],
            ['typeId' => 3645, 'typeName' => 'Water', 'quantity' => $chiralStructuresOfTwoJobsOfTenRunsInARaitaru, 'extraQuantity' => $chiralStructuresSavedByTheRaitaru],
        ], $this->materialsOf($project));
    }

    public function testShoppingListReportsAFailedProductWhenAnotherStepUsesASuboptimalStructure(): void
    {
        $this->pilotStructures = [self::structure($this->pilot, 'raitaru')];
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);
        self::addStep($project, self::ROBOTICS, 3, 0, 'manufacturing');
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing')
            ->setStructureConfig(self::structure($this->pilot, 'station'));
        $nitrogenIsotopesInTheRaitaru = 401;
        $nitrogenIsotopesSavedByTheRaitaru = 4;

        $shoppingList = $this->builder->getShoppingList($project);

        self::assertSame(
            [['typeId' => self::ROBOTICS, 'reason' => 'No blueprint or reaction found for product type ID 9848']],
            $shoppingList['failedProducts'],
        );
        self::assertContains(
            ['typeId' => self::NITROGEN_ISOTOPES, 'typeName' => 'Nitrogen Isotopes', 'quantity' => $nitrogenIsotopesInTheRaitaru, 'extraQuantity' => $nitrogenIsotopesSavedByTheRaitaru],
            $shoppingList['materials'],
        );
    }

    public function testShoppingListHasNoExtraQuantityWhenEveryStepUsesTheBestStructure(): void
    {
        $this->pilotStructures = [self::structure($this->pilot, 'raitaru')];
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing');

        $shoppingList = $this->materialsOf($project);

        self::assertArrayNotHasKey('extraQuantity', $shoppingList[0]);
        self::assertSame(401, self::quantitiesByTypeId($shoppingList)[self::NITROGEN_ISOTOPES]);
    }

    #[Group('issue-6')]
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

    #[Group('issue-6')]
    public function testShoppingListOfAStepInABetterStructureThanThePilotsBestCurrentlyHidesTheSavingIssue6(): void
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
        $this->onlyStepOf($project, self::FERNITE_ALLOY)->setPurchased(true);
        $this->onlyStepOf($project, self::CERAMIC_POWDER)->setInStockQuantity(100);

        $quantities = self::quantitiesByTypeId($this->materialsOf($project));

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
        return $this->projectOnGoldenSde($this->pilot, self::CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE, 11, 10, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);
    }

    private function oneRunOfFerniteCarbide(): IndustryProject
    {
        return $this->projectOnGoldenSde($this->pilot, self::FERNITE_CARBIDE, 1, 10, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);
    }
}
