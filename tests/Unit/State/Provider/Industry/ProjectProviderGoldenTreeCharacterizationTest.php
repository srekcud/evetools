<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Industry\ProjectStepResource;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryRigCategoryRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryTreeService;
use App\Service\Industry\ProductionTreeFromSteps;
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
    private const int NOMAD = 28846;
    private const int ROBOTICS = 9848;
    private const int HAIL_L = 12779;
    private const int RAM_AMMUNITION_TECH = 11476;
    private const int FULLERIDES = 16679;
    private const int COOLANT = 9832;
    private const int ISOGEN = 37;
    private const int NOCXIUM = 38;
    private const int MEGACYTE = 40;

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

    private bool $pilotOwnsTataraAndRaitaru = false;
    private User $pilot;
    private ?IndustryProject $requestedProject = null;
    private ProjectStepResource $mappedStep;

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
    public function testTreeOfTwentyFiveRunsOfNitrogenFuelBlockPlannedByTheFactoryCurrentlyShowsOneRunIssue2(): void
    {
        $project = $this->projectOnGoldenSde($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $tree = $this->treeOf($project);

        self::assertSame(
            [25, 1, 40, 'manufacturing', 0.0, null],
            [$tree['quantity'], $tree['runs'], $tree['outputPerRun'], $tree['activityType'], $tree['structureBonus'], $tree['structureName']],
        );
        self::assertSame(self::ONE_RUN_OF_FUEL_BLOCK_AT_ME_TEN + [17888 => 405], array_column($tree['materials'], 'quantity', 'typeId'));
        $this->assertMatchesSnapshot('nitrogen-fuel-block-25runs-me10-npc', $tree);
    }

    #[Group('issue-121')]
    public function testTreeOfAProjectWithoutStepIsNull(): void
    {
        $project = self::projectOf($this->pilot, self::NITROGEN_FUEL_BLOCK, 25, 10);

        self::assertNull($this->provide($project)->tree);
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
    public function testTreeOfTenRunsOfFerniteCarbideInATataraPlannedByTheFactoryCurrentlyShowsOneRunIssue2(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;
        $project = $this->projectOnGoldenSde($this->pilot, self::FERNITE_CARBIDE, 10, 10, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $tree = $this->treeOf($project);

        self::assertSame([10, 1], [$tree['quantity'], $tree['runs']]);
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 5, self::FERNITE_ALLOY => 98, self::CERAMIC_POWDER => 98],
            array_column($tree['materials'], 'quantity', 'typeId'),
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

    #[Group('issue-15')]
    public function testTreeOfFerniteCarbideAtMeZeroCurrentlyShowsTheFuelBlockStepPlannedAtHardCodedMeTenIssue15(): void
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
    public function testTreeIsNullWhenTheRootStepHasNoBlueprintNorReaction(): void
    {
        $project = self::projectOf($this->pilot, self::ROBOTICS, 1, 10);
        self::addStep($project, self::ROBOTICS, 1, 0, 'manufacturing');

        self::assertNull($this->provide($project)->tree);
    }

    #[Group('issue-121')]
    public function testTreeIsNullWhenATypeOfAShownNodeIsMissingFromTheSde(): void
    {
        $project = $this->projectOnGoldenSde($this->pilot, self::NITROGEN_FUEL_BLOCK, 1, 10, 20, self::DEFAULT_MAX_JOB_DURATION_DAYS);
        $this->typeIdsMissingFromSde = [self::ROBOTICS];

        self::assertNull($this->provide($project)->tree);
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
        $productRepository = $this->productRepositoryOnGoldenSde();
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
