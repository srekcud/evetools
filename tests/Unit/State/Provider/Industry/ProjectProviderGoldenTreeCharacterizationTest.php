<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Industry\ProjectStepResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\ProductionStepNeedsResolver;
use App\Service\Industry\ProductionTreeFromSteps;
use App\Service\Industry\StepProductNeed;
use App\Service\TypeNameResolver;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\State\Provider\Industry\ProjectProvider;
use App\Tests\Unit\Service\Industry\BuildsShoppingListOnGoldenSde;
use App\Tests\Unit\Service\Industry\TataraAndRaitaruBonusService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectProvider::class)]
#[CoversClass(ProductionTreeFromSteps::class)]
#[CoversClass(ProductionStepNeedsResolver::class)]
#[CoversClass(StepProductNeed::class)]
final class ProjectProviderGoldenTreeCharacterizationTest extends TestCase
{
    use BuildsShoppingListOnGoldenSde;

    private const string SNAPSHOT_DIR = __DIR__.'/../../../../Fixtures/Characterization/ProjectProvider/';

    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int TITANIUM_CARBIDE = 16671;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int NANOTRANSISTORS = 16681;
    private const int NONLINEAR_METAMATERIALS = 33362;
    private const int OXYGEN_FUEL_BLOCK = 4312;
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;
    private const int CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE = 29049;
    private const int SYLRAMIC_FIBERS = 16678;
    private const int SCANDIUM = 16639;
    private const int FENRIR = 20189;
    private const int CAPITAL_ARMOR_PLATES = 21017;
    private const int NOMAD = 28846;
    private const int ROBOTICS = 9848;
    private const int HAIL_L = 12779;
    private const int RAM_AMMUNITION_TECH = 11476;
    private const int FULLERIDES = 16679;
    private const int COOLANT = 9832;
    private const int ISOGEN = 37;
    private const int NOCXIUM = 38;
    private const int MEGACYTE = 40;
    private const int OXYGEN_ISOTOPES = 17887;

    private const float SHORT_MAX_JOB_DURATION_DAYS = 0.01;
    private const float DEFAULT_MAX_JOB_DURATION_DAYS = 2.0;
    private const float MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING = 10_000.0;

    private const string REACTION_CATEGORY = 'composite_reaction';
    private const float TATARA_REACTOR_RIG_BONUS = 2.64;
    private const float RAITARU_BASE_MATERIAL_BONUS = 1.0;

    private const array LEAVES_OF_THIRTY_TWO_QUANTUM_MICROPROCESSORS_BESIDES_TITANIUM_CARBIDE = [
        44 => 16, 3683 => 80, 3689 => 16, 9832 => 36, self::ROBOTICS => 4, 16272 => 612, 16273 => 1260,
        16274 => 405, 16275 => 72, 16634 => 100, 16635 => 200, 16636 => 100, 16638 => 100,
        16641 => 100, 16642 => 100, 16643 => 100, 16644 => 100, 16646 => 100, 16647 => 100, 16648 => 200,
        16649 => 100, 16650 => 100, 16651 => 100, 17887 => 405, 17888 => 405, 17889 => 405,
    ];

    private const array ONE_RUN_OF_FUEL_BLOCK_AT_ME_TEN = [44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, self::ROBOTICS => 1, 16272 => 153, 16273 => 315, 16275 => 18];
    private const array THREE_RUNS_OF_HYDROGEN_FUEL_BLOCK_AT_ME_TEN_IN_THE_RAITARU = [44 => 11, 3683 => 59, 3689 => 11, self::COOLANT => 25, self::ROBOTICS => 3, 16272 => 455, 16273 => 936, 16275 => 54, 17889 => 1203];

    private bool $pilotOwnsTataraAndRaitaru = false;
    private User $pilot;
    private ?IndustryProject $requestedProject = null;
    private ProjectStepResource $mappedStep;
    private int $productsOfBlueprintsReadCount = 0;

    protected function setUp(): void
    {
        $this->pilot = new User();
        $this->mappedStep = new ProjectStepResource();
    }

    #[Group('issue-121')]
    public function testTreeOfThirtyTwoQuantumMicroprocessorsSplitInThreeJobsSumsTheBlacklistedMaterialJobByJob(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::TITANIUM_CARBIDE]);
        $project = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);

        $tree = $this->treeOf($project);

        self::assertSame(
            [self::TITANIUM_CARBIDE => 491, self::PHENOLIC_COMPOSITES => 174, self::NANOTRANSISTORS => 58, self::NONLINEAR_METAMATERIALS => 58],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
        self::assertSame([32, 32, false], [$tree['quantity'], $tree['runs'], $tree['materials'][0]['isBuildable']]);
        self::assertSame(16, self::nodeCountOf($tree));
    }

    #[Group('issue-121')]
    public function testTreeOfThirtyTwoQuantumMicroprocessorsShowsOneNodePerIntermediateAndReferencesItsOtherConsumers(): void
    {
        $project = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);

        $tree = $this->treeOf($project);

        self::assertSame([[491, 1]], self::quantityAndRunsOfNodesOf($tree, self::TITANIUM_CARBIDE));
        self::assertSame([[25, 1]], self::quantityAndRunsOfNodesOf($tree, self::OXYGEN_FUEL_BLOCK));
        self::assertSame(4, self::referenceCountOf($tree, self::OXYGEN_FUEL_BLOCK));
        self::assertSame(self::sortedByTypeId(self::LEAVES_OF_THIRTY_TWO_QUANTUM_MICROPROCESSORS_BESIDES_TITANIUM_CARBIDE), self::leafQuantitiesOf($tree));
        self::assertSame(17, self::nodeCountOf($tree));
    }

    #[Group('issue-121')]
    public function testPurchasedStepKeepsItsNodeWithoutRunsNorMaterials(): void
    {
        $project = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);
        $this->onlyStepOf($project, self::TITANIUM_CARBIDE)->setPurchased(true);

        $titaniumCarbideNode = self::nodesOf($this->treeOf($project), self::TITANIUM_CARBIDE)[0];

        self::assertSame([491, 0, []], [$titaniumCarbideNode['quantity'], $titaniumCarbideNode['runs'], $titaniumCarbideNode['materials']]);
    }

    #[Group('issue-121')]
    public function testStepWhoseNeedIsInStockKeepsItsNeedWithoutRunsNorMaterials(): void
    {
        $project = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);
        $this->onlyStepOf($project, self::TITANIUM_CARBIDE)->setInStockQuantity(491);

        $titaniumCarbideNode = self::nodesOf($this->treeOf($project), self::TITANIUM_CARBIDE)[0];

        self::assertSame([491, 0, []], [$titaniumCarbideNode['quantity'], $titaniumCarbideNode['runs'], $titaniumCarbideNode['materials']]);
    }

    #[Group('issue-121')]
    public function testProductBlacklistedAfterItsStepWasPlannedIsShownAsANonBuildableMaterial(): void
    {
        $project = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);
        $this->pilot->setIndustryBlacklistTypeIds([self::TITANIUM_CARBIDE]);

        $tree = $this->treeOf($project);

        self::assertSame(
            ['typeId' => self::TITANIUM_CARBIDE, 'typeName' => 'Titanium Carbide', 'quantity' => 491, 'isBuildable' => false, 'activityType' => null],
            $tree['materials'][0],
        );
        self::assertSame(16, self::nodeCountOf($tree));
    }

    #[Group('issue-121')]
    public function testTreeOfOneNomadSplitInTwoDayJobsShowsOneNodePerIntermediate(): void
    {
        $project = $this->projectOnGoldenSde($this->pilot, self::NOMAD, 1, 10, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $tree = $this->treeOf($project);

        self::assertSame(66, self::nodeCountOf($tree));
        self::assertSame([[12855, 322]], self::quantityAndRunsOfNodesOf($tree, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame(12, self::referenceCountOf($tree, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame([[4225, 106]], self::quantityAndRunsOfNodesOf($tree, self::NITROGEN_FUEL_BLOCK));
        self::assertSame(7, self::referenceCountOf($tree, self::NITROGEN_FUEL_BLOCK));
        self::assertSame(
            [self::ISOGEN => 1, self::NOCXIUM => 1, self::MEGACYTE => 1, self::COOLANT => 1],
            self::leafDifferencesWithRavworks(self::leafQuantitiesOf($tree)),
        );
        $this->assertMatchesSnapshot('nomad-1run-me10-no-structure-leaves-and-job-counts', self::leafQuantitiesAndNodeCountsOf($tree));
    }

    #[Group('issue-121')]
    public function testTreeOfOneUnsplitNomadHasTheLeavesOfRavworks(): void
    {
        $project = $this->projectOnGoldenSde($this->pilot, self::NOMAD, 1, 10, 20, self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame([], self::leafDifferencesWithRavworks(self::leafQuantitiesOf($this->treeOf($project))));
    }

    #[Group('issue-121')]
    public function testTreeOfARootStepOfTwentyFiveRunsOfNitrogenFuelBlockShowsTwentyFiveRuns(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 25, 0, 'manufacturing');

        $tree = $this->treeOf($project);

        self::assertSame(
            [44 => 90, 3683 => 495, 3689 => 90, 9832 => 203, self::ROBOTICS => 25, 16272 => 3825, 16273 => 7875, 16275 => 450, 17888 => 10125],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
        self::assertSame([1000, 25, 40], [$tree['quantity'], $tree['runs'], $tree['outputPerRun']]);
    }

    #[Group('issue-2')]
    public function testTreeOfTwentyFiveRunsOfNitrogenFuelBlockPlannedByTheFactoryShowsTwentyFiveRunsOfOneThousandUnits(): void
    {
        $project = $this->projectOnGoldenSde($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $tree = $this->treeOf($project);

        self::assertSame(
            [1000, 25, 40, 'manufacturing', 0.0, null],
            [$tree['quantity'], $tree['runs'], $tree['outputPerRun'], $tree['activityType'], $tree['structureBonus'], $tree['structureName']],
        );
        self::assertSame(
            [44 => 90, 3683 => 495, 3689 => 90, 9832 => 203, self::ROBOTICS => 25, 16272 => 3825, 16273 => 7875, 16275 => 450, 17888 => 10125],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
        $this->assertMatchesSnapshot('nitrogen-fuel-block-25runs-me10-npc', $tree);
    }

    #[Group('issue-2')]
    public function testTreeOfTenRunsOfRamAmmunitionTechPlannedByTheFactoryShowsTenRunsOfOneThousandUnits(): void
    {
        $project = $this->projectOnGoldenSde($this->pilot, self::RAM_AMMUNITION_TECH, 10, 10, 20, self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        $tree = $this->treeOf($project);

        self::assertSame([1000, 10, 100, 'manufacturing'], [$tree['quantity'], $tree['runs'], $tree['outputPerRun'], $tree['activityType']]);
    }

    #[Group('issue-121')]
    #[Group('issue-193')]
    public function testProjectWithoutStepHasNoTreeAndNoFailedProduct(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10);

        $resource = $this->provide($project);

        self::assertSame([null, []], [$resource->tree, $resource->failedProducts]);
    }

    #[Group('issue-121')]
    public function testNodeOfASplitGroupTakesTheStructureOfItsFirstJobAndSumsTheMaterialsOfEachJobWithItsOwnStructure(): void
    {
        $project = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);
        $jobs = $this->stepsOf($project, self::QUANTUM_MICROPROCESSOR);
        $jobs[0]->setStructureConfig(self::structure($this->pilot, 'raitaru'));
        $jobs[1]->setStructureConfig(self::structure($this->pilot, 'station'));

        $tree = $this->treeOf($project);

        self::assertSame(167 + 169 + 153, array_column($tree['materials'], 'quantity', 'typeId')[self::TITANIUM_CARBIDE]);
        self::assertSame(['Raitaru', self::RAITARU_BASE_MATERIAL_BONUS], [$tree['structureName'], $tree['structureBonus']]);
    }

    #[Group('issue-121')]
    public function testNodeOfASplitGroupIgnoresTheJobsLeftWithoutRunsByTheStock(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK, self::SYLRAMIC_FIBERS]);
        $project = $this->projectOnGoldenSde($this->pilot, self::CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE, 11, 10, 20, self::SHORT_MAX_JOB_DURATION_DAYS);
        $ferniteCarbideJobs = $this->stepsOf($project, self::FERNITE_CARBIDE);
        $ferniteCarbideJobs[0]->setInStockQuantity(10_000);
        $ferniteCarbideJobs[1]->setInStockQuantity(10_000);
        $ferniteCarbideJobs[2]->setInStockQuantity(1000);

        $ferniteCarbideNode = self::nodesOf($this->treeOf($project), self::FERNITE_CARBIDE)[0];

        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 5, self::FERNITE_ALLOY => 100, self::CERAMIC_POWDER => 100],
            array_column($ferniteCarbideNode['materials'], 'quantity', 'typeId'),
        );
        self::assertSame([22_000, 1], [$ferniteCarbideNode['quantity'], $ferniteCarbideNode['runs']]);
    }

    #[Group('issue-121')]
    public function testCycleBetweenTheProductsOfTheStepsFailsTheProject(): void
    {
        $project = self::projectOf($this->pilot, self::FERNITE_ALLOY, 1, 0);
        self::addStep($project, self::FERNITE_ALLOY, 1, 0, 'reaction', 0);
        self::addStep($project, self::HYDROGEN_FUEL_BLOCK, 1, 1, 'reaction', 0)->setBlueprintTypeId(self::FERNITE_ALLOY + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has a cycle between the products of its steps');

        $this->provide($project);
    }

    #[Group('issue-121')]
    public function testTreeOfOneRunOfFerniteCarbideInATataraShowsOneFuelBlockNodeAndReferencesItsOtherConsumers(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;
        $project = $this->oneRunOfFerniteCarbide(10);

        $tree = $this->treeOf($project);

        self::assertSame(
            [[15, 1, 1, 'Raitaru', self::RAITARU_BASE_MATERIAL_BONUS]],
            array_map(static fn (array $node): array => [$node['quantity'], $node['runs'], $node['depth'], $node['structureName'], $node['structureBonus']], self::nodesOf($tree, self::HYDROGEN_FUEL_BLOCK)),
        );
        self::assertSame(
            ['typeId' => self::HYDROGEN_FUEL_BLOCK, 'typeName' => 'Hydrogen Fuel Block', 'quantity' => 5, 'isBuildable' => true, 'activityType' => 'manufacturing'],
            self::nodesOf($tree, self::FERNITE_ALLOY)[0]['materials'][0],
        );
        self::assertSame(
            [1, 10000, 'reaction', self::TATARA_REACTOR_RIG_BONUS, 'Tatara', self::REACTION_CATEGORY],
            [$tree['runs'], $tree['outputPerRun'], $tree['activityType'], $tree['structureBonus'], $tree['structureName'], $tree['productCategory']],
        );
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 5, self::FERNITE_ALLOY => 98, self::CERAMIC_POWDER => 98],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
        $this->assertMatchesSnapshot('fernite-carbide-1run-tatara-nullsec', $tree);
    }

    #[Group('issue-2')]
    public function testTreeOfTenRunsOfFerniteCarbideInATataraPlannedByTheFactoryShowsTenRunsOfOneHundredThousandUnits(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;
        $project = $this->projectOnGoldenSde($this->pilot, self::FERNITE_CARBIDE, 10, 10, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $tree = $this->treeOf($project);

        self::assertSame([100_000, 10], [$tree['quantity'], $tree['runs']]);
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 49, self::FERNITE_ALLOY => 974, self::CERAMIC_POWDER => 974],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
    }

    #[Group('issue-286')]
    public function testFuelBlockOfTenRunsOfFerniteCarbideInATataraHasTheNeedOfItsConsumerJobsInItsStepItsNodeAndTheShoppingList(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;
        $project = $this->projectOnGoldenSde($this->pilot, self::FERNITE_CARBIDE, 10, 10, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $tree = $this->treeOf($project);
        $fuelBlockLeaves = array_intersect_key(
            array_column($this->shoppingListBuilderOnGoldenSde()->getShoppingList($project)['materials'], 'quantity', 'typeId'),
            self::THREE_RUNS_OF_HYDROGEN_FUEL_BLOCK_AT_ME_TEN_IN_THE_RAITARU,
        );
        ksort($fuelBlockLeaves);

        self::assertSame(
            [[3, 99]],
            array_map(static fn (IndustryProjectStep $step): array => [$step->getRuns(), $step->getQuantity()], $this->stepsOf($project, self::HYDROGEN_FUEL_BLOCK)),
        );
        self::assertSame([self::FERNITE_CARBIDE => 49, self::FERNITE_ALLOY => 25, self::CERAMIC_POWDER => 25], self::rowQuantityOfEachConsumerOf($tree, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame([[99, 3]], self::quantityAndRunsOfNodesOf($tree, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame(self::THREE_RUNS_OF_HYDROGEN_FUEL_BLOCK_AT_ME_TEN_IN_THE_RAITARU, $fuelBlockLeaves);
    }

    #[Group('issue-286')]
    public function testFuelBlockOfOneUnsplitNomadInATataraHasTheRowOfEachConsumerJobWithTheReactorRigAndTheirSumInItsStepAndItsNode(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;
        $project = $this->projectOnGoldenSde($this->pilot, self::NOMAD, 1, 10, 20, self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        $tree = $this->treeOf($project);
        $fuelBlockRows = self::rowQuantityOfEachConsumerOf($tree, self::HYDROGEN_FUEL_BLOCK);

        self::assertSame(
            [self::FERNITE_ALLOY => [525, 2556], self::CERAMIC_POWDER => [666, 3243], self::FERNITE_CARBIDE => [953, 4640]],
            [
                self::FERNITE_ALLOY => [self::nodesOf($tree, self::FERNITE_ALLOY)[0]['runs'], $fuelBlockRows[self::FERNITE_ALLOY]],
                self::CERAMIC_POWDER => [self::nodesOf($tree, self::CERAMIC_POWDER)[0]['runs'], $fuelBlockRows[self::CERAMIC_POWDER]],
                self::FERNITE_CARBIDE => [self::nodesOf($tree, self::FERNITE_CARBIDE)[0]['runs'], $fuelBlockRows[self::FERNITE_CARBIDE]],
            ],
        );
        self::assertSame([13, 12109], [\count($fuelBlockRows), array_sum($fuelBlockRows)]);
        self::assertSame([[12109, 303]], self::quantityAndRunsOfNodesOf($tree, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame(
            [[303, 12109]],
            array_map(static fn (IndustryProjectStep $step): array => [$step->getRuns(), $step->getQuantity()], $this->stepsOf($project, self::HYDROGEN_FUEL_BLOCK)),
        );
    }

    #[Group('issue-121')]
    public function testFuelBlockConsumedByTenRunsOfReactionInATataraGetsTheReactorRigOfTheReactionJob(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;
        $project = $this->projectOnGoldenSde($this->pilot, self::CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE, 41, 0, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $reactionNode = self::nodesOf($this->treeOf($project), self::FERNITE_CARBIDE)[0];

        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 49, self::FERNITE_ALLOY => 974, self::CERAMIC_POWDER => 974],
            array_column($reactionNode['materials'], 'quantity', 'typeId'),
        );
        self::assertSame([10, 'Tatara', self::TATARA_REACTOR_RIG_BONUS], [$reactionNode['runs'], $reactionNode['structureName'], $reactionNode['structureBonus']]);
    }

    public function testTreeOfFerniteCarbideAtMeZeroShowsTheFuelBlockStepPlannedAtTheDefaultMeTenOfAnIntermediate(): void
    {
        $project = $this->oneRunOfFerniteCarbide(0);

        $fuelBlockNode = self::nodesOf($this->treeOf($project), self::HYDROGEN_FUEL_BLOCK)[0];

        self::assertSame(self::ONE_RUN_OF_FUEL_BLOCK_AT_ME_TEN + [17889 => 405], array_column($fuelBlockNode['materials'], 'quantity', 'typeId'));
    }

    #[Group('issue-121')]
    public function testTreeIgnoresTheMeOfTheProjectOnAReactionRoot(): void
    {
        $treeAtMeTen = $this->treeOf($this->oneRunOfFerniteCarbide(10));
        $treeAtMeZero = $this->treeOf($this->oneRunOfFerniteCarbide(0));

        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 5, self::FERNITE_ALLOY => 100, self::CERAMIC_POWDER => 100],
            array_column($treeAtMeTen['materials'], 'quantity', 'typeId'),
        );
        self::assertSame($treeAtMeZero['materials'][1], $treeAtMeTen['materials'][1]);
        self::assertSame(array_column($treeAtMeZero['materials'], 'quantity', 'typeId'), array_column($treeAtMeTen['materials'], 'quantity', 'typeId'));
    }

    #[Group('issue-121')]
    public function testTreeOfOneRunOfNitrogenFuelBlockAtMeZeroKeepsTheBaseQuantitiesOfTheSde(): void
    {
        $project = $this->projectOnGoldenSde($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 0, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $tree = $this->treeOf($project);

        self::assertSame(
            [44 => 4, 3683 => 22, 3689 => 4, 9832 => 9, self::ROBOTICS => 1, 16272 => 170, 16273 => 350, 16275 => 20, 17888 => 450],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
        self::assertSame([false], array_values(array_unique(array_column($tree['materials'], 'isBuildable'))));
    }

    #[Group('issue-121')]
    public function testTreeOfOneRunOfHailLAtMeTwoWithoutStructure(): void
    {
        $project = $this->projectOnGoldenSde($this->pilot, self::HAIL_L, 1, 2, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $tree = $this->treeOf($project);

        self::assertSame(
            [1, 5000, 'manufacturing', 0.0, null],
            [$tree['runs'], $tree['outputPerRun'], $tree['activityType'], $tree['structureBonus'], $tree['structureName']],
        );
        self::assertSame(
            [11399 => 15, self::RAM_AMMUNITION_TECH => 1, self::FERNITE_CARBIDE => 2940, self::FULLERIDES => 1176],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
        $ramNode = self::nodesOf($tree, self::RAM_AMMUNITION_TECH)[0];
        self::assertSame([1, 1, 100], [$ramNode['quantity'], $ramNode['runs'], $ramNode['outputPerRun']]);
        $ferniteCarbideNode = self::nodesOf($tree, self::FERNITE_CARBIDE)[0];
        self::assertSame([2940, 1], [$ferniteCarbideNode['quantity'], $ferniteCarbideNode['runs']]);
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 5, self::FERNITE_ALLOY => 100, self::CERAMIC_POWDER => 100],
            array_column($ferniteCarbideNode['materials'], 'quantity', 'typeId'),
        );
        $fulleridesNode = self::nodesOf($tree, self::FULLERIDES)[0];
        self::assertSame([1176, 1, 3000], [$fulleridesNode['quantity'], $fulleridesNode['runs'], $fulleridesNode['outputPerRun']]);
        self::assertSame([self::NITROGEN_FUEL_BLOCK => 5, 16659 => 100, 16662 => 100], array_column($fulleridesNode['materials'], 'quantity', 'typeId'));
    }

    #[Group('issue-121')]
    public function testOnlyTheNodeOfABlueprintWithACopyStepHasACopy(): void
    {
        $project = $this->oneRunOfFerniteCarbide(0);
        self::addStep($project, self::HYDROGEN_FUEL_BLOCK, 1, 1, 'copy');

        $tree = $this->treeOf($project);

        self::assertSame([true], array_column(self::nodesOf($tree, self::HYDROGEN_FUEL_BLOCK), 'hasCopy'));
        self::assertSame([false, false, false], [$tree['hasCopy'], self::nodesOf($tree, self::FERNITE_ALLOY)[0]['hasCopy'], self::nodesOf($tree, self::CERAMIC_POWDER)[0]['hasCopy']]);
    }

    #[Group('issue-121')]
    public function testTreeOfNitrogenFuelBlockWithEveryTypeInTheSdeKeepsTheSdeNames(): void
    {
        $project = $this->projectOnGoldenSde($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 0, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $tree = $this->treeOf($project);

        self::assertSame('Nitrogen Fuel Block', $tree['productTypeName']);
        self::assertSame(
            [44 => 'Enriched Uranium', 3683 => 'Oxygen', 3689 => 'Mechanical Parts', 9832 => 'Coolant', self::ROBOTICS => 'Robotics', 16272 => 'Heavy Water', 16273 => 'Liquid Ozone', 16275 => 'Strontium Clathrates', 17888 => 'Nitrogen Isotopes'],
            array_column($tree['materials'], 'typeName', 'typeId'),
        );
    }

    #[Group('issue-121')]
    public function testIntermediateStepWithoutRecipeIsShownAsANonBuildableMaterialAndKeepsTheTree(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 0);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 1, 0, 'manufacturing', 0);
        self::addStep($project, self::ROBOTICS, 1, 1, 'manufacturing', 0);

        $tree = $this->treeOf($project);

        self::assertSame(
            ['typeId' => self::ROBOTICS, 'typeName' => 'Robotics', 'quantity' => 1, 'isBuildable' => false, 'activityType' => null],
            $tree['materials'][4],
        );
    }

    #[Group('issue-121')]
    public function testBlacklistedIntermediateIsShownAsANonBuildableMaterialWithoutSubtree(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);
        $project = $this->oneRunOfFerniteCarbide(0);

        $tree = $this->treeOf($project);

        self::assertSame([], self::nodesOf($tree, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame(
            ['typeId' => self::HYDROGEN_FUEL_BLOCK, 'typeName' => 'Hydrogen Fuel Block', 'quantity' => 5, 'isBuildable' => false, 'activityType' => null],
            $tree['materials'][0],
        );
    }

    #[Group('issue-121')]
    public function testMaterialWhoseStepWasRemovedAndBlacklistedIntermediateAreBothShownAsNonBuildableMaterials(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK]);
        $project = $this->oneRunOfFerniteCarbide(0);
        $project->getSteps()->removeElement($this->onlyStepOf($project, self::FERNITE_ALLOY));

        $tree = $this->treeOf($project);

        self::assertSame(
            [
                ['typeId' => self::HYDROGEN_FUEL_BLOCK, 'typeName' => 'Hydrogen Fuel Block', 'quantity' => 5, 'isBuildable' => false, 'activityType' => null],
                ['typeId' => self::FERNITE_ALLOY, 'typeName' => 'Fernite Alloy', 'quantity' => 100, 'isBuildable' => false, 'activityType' => null],
            ],
            [$tree['materials'][0], $tree['materials'][1]],
        );
        self::assertSame([[], 1], [self::nodesOf($tree, self::FERNITE_ALLOY), \count(self::nodesOf($tree, self::CERAMIC_POWDER))]);
    }

    #[Group('issue-121')]
    #[Group('issue-193')]
    public function testTreeIsNullAndTheRootIsAFailedProductWhenTheRootStepHasNoBlueprintNorReaction(): void
    {
        $project = self::projectOf($this->pilot, self::ROBOTICS, 1, 10);
        self::addStep($project, self::ROBOTICS, 1, 0, 'manufacturing');

        $resource = $this->provide($project);

        self::assertSame(
            [null, [['typeId' => self::ROBOTICS, 'reason' => 'No blueprint or reaction found for product type ID 9848']]],
            [$resource->tree, $resource->failedProducts],
        );
    }

    #[Group('issue-121')]
    #[Group('issue-193')]
    public function testTreeIsNullAndTheRootIsAFailedProductWhenAMaterialOfTheRootIsMissingFromTheSde(): void
    {
        $project = $this->projectOnGoldenSde($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);
        $this->typeIdsMissingFromSde = [self::ROBOTICS];

        $resource = $this->provide($project);

        self::assertSame(
            [null, [['typeId' => self::NITROGEN_FUEL_BLOCK, 'reason' => 'Types missing from the SDE: 9848']]],
            [$resource->tree, $resource->failedProducts],
        );
    }

    #[Group('issue-121')]
    #[Group('issue-193')]
    public function testTreeIsNullAndTheIntermediateIsAFailedProductWhenAMaterialOfTheIntermediateIsMissingFromTheSde(): void
    {
        $project = $this->oneRunOfFerniteCarbide(10);
        $this->typeIdsMissingFromSde = [self::SCANDIUM];

        $resource = $this->provide($project);

        self::assertSame(
            [null, [['typeId' => self::FERNITE_ALLOY, 'reason' => 'Types missing from the SDE: 16639']]],
            [$resource->tree, $resource->failedProducts],
        );
    }

    #[Group('issue-193')]
    public function testProjectWhoseTypesAreAllInTheSdeKeepsItsTreeWithoutFailedProduct(): void
    {
        $project = $this->projectOnGoldenSde($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $resource = $this->provide($project);

        self::assertIsArray($resource->tree);
        self::assertSame(
            [self::ONE_RUN_OF_FUEL_BLOCK_AT_ME_TEN + [17888 => 405], []],
            [array_column($resource->tree['materials'], 'quantity', 'typeId'), $resource->failedProducts],
        );
    }

    #[Group('issue-193')]
    public function testEveryFailedProductIsListedByAscendingTypeId(): void
    {
        $project = $this->oneRunOfFerniteCarbide(0);
        $this->typeIdsMissingFromSde = [self::SCANDIUM, self::ROBOTICS];

        $resource = $this->provide($project);

        self::assertSame(
            [null, [
                ['typeId' => self::HYDROGEN_FUEL_BLOCK, 'reason' => 'Types missing from the SDE: 9848'],
                ['typeId' => self::FERNITE_ALLOY, 'reason' => 'Types missing from the SDE: 16639'],
            ]],
            [$resource->tree, $resource->failedProducts],
        );
    }

    #[Group('issue-193')]
    public function testIntermediateMissingFromTheSdeIsAFailedProductAlongWithItsConsumer(): void
    {
        $project = $this->oneRunOfFerniteCarbide(0);
        $this->typeIdsMissingFromSde = [self::FERNITE_ALLOY];

        $resource = $this->provide($project);

        self::assertSame(
            [null, [
                ['typeId' => self::FERNITE_ALLOY, 'reason' => 'Types missing from the SDE: 16656'],
                ['typeId' => self::FERNITE_CARBIDE, 'reason' => 'Types missing from the SDE: 16656'],
            ]],
            [$resource->tree, $resource->failedProducts],
        );
    }

    #[Group('issue-193')]
    public function testBlacklistedIntermediateWithAMaterialMissingFromTheSdeIsAFailedProductBesideTheShownTree(): void
    {
        $project = $this->oneRunOfFerniteCarbide(0);
        $this->pilot->setIndustryBlacklistTypeIds([self::FERNITE_ALLOY]);
        $this->typeIdsMissingFromSde = [self::SCANDIUM];

        $resource = $this->provide($project);

        self::assertIsArray($resource->tree);
        self::assertSame(
            [
                [['typeId' => self::FERNITE_ALLOY, 'typeName' => 'Fernite Alloy', 'quantity' => 100, 'isBuildable' => false, 'activityType' => null]],
                [],
                [['typeId' => self::FERNITE_ALLOY, 'reason' => 'Types missing from the SDE: 16639']],
            ],
            [
                array_values(array_filter($resource->tree['materials'], static fn (array $material): bool => $material['typeId'] === self::FERNITE_ALLOY)),
                self::nodesOf($resource->tree, self::FERNITE_ALLOY),
                $resource->failedProducts,
            ],
        );
    }

    #[Group('issue-193')]
    public function testStepWithoutRecipeConsumedByNoStepIsAFailedProductBesideTheUnchangedTree(): void
    {
        $treeWithoutTheAddedStep = $this->treeOf($this->oneRunOfFerniteCarbide(0));
        $project = $this->oneRunOfFerniteCarbide(0);
        self::addStep($project, self::OXYGEN_ISOTOPES, 1, 1, 'manufacturing');

        $resource = $this->provide($project);

        self::assertSame(
            [$treeWithoutTheAddedStep, [['typeId' => self::OXYGEN_ISOTOPES, 'reason' => 'No blueprint or reaction found for product type ID 17887']]],
            [$resource->tree, $resource->failedProducts],
        );
    }

    #[Group('issue-193')]
    public function testProjectReadResolvesTheNeedsOfItsStepsOnce(): void
    {
        $project = $this->oneRunOfFerniteCarbide(10);
        $this->typeIdsMissingFromSde = [self::SCANDIUM];

        $this->provide($project);

        self::assertSame(1, $this->productsOfBlueprintsReadCount);
    }

    #[Group('issue-121')]
    public function testRootBlacklistedAfterItsStepWasPlannedKeepsItsNeedWithoutRunsNorMaterials(): void
    {
        $project = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);
        $this->pilot->setIndustryBlacklistTypeIds([self::QUANTUM_MICROPROCESSOR]);

        $tree = $this->treeOf($project);

        self::assertSame([32, 0, []], [$tree['quantity'], $tree['runs'], $tree['materials']]);
    }

    #[Group('issue-121')]
    public function testPurchasedRootKeepsItsNeedWithoutRunsNorMaterials(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 25, 0, 'manufacturing')->setPurchased(true);

        $tree = $this->treeOf($project);

        self::assertSame([1000, 0, []], [$tree['quantity'], $tree['runs'], $tree['materials']]);
    }

    #[Group('issue-121')]
    public function testRootStepKeepsItsPersistedRunsWhateverItsStock(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10);
        self::addStep($project, self::NITROGEN_FUEL_BLOCK, 25, 0, 'manufacturing')->setInStockQuantity(1000);

        $tree = $this->treeOf($project);

        self::assertSame([1000, 25], [$tree['quantity'], $tree['runs']]);
        self::assertSame(
            [44 => 90, 3683 => 495, 3689 => 90, 9832 => 203, self::ROBOTICS => 25, 16272 => 3825, 16273 => 7875, 16275 => 450, 17888 => 10125],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
    }

    #[Group('issue-121')]
    public function testManuallySplitRootKeepsTheRunsOfEachOfItsJobs(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::TITANIUM_CARBIDE]);
        $project = $this->thirtyTwoQuantumMicroprocessors(self::DEFAULT_MAX_JOB_DURATION_DAYS);
        $rootStep = $this->onlyStepOf($project, self::QUANTUM_MICROPROCESSOR)->setRuns(21)->setQuantity(21)->setSplitGroupId('manual-split')->setSplitIndex(0)->setTotalGroupRuns(32);
        $project->addStep((clone $rootStep)->setRuns(11)->setQuantity(11)->setSplitIndex(1));

        $tree = $this->treeOf($project);

        self::assertSame([32, 32], [$tree['quantity'], $tree['runs']]);
        self::assertSame(322 + 169, array_column($tree['materials'], 'quantity', 'typeId')[self::TITANIUM_CARBIDE]);
    }

    #[Group('issue-121')]
    public function testNodeOfASplitGroupTakesTheStructureOfTheJobOfSplitIndexZeroWhateverTheOrderOfItsSteps(): void
    {
        $project = $this->thirtyTwoQuantumMicroprocessors(self::SHORT_MAX_JOB_DURATION_DAYS);
        $jobs = $this->stepsOf($project, self::QUANTUM_MICROPROCESSOR);
        $jobs[0]->setStructureConfig(self::structure($this->pilot, 'raitaru'));
        $jobs[2]->setStructureConfig(self::structure($this->pilot, 'station'));
        foreach (array_reverse($jobs) as $job) {
            $project->getSteps()->removeElement($job);
            $project->addStep($job);
        }

        $tree = $this->treeOf($project);

        self::assertSame(['Raitaru', self::RAITARU_BASE_MATERIAL_BONUS], [$tree['structureName'], $tree['structureBonus']]);
        self::assertSame(167 + 169 + 153, array_column($tree['materials'], 'quantity', 'typeId')[self::TITANIUM_CARBIDE]);
    }

    #[Group('issue-121')]
    public function testSplitGroupWithOnePurchasedJobIsBoughtAsAWhole(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::HYDROGEN_FUEL_BLOCK, self::SYLRAMIC_FIBERS]);
        $project = $this->projectOnGoldenSde($this->pilot, self::CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE, 11, 10, 20, self::SHORT_MAX_JOB_DURATION_DAYS);
        $this->stepsOf($project, self::FERNITE_CARBIDE)[1]->setPurchased(true);

        $ferniteCarbideNode = self::nodesOf($this->treeOf($project), self::FERNITE_CARBIDE)[0];

        self::assertSame([22_000, 0, []], [$ferniteCarbideNode['quantity'], $ferniteCarbideNode['runs'], $ferniteCarbideNode['materials']]);
    }

    #[Group('issue-121')]
    public function testStepWithMoreStockThanItsNeedHasNoRunNorMaterials(): void
    {
        $project = self::projectOf($this->pilot, self::FENRIR, 1, 10);
        self::addStep($project, self::FENRIR, 1, 0, 'manufacturing');
        self::addStep($project, self::CAPITAL_ARMOR_PLATES, 5, 1, 'manufacturing')->setInStockQuantity(6);

        $capitalArmorPlatesNode = self::nodesOf($this->treeOf($project), self::CAPITAL_ARMOR_PLATES)[0];

        self::assertSame([5, 0, []], [$capitalArmorPlatesNode['quantity'], $capitalArmorPlatesNode['runs'], $capitalArmorPlatesNode['materials']]);
    }

    #[Group('issue-121')]
    public function testNeedOfAnIntermediateDropsByTheNeedOfEachOfItsPurchasedConsumers(): void
    {
        $project = $this->oneRunOfFerniteCarbide(10);
        $this->onlyStepOf($project, self::FERNITE_ALLOY)->setPurchased(true);
        $this->onlyStepOf($project, self::CERAMIC_POWDER)->setPurchased(true);

        $tree = $this->treeOf($project);

        self::assertSame([[5, 1]], self::quantityAndRunsOfNodesOf($tree, self::HYDROGEN_FUEL_BLOCK));
    }

    #[Group('issue-121')]
    public function testRowsOfAnIntermediateSharedInATataraSumToTheQuantityOfItsNode(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;
        $project = $this->oneRunOfFerniteCarbide(10);

        $tree = $this->treeOf($project);

        self::assertSame([5, 5, 5], self::quantitiesOfRowsOf($tree, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame([[15, 1]], self::quantityAndRunsOfNodesOf($tree, self::HYDROGEN_FUEL_BLOCK));
    }

    public function testStepsAreMappedToResources(): void
    {
        $project = $this->projectOnGoldenSde($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 0, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame([$this->mappedStep], $this->provide($project)->steps);
    }

    public function testProjectOfAnotherPilotIsNotFound(): void
    {
        $project = self::projectOf(new User(), self::NITROGEN_FUEL_BLOCK, 1, 0);

        $this->expectException(NotFoundHttpException::class);

        $this->provide($project);
    }

    public function testUnknownProjectIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->providerOnGoldenSde($this->pilot)->provide(new Get(), ['id' => Uuid::v4()->toRfc4122()]);
    }

    public function testAnonymousRequestIsUnauthorized(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 0);
        $this->requestedProject = self::withId($project);

        $this->expectException(UnauthorizedHttpException::class);

        $this->providerOnGoldenSde(null)->provide(new Get(), ['id' => $project->getId()->toRfc4122()]);
    }

    private function pilotBonusService(): IndustryBonusService
    {
        return new TataraAndRaitaruBonusService(
            fn (): bool => $this->pilotOwnsTataraAndRaitaru,
            static fn (int $typeId): bool => (self::goldenRecipes()[$typeId]['activity'] ?? null) === 'reaction',
            $this->createStub(IndustryRigCategoryRepository::class),
            $this->pilotStructureConfigRepository(),
            $this->typeRepositoryOnGoldenSde(),
            $this->productRepositoryOnGoldenSde(),
        );
    }

    private function providerOnGoldenSde(?User $authenticatedPilot): ProjectProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($authenticatedPilot);

        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturnCallback(
            fn (Uuid $id): ?IndustryProject => $this->requestedProject !== null && $id->equals($this->requestedProject->getId()) ? $this->requestedProject : null,
        );

        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('projectToResource')->willReturnCallback(static fn (): ProjectResource => new ProjectResource());
        $mapper->method('stepToResource')->willReturn($this->mappedStep);

        $typeRepository = $this->typeRepositoryOnGoldenSde();
        $productRepository = $this->productRepositoryCountingTheProductsOfBlueprintsReads();
        $materialRepository = $this->materialRepositoryOnGoldenSde();
        $entityManager = $this->entityManagerWithoutRows();
        $bonusService = $this->pilotBonusService();

        return self::autowired(ProjectProvider::class, [
            $security,
            $projectRepository,
            $mapper,
            new IndustryTreeService($productRepository, $materialRepository, $typeRepository, $entityManager, $bonusService),
            new IndustryBlacklistService($entityManager),
            $bonusService,
            $this->calculationServiceOnGoldenSde($bonusService, $this->pilotStructureConfigRepository()),
            new TypeNameResolver($typeRepository),
            $typeRepository,
            $productRepository,
            $materialRepository,
            $this->activityRepositoryOnGoldenSde(),
            $entityManager,
        ]);
    }

    private function productRepositoryCountingTheProductsOfBlueprintsReads(): IndustryActivityProductRepository
    {
        $goldenProductRepository = $this->productRepositoryOnGoldenSde();

        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback($goldenProductRepository->findBlueprintForProduct(...));
        $productRepository->method('findProductsForBlueprints')->willReturnCallback(
            function (array $blueprintTypeIds, array $activityIds) use ($goldenProductRepository): array {
                ++$this->productsOfBlueprintsReadCount;

                return $goldenProductRepository->findProductsForBlueprints($blueprintTypeIds, $activityIds);
            },
        );

        return $productRepository;
    }

    private function provide(IndustryProject $project): ProjectResource
    {
        $this->requestedProject = self::withId($project);

        return $this->providerOnGoldenSde($this->pilot)->provide(new Get(), ['id' => $project->getId()->toRfc4122()]);
    }

    private function treeOf(IndustryProject $project): array
    {
        $tree = $this->provide($project)->tree;
        self::assertIsArray($tree);

        return $tree;
    }

    private function thirtyTwoQuantumMicroprocessors(float $maxJobDurationDays): IndustryProject
    {
        return $this->projectOnGoldenSde($this->pilot, self::QUANTUM_MICROPROCESSOR, 32, 10, 20, $maxJobDurationDays);
    }

    private function oneRunOfFerniteCarbide(int $meLevel): IndustryProject
    {
        return $this->projectOnGoldenSde($this->pilot, self::FERNITE_CARBIDE, 1, $meLevel, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);
    }

    private static function withId(IndustryProject $project): IndustryProject
    {
        if ($project->getId() === null) {
            (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($project, Uuid::v4());
        }

        return $project;
    }

    private function assertMatchesSnapshot(string $name, array $actual): void
    {
        $snapshotFile = self::SNAPSHOT_DIR.$name.'.json';
        if (getenv('UPDATE_SNAPSHOTS') === '1') {
            file_put_contents($snapshotFile, json_encode($actual, \JSON_PRETTY_PRINT | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
        }
        if (!is_file($snapshotFile)) {
            self::fail("Missing snapshot {$snapshotFile}: run the test with UPDATE_SNAPSHOTS=1 to write it from the current output.");
        }

        self::assertSame(json_decode((string) file_get_contents($snapshotFile), true, flags: \JSON_THROW_ON_ERROR), $actual);
    }

    private static function nodesOf(array $tree, int $productTypeId): array
    {
        return array_values(array_filter(self::allNodesOf($tree), static fn (array $node): bool => $node['productTypeId'] === $productTypeId));
    }

    private static function quantityAndRunsOfNodesOf(array $tree, int $productTypeId): array
    {
        return array_map(static fn (array $node): array => [$node['quantity'], $node['runs']], self::nodesOf($tree, $productTypeId));
    }

    private static function quantitiesOfRowsOf(array $tree, int $typeId): array
    {
        $quantities = [];
        foreach (self::allNodesOf($tree) as $node) {
            foreach ($node['materials'] as $material) {
                if ($material['typeId'] === $typeId) {
                    $quantities[] = $material['quantity'];
                }
            }
        }

        return $quantities;
    }

    private static function rowQuantityOfEachConsumerOf(array $tree, int $typeId): array
    {
        $quantityByConsumer = [];
        foreach (self::allNodesOf($tree) as $node) {
            foreach ($node['materials'] as $material) {
                if ($material['typeId'] === $typeId) {
                    $quantityByConsumer[$node['productTypeId']] = $material['quantity'];
                }
            }
        }

        return $quantityByConsumer;
    }

    private static function nodeCountOf(array $tree): int
    {
        return \count(self::allNodesOf($tree));
    }

    private static function allNodesOf(array $node): array
    {
        $nodes = [$node];
        foreach ($node['materials'] as $material) {
            if (isset($material['blueprint'])) {
                $nodes = [...$nodes, ...self::allNodesOf($material['blueprint'])];
            }
        }

        return $nodes;
    }

    private static function referenceCountOf(array $tree, int $typeId): int
    {
        return self::leafQuantitiesAndNodeCountsOf($tree)['referenceCounts'][$typeId] ?? 0;
    }

    private static function leafQuantitiesOf(array $tree): array
    {
        return self::leafQuantitiesAndNodeCountsOf($tree)['leafQuantities'];
    }

    private static function leafQuantitiesAndNodeCountsOf(array $tree): array
    {
        $leafQuantities = [];
        $jobCounts = [];
        $referenceCounts = [];
        foreach (self::allNodesOf($tree) as $node) {
            $jobCounts[$node['productTypeId']] = ($jobCounts[$node['productTypeId']] ?? 0) + 1;
            foreach ($node['materials'] as $material) {
                if (isset($material['blueprint'])) {
                    continue;
                }
                if ($material['isBuildable']) {
                    $referenceCounts[$material['typeId']] = ($referenceCounts[$material['typeId']] ?? 0) + 1;
                    continue;
                }
                $leafQuantities[$material['typeId']] = ($leafQuantities[$material['typeId']] ?? 0) + $material['quantity'];
            }
        }
        ksort($leafQuantities);
        ksort($jobCounts);
        ksort($referenceCounts);

        return ['leafQuantities' => $leafQuantities, 'jobCounts' => $jobCounts, 'referenceCounts' => $referenceCounts];
    }

    private static function leafDifferencesWithRavworks(array $leafQuantities): array
    {
        $golden = json_decode((string) file_get_contents(self::GOLDEN_DIR.'nomad-1run-me10-no-structure.ravworks.json'), true, flags: \JSON_THROW_ON_ERROR);
        $differences = [];
        foreach (array_column($golden['expected']['leaves'], 'quantity', 'typeId') as $typeId => $quantity) {
            $difference = ($leafQuantities[$typeId] ?? 0) - $quantity;
            if ($difference !== 0) {
                $differences[$typeId] = $difference;
            }
        }
        foreach (array_diff_key($leafQuantities, array_column($golden['expected']['leaves'], 'quantity', 'typeId')) as $typeId => $quantity) {
            $differences[$typeId] = $quantity;
        }
        ksort($differences);

        return $differences;
    }

    private static function sortedByTypeId(array $quantities): array
    {
        ksort($quantities);

        return $quantities;
    }
}
