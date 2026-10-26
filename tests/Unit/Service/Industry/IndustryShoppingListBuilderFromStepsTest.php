<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Service\Industry\IndustryShoppingListBuilder;
use App\Service\Industry\ProductionStepNeedsResolver;
use App\Service\Industry\StepProductNeed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryShoppingListBuilder::class)]
#[CoversClass(ProductionStepNeedsResolver::class)]
#[CoversClass(StepProductNeed::class)]
#[Group('issue-121')]
final class IndustryShoppingListBuilderFromStepsTest extends TestCase
{
    use BuildsShoppingListOnGoldenSde;

    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int TITANIUM_CARBIDE = 16671;
    private const int OXYGEN_FUEL_BLOCK = 4312;
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int NOMAD = 28846;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE = 29049;
    private const int SYLRAMIC_FIBERS = 16678;
    private const int SCANDIUM = 16639;
    private const int VANADIUM = 16642;
    private const int EVAPORITE_DEPOSITS = 16635;
    private const int SILICATES = 16636;
    private const int OXYGEN_ISOTOPES = 17887;
    private const int ROBOTICS = 9848;
    private const int LIQUID_OZONE = 16273;

    private const float SHORT_MAX_JOB_DURATION_DAYS = 0.01;
    private const float DEFAULT_MAX_JOB_DURATION_DAYS = 2.0;
    private const float MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING = 10_000.0;

    private const array LEAVES_OF_THIRTY_TWO_QUANTUM_MICROPROCESSORS_BESIDES_TITANIUM_CARBIDE = [
        44 => 16, 3683 => 80, 3689 => 16, 9832 => 36, self::ROBOTICS => 4, 16272 => 612, self::LIQUID_OZONE => 1260,
        16274 => 405, 16275 => 72, 16634 => 100, self::EVAPORITE_DEPOSITS => 200, self::SILICATES => 100, 16638 => 100,
        16641 => 100, self::VANADIUM => 100, 16643 => 100, 16644 => 100, 16646 => 100, 16647 => 100, 16648 => 200,
        16649 => 100, 16650 => 100, 16651 => 100, self::OXYGEN_ISOTOPES => 405, 17888 => 405, 17889 => 405,
    ];

    private const array ONE_RUN_OF_HYDROGEN_FUEL_BLOCK = [
        44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, self::ROBOTICS => 1, 16272 => 153, self::LIQUID_OZONE => 315, 16275 => 18, 17889 => 405,
    ];

    private User $pilot;

    protected function setUp(): void
    {
        $this->pilot = new User();
    }

    public function testShoppingListBuysABlacklistedMaterialJobByJobOfTheSplitConsumer(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::TITANIUM_CARBIDE]);
        $project = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);

        $quantities = $this->quantitiesOf($project);

        self::assertSame([11, 11, 10], $this->runsOfEachJobOf($project, self::QUANTUM_MICROPROCESSOR));
        self::assertSame(169 + 169 + 153, $quantities[self::TITANIUM_CARBIDE]);
    }

    public function testShoppingListOfASplitConsumerBuysTheLeavesOfTheAggregatedSteps(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::TITANIUM_CARBIDE]);
        $project = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);

        $quantities = $this->quantitiesOf($project);

        self::assertSame(405, $quantities[self::OXYGEN_ISOTOPES]);
        self::assertSame(4, $quantities[self::ROBOTICS]);
        self::assertSame(1260, $quantities[self::LIQUID_OZONE]);
        self::assertSame(self::sortedByTypeId(self::LEAVES_OF_THIRTY_TWO_QUANTUM_MICROPROCESSORS_BESIDES_TITANIUM_CARBIDE + [self::TITANIUM_CARBIDE => 491]), $quantities);
    }

    public function testShoppingListOfAnUnsplitConsumerBuysTheBlacklistedMaterialForAllItsRunsAtOnce(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::TITANIUM_CARBIDE]);
        $project = $this->thirtyTwoQuantumMicroprocessors(self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame(490, $this->quantitiesOf($project)[self::TITANIUM_CARBIDE]);
    }

    public function testShoppingListBuysTheNeedOfAPurchasedStepAndNothingOfItsMaterials(): void
    {
        $project = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);
        $this->onlyStepOf($project, self::TITANIUM_CARBIDE)->setPurchased(true);

        self::assertSame(
            self::sortedByTypeId(self::LEAVES_OF_THIRTY_TWO_QUANTUM_MICROPROCESSORS_BESIDES_TITANIUM_CARBIDE + [self::TITANIUM_CARBIDE => 491]),
            $this->quantitiesOf($project),
        );
    }

    public function testShoppingListBuysNothingForAStepWhoseNeedIsInStockAndLowersTheNeedOfItsMaterials(): void
    {
        $project = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);
        $this->onlyStepOf($project, self::TITANIUM_CARBIDE)->setInStockQuantity(491);

        self::assertSame(
            self::sortedByTypeId(self::LEAVES_OF_THIRTY_TWO_QUANTUM_MICROPROCESSORS_BESIDES_TITANIUM_CARBIDE),
            $this->quantitiesOf($project),
        );
    }

    public function testShoppingListOfASplitConsumerEqualsTheListOfTheUnsplitConsumerWhenTheSplitChangesNoRunOfItsMaterials(): void
    {
        $split = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);
        $unsplit = $this->thirtyTwoQuantumMicroprocessors(self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame([1], $this->runsOfEachJobOf($split, self::TITANIUM_CARBIDE));
        self::assertSame(self::sortedByTypeId(self::LEAVES_OF_THIRTY_TWO_QUANTUM_MICROPROCESSORS_BESIDES_TITANIUM_CARBIDE), $this->quantitiesOf($split));
        self::assertSame($this->quantitiesOf($unsplit), $this->quantitiesOf($split));
    }

    public function testShoppingListOfOneNomadBuysTheLeavesOfRavworks(): void
    {
        $golden = json_decode((string) file_get_contents(self::GOLDEN_DIR.'nomad-1run-me10-no-structure.ravworks.json'), true, flags: \JSON_THROW_ON_ERROR);
        $project = $this->projectOnGoldenSde($this->pilot, self::NOMAD, 1, 10, 20, self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame(self::sortedByTypeId(array_column($golden['expected']['leaves'], 'quantity', 'typeId')), $this->quantitiesOf($project));
    }

    public function testShoppingListOfTwentyFiveRunsOfNitrogenFuelBlockBuysTheMaterialsOfTwentyFiveRuns(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 25, 0, 'manufacturing');

        self::assertSame([
            ['typeId' => 9832, 'typeName' => 'Coolant', 'quantity' => 203],
            ['typeId' => 44, 'typeName' => 'Enriched Uranium', 'quantity' => 90],
            ['typeId' => 16272, 'typeName' => 'Heavy Water', 'quantity' => 3825],
            ['typeId' => self::LIQUID_OZONE, 'typeName' => 'Liquid Ozone', 'quantity' => 7875],
            ['typeId' => 3689, 'typeName' => 'Mechanical Parts', 'quantity' => 90],
            ['typeId' => 17888, 'typeName' => 'Nitrogen Isotopes', 'quantity' => 10125],
            ['typeId' => 3683, 'typeName' => 'Oxygen', 'quantity' => 495],
            ['typeId' => self::ROBOTICS, 'typeName' => 'Robotics', 'quantity' => 25],
            ['typeId' => 16275, 'typeName' => 'Strontium Clathrates', 'quantity' => 450],
        ], $this->shoppingListBuilderOnGoldenSde()->getShoppingList($project)['materials']);
    }

    public function testShoppingListOfAProjectWithoutStepIsEmptyAndReportsNoFailedProduct(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10);

        self::assertSame(['materials' => [], 'failedProducts' => []], $this->shoppingListBuilderOnGoldenSde()->getShoppingList($project));
    }

    public function testShoppingListOfAProjectWithOnlyACopyStepIsEmptyAndReportsNoFailedProduct(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 25, 0, 'copy');

        self::assertSame(['materials' => [], 'failedProducts' => []], $this->shoppingListBuilderOnGoldenSde()->getShoppingList($project));
    }

    public function testShoppingListBuysAManufacturableMaterialWhoseStepWasRemovedAsARawMaterial(): void
    {
        $project = $this->oneRunOfFerniteCarbide();
        $project->getSteps()->removeElement($this->onlyStepOf($project, self::FERNITE_ALLOY));

        self::assertSame(
            self::sortedByTypeId(self::ONE_RUN_OF_HYDROGEN_FUEL_BLOCK + [self::FERNITE_ALLOY => 100, self::EVAPORITE_DEPOSITS => 100, self::SILICATES => 100]),
            $this->quantitiesOf($project),
        );
    }

    public function testShoppingListReportsAFailedProductBelowTheRootAndKeepsThePersistedNeedOfTheStepsBelowIt(): void
    {
        $project = $this->oneRunOfFerniteCarbide();
        $this->typeIdsMissingFromSde = [self::SCANDIUM];

        $shoppingList = $this->shoppingListBuilderOnGoldenSde()->getShoppingList($project);

        self::assertSame([['typeId' => self::FERNITE_ALLOY, 'reason' => 'Types missing from the SDE: 16639']], $shoppingList['failedProducts']);
        self::assertSame(
            self::sortedByTypeId(self::ONE_RUN_OF_HYDROGEN_FUEL_BLOCK + [self::EVAPORITE_DEPOSITS => 100, self::SILICATES => 100]),
            self::quantitiesByTypeId($shoppingList['materials']),
        );
    }

    public function testShoppingListWithAPartialStockThatChangesNoRunEqualsTheListWithoutStock(): void
    {
        $project = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);
        $this->onlyStepOf($project, self::OXYGEN_FUEL_BLOCK)->setInStockQuantity(10);

        self::assertSame(self::sortedByTypeId(self::LEAVES_OF_THIRTY_TWO_QUANTUM_MICROPROCESSORS_BESIDES_TITANIUM_CARBIDE), $this->quantitiesOf($project));
    }

    public function testShoppingListIgnoresTheJobsOfASplitGroupLeftWithoutRunsByTheStock(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK, self::SYLRAMIC_FIBERS]);
        $project = $this->projectOnGoldenSde($this->pilot, self::CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE, 11, 10, 20, self::SHORT_MAX_JOB_DURATION_DAYS);
        $ferniteCarbideJobs = $this->stepsOf($project, self::FERNITE_CARBIDE);
        $ferniteCarbideJobs[0]->setInStockQuantity(10_000);
        $ferniteCarbideJobs[1]->setInStockQuantity(10_000);
        $ferniteCarbideJobs[2]->setInStockQuantity(1000);

        self::assertSame([1, 1, 1], $this->runsOfEachJobOf($project, self::FERNITE_CARBIDE));
        self::assertSame([1, 1], $this->runsOfEachJobOf($project, self::FERNITE_ALLOY));
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 15, self::EVAPORITE_DEPOSITS => 100, self::SILICATES => 100, self::SCANDIUM => 100, self::VANADIUM => 100, self::SYLRAMIC_FIBERS => 11 * 1501],
            $this->quantitiesOf($project),
        );
    }

    public function testShoppingListFollowsAManuallySplitRootStepWithoutRecalculatingTheStepsBelowIt(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::TITANIUM_CARBIDE]);
        $project = $this->thirtyTwoQuantumMicroprocessors(self::DEFAULT_MAX_JOB_DURATION_DAYS);
        $rootStep = $this->onlyStepOf($project, self::QUANTUM_MICROPROCESSOR)->setRuns(11)->setQuantity(11)->setSplitGroupId('manual-split')->setSplitIndex(0)->setTotalGroupRuns(32);
        $project->addStep((clone $rootStep)->setRuns(11)->setQuantity(11)->setSplitIndex(1));
        $project->addStep((clone $rootStep)->setRuns(10)->setQuantity(10)->setSplitIndex(2));

        self::assertSame(
            self::sortedByTypeId(self::LEAVES_OF_THIRTY_TWO_QUANTUM_MICROPROCESSORS_BESIDES_TITANIUM_CARBIDE + [self::TITANIUM_CARBIDE => 491]),
            $this->quantitiesOf($project),
        );
    }

    public function testShoppingListReportsAStepWithoutBlueprintNorReactionAsAFailedProductAndBuysItsProduct(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 0);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing', 0);
        self::addStep($project, self::ROBOTICS, 1, 1, 'manufacturing', 0);

        $shoppingList = $this->shoppingListBuilderOnGoldenSde()->getShoppingList($project);

        self::assertSame([['typeId' => self::ROBOTICS, 'reason' => 'No blueprint or reaction found for product type ID 9848']], $shoppingList['failedProducts']);
        self::assertSame(1, self::quantitiesByTypeId($shoppingList['materials'])[self::ROBOTICS]);
    }

    public function testShoppingListReportsTheFailedProductsByIncreasingTypeIdWhateverTheOrderOfTheirSteps(): void
    {
        $project = $this->oneRunOfFerniteCarbide();
        self::addStep($project, self::OXYGEN_ISOTOPES, 1, 1, 'manufacturing');
        $this->typeIdsMissingFromSde = [self::SCANDIUM];

        self::assertSame(
            [
                ['typeId' => self::FERNITE_ALLOY, 'reason' => 'Types missing from the SDE: 16639'],
                ['typeId' => self::OXYGEN_ISOTOPES, 'reason' => 'No blueprint or reaction found for product type ID 17887'],
            ],
            $this->shoppingListBuilderOnGoldenSde()->getShoppingList($project)['failedProducts'],
        );
    }

    public function testShoppingListDoesNotBuyAFailedProductBlacklistedAfterItsStepWasPlanned(): void
    {
        $project = $this->oneRunOfFerniteCarbide();
        $this->pilot->setIndustryBlacklistTypeIds([self::FERNITE_ALLOY]);
        $this->typeIdsMissingFromSde = [self::SCANDIUM];

        $shoppingList = $this->shoppingListBuilderOnGoldenSde()->getShoppingList($project);

        self::assertSame([['typeId' => self::FERNITE_ALLOY, 'reason' => 'Types missing from the SDE: 16639']], $shoppingList['failedProducts']);
        self::assertSame(
            self::sortedByTypeId(self::ONE_RUN_OF_HYDROGEN_FUEL_BLOCK + [self::EVAPORITE_DEPOSITS => 100, self::SILICATES => 100]),
            self::quantitiesByTypeId($shoppingList['materials']),
        );
    }

    public function testShoppingListKeepsThePersistedNeedOfTheStepsBelowAFailedProductWhateverItsStock(): void
    {
        $project = $this->oneRunOfFerniteCarbide();
        $this->onlyStepOf($project, self::FERNITE_ALLOY)->setInStockQuantity(200);
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);
        $this->typeIdsMissingFromSde = [self::SCANDIUM];

        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 15, self::EVAPORITE_DEPOSITS => 100, self::SILICATES => 100],
            $this->quantitiesOf($project),
        );
    }

    public function testShoppingListOfABlacklistedRootBuysOnlyTheRoot(): void
    {
        $project = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);
        $this->pilot->setIndustryBlacklistTypeIds([self::QUANTUM_MICROPROCESSOR]);

        self::assertSame([self::QUANTUM_MICROPROCESSOR => 32], $this->quantitiesOf($project));
    }

    public function testShoppingListOfAPurchasedRootBuysOnlyTheRoot(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 25, 0, 'manufacturing')->setPurchased(true);

        self::assertSame([self::NITROGEN_FUEL_BLOCK => 1000], $this->quantitiesOf($project));
    }

    public function testShoppingListIgnoresTheStockOfTheRoot(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 25, 0, 'manufacturing')->setInStockQuantity(1000);

        self::assertSame(
            [44 => 90, 3683 => 495, 3689 => 90, 9832 => 203, self::ROBOTICS => 25, 16272 => 3825, self::LIQUID_OZONE => 7875, 16275 => 450, 17888 => 10125],
            $this->quantitiesOf($project),
        );
    }

    public function testShoppingListBuysTheWholeNeedOfASplitGroupWithOnePurchasedJob(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK, self::SYLRAMIC_FIBERS]);
        $project = $this->projectOnGoldenSde($this->pilot, self::CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE, 11, 10, 20, self::SHORT_MAX_JOB_DURATION_DAYS);
        $this->stepsOf($project, self::FERNITE_CARBIDE)[1]->setPurchased(true);

        self::assertSame([self::FERNITE_CARBIDE => 22_000, self::SYLRAMIC_FIBERS => 11 * 1501], $this->quantitiesOf($project));
    }

    public function testShoppingListKeepsTheRunsOfEachJobOfAManuallySplitRoot(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::TITANIUM_CARBIDE]);
        $project = $this->thirtyTwoQuantumMicroprocessors(self::DEFAULT_MAX_JOB_DURATION_DAYS);
        $rootStep = $this->onlyStepOf($project, self::QUANTUM_MICROPROCESSOR)->setRuns(21)->setQuantity(21)->setSplitGroupId('manual-split')->setSplitIndex(0)->setTotalGroupRuns(32);
        $project->addStep((clone $rootStep)->setRuns(11)->setQuantity(11)->setSplitIndex(1));

        self::assertSame(322 + 169, $this->quantitiesOf($project)[self::TITANIUM_CARBIDE]);
    }

    private function thirtyTwoQuantumMicroprocessors(float $maxJobDurationDays): IndustryProject
    {
        return $this->projectOnGoldenSde($this->pilot, self::QUANTUM_MICROPROCESSOR, 32, 10, 20, $maxJobDurationDays);
    }

    private function oneRunOfFerniteCarbide(): IndustryProject
    {
        return $this->projectOnGoldenSde($this->pilot, self::FERNITE_CARBIDE, 1, 10, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);
    }

    private function runsOfEachJobOf(IndustryProject $project, int $productTypeId): array
    {
        return array_map(static fn (IndustryProjectStep $step): int => $step->getRuns(), $this->stepsOf($project, $productTypeId));
    }

    private function quantitiesOf(IndustryProject $project): array
    {
        return self::quantitiesByTypeId($this->shoppingListBuilderOnGoldenSde()->getShoppingList($project)['materials']);
    }

    private static function sortedByTypeId(array $quantities): array
    {
        ksort($quantities);

        return $quantities;
    }
}
