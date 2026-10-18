<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Industry;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Industry\ProjectResource;
use App\ApiResource\Industry\ProjectStepResource;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Exception\TypeMissingFromSdeException;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryTreeService;
use App\State\Provider\Industry\IndustryResourceMapper;
use App\State\Provider\Industry\ProjectProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Characterization of the production tree displayed by GET /industry/projects/{id} (ProjectProvider::provide()->tree),
 * before buildProductionTree() is replaced by src/Industry/Application (docs/specs/industry-engine.md, §7 rank 4).
 *
 * The real IndustryTreeService and IndustryBlacklistService run on the SDE extract of the goldens
 * (tests/Fixtures/Golden/sde-blueprint-recipes.json). Stubbed: the SDE repositories, the project repository,
 * the database connection, the security token, the resource mapper (steps and costs are out of scope here) and the
 * structure lookup of IndustryBonusService (the goldens fix the formula, not the structure choice).
 *
 * Trees are pinned in JSON snapshots in tests/Fixtures/Characterization/ProjectProvider/ (the Nomad snapshot is reduced
 * to leaf totals and job counts per type). A missing snapshot fails the test; snapshots are (re)written only when the
 * environment variable UPDATE_SNAPSHOTS=1 is set. Blueprint type IDs in the snapshots are synthetic (product type ID +
 * 1000000) and hasCopy is false unless a test declares a copying activity.
 */
#[CoversClass(ProjectProvider::class)]
#[CoversClass(IndustryTreeService::class)]
#[AllowMockObjectsWithoutExpectations]
final class ProjectProviderGoldenTreeCharacterizationTest extends TestCase
{
    private const string GOLDEN_DIR = __DIR__.'/../../../../Fixtures/Golden/';
    private const string SNAPSHOT_DIR = __DIR__.'/../../../../Fixtures/Characterization/ProjectProvider/';
    private const int BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;

    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;
    private const int NOMAD = 28846;
    private const int ROBOTICS = 9848;
    private const int HAIL_L = 12779;
    private const int RAM_AMMUNITION_TECH = 11476;
    private const int FULLERIDES = 16679;
    private const int CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE = 29049;

    private const string FUEL_BLOCK_CATEGORY = 'ammunition';
    private const string REACTION_CATEGORY = 'composite_reaction';
    // Synthetic category: no structure of the pilot covers it
    private const string ROBOTICS_CATEGORY = 'planetary_commodity';
    // Raitaru without rig: 1 % structure material bonus (IndustryBonusService::STRUCTURE_MATERIAL_BONUSES)
    private const float RAITARU_BASE_MATERIAL_BONUS = 1.0;
    private const float RAITARU_MATERIAL_RIG_BONUS = 2.0;
    // 1 - 0.99 × 0.98
    private const float RAITARU_WITH_RIG_TOTAL_MATERIAL_BONUS = 2.98;
    // Tatara in nullsec with Standup L-Set Reactor Efficiency II: 2.4 % × 1.1
    private const float TATARA_REACTOR_RIG_BONUS = 2.64;

    /** @var array{recipes: array<string, array{name: string, activity: string, outputPerRun: int, materials: array<string, int>}>, typeNames: array<string, string>} */
    private array $sde;

    private bool $pilotOwnsTataraAndRaitaru = false;
    private bool $raitaruHasMaterialRig = false;
    private int $copyingActivityCount = 0;

    /** @var list<int> */
    private array $typeIdsWithoutSdeName = [];

    /** @var list<int> */
    private array $typeIdsWithoutRecipe = [];

    /** @var list<int> */
    private array $blacklistedTypeIds = [];

    private User $pilot;
    private IndustryProject $project;
    private Security $security;
    private IndustryTreeService $treeService;
    private ProjectProvider $provider;
    private ProjectStepResource $mappedStep;

    protected function setUp(): void
    {
        $this->sde = json_decode((string) file_get_contents(self::GOLDEN_DIR.'sde-blueprint-recipes.json'), true, flags: \JSON_THROW_ON_ERROR);

        $this->pilot = $this->createStub(User::class);
        $this->pilot->method('getIndustryBlacklistTypeIds')->willReturnCallback(fn (): array => $this->blacklistedTypeIds);
        $this->pilot->method('getIndustryBlacklistGroupIds')->willReturn([]);

        $this->project = (new IndustryProject())->setUser($this->pilot);
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($this->project, Uuid::v4());

        $this->security = $this->createStub(Security::class);
        $this->security->method('getUser')->willReturn($this->pilot);

        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('find')->willReturnCallback(
            fn (Uuid $id): ?IndustryProject => $id->equals($this->project->getId()) ? $this->project : null,
        );

        $mapper = $this->createStub(IndustryResourceMapper::class);
        $mapper->method('projectToResource')->willReturnCallback(static fn (): ProjectResource => new ProjectResource());
        $this->mappedStep = new ProjectStepResource();
        $mapper->method('stepToResource')->willReturn($this->mappedStep);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(fn (): int => $this->copyingActivityCount);
        $entityManager->method('getConnection')->willReturn($connection);

        $this->treeService = $this->treeServiceOnGoldenSde($entityManager);
        $this->provider = new ProjectProvider(
            $this->security,
            $projectRepository,
            $mapper,
            $this->treeService,
            new IndustryBlacklistService($entityManager),
        );
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2 (B1) : les 25 runs du projet sont lus comme
    // une quantité. L'arbre affiche 1 run (40 unités) au lieu de 25 runs (1000 unités) : Nitrogen Isotopes 405 au lieu
    // de 10125, Robotics 1 au lieu de 25 (golden nitrogen-fuel-block-25runs-me10-npc).
    public function testTreeOfTwentyFiveRunsOfNitrogenFuelBlockCurrentlyShowsOneRunIssue2(): void
    {
        $tree = $this->treeOfProject(self::NITROGEN_FUEL_BLOCK, runs: 25, meLevel: 10);

        self::assertSame(
            [25, 1, 40, 'manufacturing', 0.0, null],
            [$tree['quantity'], $tree['runs'], $tree['outputPerRun'], $tree['activityType'], $tree['structureBonus'], $tree['structureName']],
        );
        self::assertSame(
            [44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18, 17888 => 405],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
        $this->assertMatchesSnapshot('nitrogen-fuel-block-25runs-me10-npc', $tree);
    }

    public function testTreeOfOneRunOfNitrogenFuelBlockAtMeZeroKeepsTheBaseQuantitiesOfTheSde(): void
    {
        $tree = $this->treeOfProject(self::NITROGEN_FUEL_BLOCK, runs: 1, meLevel: 0);

        self::assertSame(
            [44 => 4, 3683 => 22, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 170, 16273 => 350, 16275 => 20, 17888 => 450],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
        self::assertSame([false], array_values(array_unique(array_column($tree['materials'], 'isBuildable'))));
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #16 : le Hydrogen Fuel Block, consommé à deux
    // profondeurs (réaction racine et les deux réactions intermédiaires), est un sous-arbre par branche : 3 jobs d'un run
    // au lieu d'un job agrégé. Tatara nullsec (L-Set Reactor Efficiency II) et Raitaru sans rig.
    public function testTreeOfOneRunOfFerniteCarbideInATataraCurrentlyHasOneFuelBlockJobPerBranchIssue16(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $tree = $this->treeOfProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 10);

        self::assertSame(
            [1, 1, 10000, 'reaction', self::TATARA_REACTOR_RIG_BONUS, 'Tatara', self::REACTION_CATEGORY],
            [$tree['quantity'], $tree['runs'], $tree['outputPerRun'], $tree['activityType'], $tree['structureBonus'], $tree['structureName'], $tree['productCategory']],
        );
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 5, self::FERNITE_ALLOY => 98, self::CERAMIC_POWDER => 98],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
        $fuelBlockJobs = $this->blueprintNodesOf($tree, self::HYDROGEN_FUEL_BLOCK);
        self::assertSame(
            [[5, 1, 1, 'Raitaru', self::RAITARU_BASE_MATERIAL_BONUS], [5, 1, 2, 'Raitaru', self::RAITARU_BASE_MATERIAL_BONUS], [5, 1, 2, 'Raitaru', self::RAITARU_BASE_MATERIAL_BONUS]],
            array_map(static fn (array $job): array => [$job['quantity'], $job['runs'], $job['depth'], $job['structureName'], $job['structureBonus']], $fuelBlockJobs),
        );
        $this->assertMatchesSnapshot('fernite-carbide-1run-tatara-nullsec', $tree);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2 (B1) : les 10 runs du projet sont lus comme une
    // quantité ; 10 unités à 10000 par run donnent 1 run. Ceramic Powder et Fernite Alloy 98 au lieu de 974 (golden
    // fernite-carbide-10runs-tatara-nullsec).
    public function testTreeOfTenRunsOfFerniteCarbideInATataraCurrentlyShowsOneRunIssue2(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $tree = $this->treeOfProject(self::FERNITE_CARBIDE, runs: 10, meLevel: 10);

        self::assertSame([10, 1], [$tree['quantity'], $tree['runs']]);
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 5, self::FERNITE_ALLOY => 98, self::CERAMIC_POWDER => 98],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #6 (R4) : dans un job de réaction, le Hydrogen
    // Fuel Block reçoit le bonus de la meilleure structure de SA catégorie (Raitaru, ×0,99) au lieu du rig du job
    // consommateur (Tatara, ×0,9736). Le golden fernite-carbide-10runs-tatara-nullsec attend 49 (ceil(50 × 0,9736))
    // pour 10 runs de réaction ; l'arbre affiche 50 (ceil(50 × 0,99)). L'écart n'apparaît qu'à partir de 8 runs de
    // réaction, inatteignables par une racine de réaction tant que #2 lit les runs comme une quantité : la réaction est
    // donc un intermédiaire d'une racine à 1 unité par run. 41 runs de plaque × 2222 × 0,99 (Raitaru du stub) = 90191
    // Fernite Carbide, soit 10 runs de réaction. Fernite Alloy et Ceramic Powder suivent le golden (974).
    public function testFuelBlockConsumedByTenRunsOfReactionCurrentlyGetsTheBonusOfItsOwnCategoryIssue6(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $tree = $this->treeOfProject(self::CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE, runs: 41, meLevel: 0);

        $reactionJob = $this->blueprintNodesOf($tree, self::FERNITE_CARBIDE)[0];
        self::assertSame([90191, 10, 'Tatara'], [$reactionJob['quantity'], $reactionJob['runs'], $reactionJob['structureName']]);
        self::assertSame(50, array_column($reactionJob['materials'], 'quantity', 'typeId')[self::HYDROGEN_FUEL_BLOCK]);
        self::assertSame(
            [self::FERNITE_ALLOY => 974, self::CERAMIC_POWDER => 974],
            array_diff_key(array_column($reactionJob['materials'], 'quantity', 'typeId'), [self::HYDROGEN_FUEL_BLOCK => true]),
        );
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #15 : chaque intermédiaire fabriqué reçoit un
    // ME 10 en dur, quel que soit le ME du projet. À ME 0, le Hydrogen Fuel Block consommé par la réaction calcule
    // ses matériaux à ME 10 (Robotics max(1 run, 0,9) = 1, Hydrogen Isotopes 450 × 0,9 = 405).
    public function testTreeOfFerniteCarbideAtMeZeroCurrentlyBuildsTheFuelBlockIntermediateAtHardCodedMeTenIssue15(): void
    {
        $tree = $this->treeOfProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 0);

        $fuelBlockJob = $this->blueprintNodesOf($tree, self::HYDROGEN_FUEL_BLOCK)[0];
        self::assertSame(
            [44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, 9848 => 1, 16272 => 153, 16273 => 315, 16275 => 18, 17889 => 405],
            array_column($fuelBlockJob['materials'], 'quantity', 'typeId'),
        );
    }

    public function testTreeIgnoresTheMeOfTheProjectOnAReactionRoot(): void
    {
        $treeAtMeTen = $this->treeOfProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 10);
        $treeAtMeZero = $this->treeOfProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 0);

        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 5, self::FERNITE_ALLOY => 100, self::CERAMIC_POWDER => 100],
            array_column($treeAtMeTen['materials'], 'quantity', 'typeId'),
        );
        self::assertSame($treeAtMeZero, $treeAtMeTen);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issues #69 et #16 : la demande d'un intermédiaire
    // n'est pas agrégée entre branches avant l'arrondi en runs. Chaque consommateur a son propre sous-arbre : 50 jobs
    // de Hydrogen Fuel Block et 21 de Nitrogen Fuel Block. Le golden Ravworks (fait foi) a un job par intermédiaire.
    public function testTreeOfOneNomadCurrentlyRepeatsEachIntermediatePerConsumerIssues69And16(): void
    {
        $tree = $this->treeOfProject(self::NOMAD, runs: 1, meLevel: 10);

        self::assertSame(
            [1, 1, 1, 'manufacturing', 0.0, null],
            [$tree['quantity'], $tree['runs'], $tree['outputPerRun'], $tree['activityType'], $tree['structureBonus'], $tree['structureName']],
        );
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 50, self::NITROGEN_FUEL_BLOCK => 21],
            [self::HYDROGEN_FUEL_BLOCK => count($this->blueprintNodesOf($tree, self::HYDROGEN_FUEL_BLOCK)), self::NITROGEN_FUEL_BLOCK => count($this->blueprintNodesOf($tree, self::NITROGEN_FUEL_BLOCK))],
        );
        $this->assertMatchesSnapshot('nomad-1run-me10-no-structure-leaves-and-job-counts', $this->leafQuantitiesAndJobCountsOf($tree));
    }

    public function testTreeOfOneRunOfHailLAtMeTwoWithoutStructure(): void
    {
        $tree = $this->treeOfProject(self::HAIL_L, runs: 1, meLevel: 2);

        self::assertSame(
            [1, 1, 5000, 'manufacturing', 0.0, null],
            [$tree['quantity'], $tree['runs'], $tree['outputPerRun'], $tree['activityType'], $tree['structureBonus'], $tree['structureName']],
        );
        self::assertSame(
            [11399 => 15, self::RAM_AMMUNITION_TECH => 1, self::FERNITE_CARBIDE => 2940, self::FULLERIDES => 1176],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
        $ramJob = $this->blueprintNodesOf($tree, self::RAM_AMMUNITION_TECH)[0];
        self::assertSame([1, 1, 100], [$ramJob['quantity'], $ramJob['runs'], $ramJob['outputPerRun']]);
        self::assertSame(
            [34 => 501, 35 => 400, 36 => 200, 37 => 74, 38 => 33],
            array_column($ramJob['materials'], 'quantity', 'typeId'),
        );
        $ferniteCarbideJob = $this->blueprintNodesOf($tree, self::FERNITE_CARBIDE)[0];
        self::assertSame([2940, 1], [$ferniteCarbideJob['quantity'], $ferniteCarbideJob['runs']]);
        self::assertSame(
            [self::HYDROGEN_FUEL_BLOCK => 5, self::FERNITE_ALLOY => 100, self::CERAMIC_POWDER => 100],
            array_column($ferniteCarbideJob['materials'], 'quantity', 'typeId'),
        );
        $fulleridesJob = $this->blueprintNodesOf($tree, self::FULLERIDES)[0];
        self::assertSame([1176, 1, 3000], [$fulleridesJob['quantity'], $fulleridesJob['runs'], $fulleridesJob['outputPerRun']]);
        self::assertSame(
            [self::NITROGEN_FUEL_BLOCK => 5, 16659 => 100, 16662 => 100],
            array_column($fulleridesJob['materials'], 'quantity', 'typeId'),
        );
    }

    // Raitaru with a 2 % material rig on top of its 1 % base bonus, ME 7: Hydrogen Isotopes 450 × 0,93 × 0,99 × 0,98
    // = 406,04 → 407 (an additive 3 % would give 405,95 → 406). Robotics falls in its own category, which no structure
    // covers: its quantity only gets the ME, floored at one per run.
    public function testTreeOfOneRunOfHydrogenFuelBlockInARaitaruWithRigStacksBaseAndRigBonusesMultiplicatively(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;
        $this->raitaruHasMaterialRig = true;

        $tree = $this->treeOfProject(self::HYDROGEN_FUEL_BLOCK, runs: 1, meLevel: 7);

        self::assertSame(
            [1, 'Raitaru', self::RAITARU_WITH_RIG_TOTAL_MATERIAL_BONUS, self::FUEL_BLOCK_CATEGORY],
            [$tree['runs'], $tree['structureName'], $tree['structureBonus'], $tree['productCategory']],
        );
        self::assertSame(
            [44 => 4, 3683 => 20, 3689 => 4, 9832 => 9, self::ROBOTICS => 1, 16272 => 154, 16273 => 316, 16275 => 19, 17889 => 407],
            array_column($tree['materials'], 'quantity', 'typeId'),
        );
    }

    public function testJobsOfABlueprintWithACopyingActivityHaveACopy(): void
    {
        $this->copyingActivityCount = 1;

        $tree = $this->treeOfProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 0);

        self::assertTrue($tree['hasCopy']);
        self::assertTrue($this->blueprintNodesOf($tree, self::HYDROGEN_FUEL_BLOCK)[0]['hasCopy']);
    }

    public function testTreeOfNitrogenFuelBlockWithEveryTypeInTheSdeKeepsTheSdeNames(): void
    {
        $tree = $this->treeOfProject(self::NITROGEN_FUEL_BLOCK, runs: 1, meLevel: 0);

        self::assertSame('Nitrogen Fuel Block', $tree['productTypeName']);
        self::assertSame(
            [44 => 'Enriched Uranium', 3683 => 'Oxygen', 3689 => 'Mechanical Parts', 9832 => 'Coolant', self::ROBOTICS => 'Robotics', 16272 => 'Heavy Water', 16273 => 'Liquid Ozone', 16275 => 'Strontium Clathrates', 17888 => 'Nitrogen Isotopes'],
            array_column($tree['materials'], 'typeName', 'typeId'),
        );
    }

    public function testRootProductMissingFromTheSdeFailsTheTreeWithItsTypeId(): void
    {
        $this->typeIdsWithoutSdeName = [self::NITROGEN_FUEL_BLOCK];

        $error = $this->typeMissingFromSdeErrorOfTree(self::NITROGEN_FUEL_BLOCK);

        self::assertSame([[self::NITROGEN_FUEL_BLOCK], 'Types missing from the SDE: 4051'], [$error->getMissingTypeIds(), $error->getMessage()]);
    }

    public function testMaterialMissingFromTheSdeFailsTheTreeWithItsTypeId(): void
    {
        $this->typeIdsWithoutSdeName = [self::ROBOTICS];

        $error = $this->typeMissingFromSdeErrorOfTree(self::NITROGEN_FUEL_BLOCK);

        self::assertSame([[self::ROBOTICS], 'Types missing from the SDE: 9848'], [$error->getMissingTypeIds(), $error->getMessage()]);
    }

    public function testProductAndMaterialMissingFromTheSdeAreAllReportedInAscendingOrder(): void
    {
        $this->typeIdsWithoutSdeName = [self::ROBOTICS, self::NITROGEN_FUEL_BLOCK];

        $error = $this->typeMissingFromSdeErrorOfTree(self::NITROGEN_FUEL_BLOCK);

        self::assertSame(
            [[self::NITROGEN_FUEL_BLOCK, self::ROBOTICS], 'Types missing from the SDE: 4051, 9848'],
            [$error->getMissingTypeIds(), $error->getMessage()],
        );
    }

    public function testIntermediateMissingFromTheSdeInSeveralBranchesIsReportedOnce(): void
    {
        $this->typeIdsWithoutSdeName = [self::HYDROGEN_FUEL_BLOCK];

        $error = $this->typeMissingFromSdeErrorOfTree(self::FERNITE_CARBIDE);

        self::assertSame([[self::HYDROGEN_FUEL_BLOCK], 'Types missing from the SDE: 4246'], [$error->getMissingTypeIds(), $error->getMessage()]);
    }

    public function testExcludedMaterialMissingFromTheSdeFailsTheTreeWithItsTypeId(): void
    {
        $this->typeIdsWithoutSdeName = [self::HYDROGEN_FUEL_BLOCK];

        $error = $this->typeMissingFromSdeErrorOfTree(self::FERNITE_CARBIDE, self::HYDROGEN_FUEL_BLOCK);

        self::assertSame([[self::HYDROGEN_FUEL_BLOCK], 'Types missing from the SDE: 4246'], [$error->getMissingTypeIds(), $error->getMessage()]);
    }

    public function testRootWithoutRecipeAndMissingFromTheSdeStillReportsTheMissingBlueprint(): void
    {
        $this->typeIdsWithoutSdeName = [self::ROBOTICS];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No blueprint or reaction found for product type ID 9848');

        $this->treeService->buildProductionTree(self::ROBOTICS, 1, 0);
    }

    public function testIntermediateWithoutRecipeIsShownAsANonBuildableMaterialAndKeepsTheTree(): void
    {
        $this->typeIdsWithoutRecipe = [self::FERNITE_ALLOY];

        $tree = $this->treeOfProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 0);

        self::assertSame([], $this->blueprintNodesOf($tree, self::FERNITE_ALLOY));
        self::assertSame(
            ['typeId' => self::FERNITE_ALLOY, 'typeName' => 'Fernite Alloy', 'quantity' => 100, 'isBuildable' => false, 'activityType' => null],
            $tree['materials'][1],
        );
        self::assertCount(1, $this->blueprintNodesOf($tree, self::CERAMIC_POWDER));
    }

    public function testStepsAreMappedToResources(): void
    {
        $this->project->addStep(new IndustryProjectStep());

        $resource = $this->provideProject(self::NITROGEN_FUEL_BLOCK, runs: 1, meLevel: 0);

        self::assertSame([$this->mappedStep], $resource->steps);
    }

    public function testBlacklistedIntermediateIsShownAsANonBuildableMaterialWithoutSubtree(): void
    {
        $this->blacklistedTypeIds = [self::HYDROGEN_FUEL_BLOCK];

        $tree = $this->treeOfProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 0);

        self::assertSame([], $this->blueprintNodesOf($tree, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame(
            ['typeId' => self::HYDROGEN_FUEL_BLOCK, 'typeName' => 'Hydrogen Fuel Block', 'quantity' => 5, 'isBuildable' => false, 'activityType' => null],
            $tree['materials'][0],
        );
    }

    public function testTreeIsNullWhenTheProductHasNoBlueprintNorReaction(): void
    {
        $resource = $this->provideProject(self::ROBOTICS, runs: 1, meLevel: 10);

        self::assertNull($resource->tree);
    }

    public function testTreeIsNullWhenATypeOfTheTreeIsMissingFromTheSde(): void
    {
        $this->typeIdsWithoutSdeName = [self::ROBOTICS];

        $resource = $this->provideProject(self::NITROGEN_FUEL_BLOCK, runs: 1, meLevel: 10);

        self::assertNull($resource->tree);
    }

    public function testProjectOfAnotherPilotIsNotFound(): void
    {
        $this->project->setUser($this->createStub(User::class));

        $this->expectException(NotFoundHttpException::class);

        $this->provider->provide(new Get(), ['id' => $this->project->getId()->toRfc4122()]);
    }

    public function testUnknownProjectIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->provider->provide(new Get(), ['id' => Uuid::v4()->toRfc4122()]);
    }

    public function testAnonymousRequestIsUnauthorized(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $provider = new ProjectProvider(
            $security,
            $this->createStub(IndustryProjectRepository::class),
            $this->createStub(IndustryResourceMapper::class),
            $this->createStub(IndustryTreeService::class),
            $this->createStub(IndustryBlacklistService::class),
        );

        $this->expectException(UnauthorizedHttpException::class);

        $provider->provide(new Get(), ['id' => $this->project->getId()->toRfc4122()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function treeOfProject(int $productTypeId, int $runs, int $meLevel): array
    {
        $tree = $this->provideProject($productTypeId, $runs, $meLevel)->tree;
        self::assertIsArray($tree);

        return $tree;
    }

    private function typeMissingFromSdeErrorOfTree(int $productTypeId, int ...$excludedTypeIds): TypeMissingFromSdeException
    {
        try {
            $this->treeService->buildProductionTree($productTypeId, 1, 0, array_values($excludedTypeIds));
        } catch (TypeMissingFromSdeException $error) {
            return $error;
        }

        self::fail("The tree of type {$productTypeId} was built without a TypeMissingFromSdeException.");
    }

    private function provideProject(int $productTypeId, int $runs, int $meLevel): ProjectResource
    {
        $this->project->setProductTypeId($productTypeId)->setRuns($runs)->setMeLevel($meLevel);

        return $this->provider->provide(new Get(), ['id' => $this->project->getId()->toRfc4122()]);
    }

    /**
     * @param array<string, mixed> $actual
     */
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

    /**
     * Quantities of the non-buildable materials summed by type over the whole tree, and number of job nodes per
     * produced type (root included), both sorted by type ID.
     *
     * @param array<string, mixed> $tree
     * @return array{leafQuantities: array<int, int>, jobCounts: array<int, int>}
     */
    private function leafQuantitiesAndJobCountsOf(array $tree): array
    {
        $leafQuantities = [];
        $jobCounts = [];
        $pendingJobs = [$tree];
        while ($pendingJobs !== []) {
            $job = array_pop($pendingJobs);
            $jobCounts[$job['productTypeId']] = ($jobCounts[$job['productTypeId']] ?? 0) + 1;
            foreach ($job['materials'] as $material) {
                if (isset($material['blueprint'])) {
                    $pendingJobs[] = $material['blueprint'];
                    continue;
                }
                $leafQuantities[$material['typeId']] = ($leafQuantities[$material['typeId']] ?? 0) + $material['quantity'];
            }
        }
        ksort($leafQuantities);
        ksort($jobCounts);

        return ['leafQuantities' => $leafQuantities, 'jobCounts' => $jobCounts];
    }

    /**
     * Every job node (the `blueprint` of a material) producing the given type, depth-first.
     *
     * @param array<string, mixed> $node
     * @return list<array<string, mixed>>
     */
    private function blueprintNodesOf(array $node, int $productTypeId): array
    {
        $found = [];
        foreach ($node['materials'] as $material) {
            if (!isset($material['blueprint'])) {
                continue;
            }
            if ($material['blueprint']['productTypeId'] === $productTypeId) {
                $found[] = $material['blueprint'];
            }
            $found = [...$found, ...$this->blueprintNodesOf($material['blueprint'], $productTypeId)];
        }

        return $found;
    }

    private function treeServiceOnGoldenSde(EntityManagerInterface $entityManager): IndustryTreeService
    {
        $recipes = $this->sde['recipes'];

        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            function (int $productTypeId, int $activityId) use ($recipes): ?IndustryActivityProduct {
                $recipe = in_array($productTypeId, $this->typeIdsWithoutRecipe, true) ? null : ($recipes[(string) $productTypeId] ?? null);
                if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                    return null;
                }

                return (new IndustryActivityProduct())
                    ->setTypeId($productTypeId + self::BLUEPRINT_TYPE_ID_OFFSET)
                    ->setActivityId($activityId)
                    ->setProductTypeId($productTypeId)
                    ->setQuantity($recipe['outputPerRun']);
            },
        );

        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findBy')->willReturnCallback(
            static function (array $criteria) use ($recipes): array {
                $productTypeId = $criteria['typeId'] - self::BLUEPRINT_TYPE_ID_OFFSET;
                $materials = [];
                foreach ($recipes[(string) $productTypeId]['materials'] as $materialTypeId => $baseQuantityPerRun) {
                    $materials[] = (new IndustryActivityMaterial())
                        ->setTypeId($criteria['typeId'])
                        ->setActivityId($criteria['activityId'])
                        ->setMaterialTypeId((int) $materialTypeId)
                        ->setQuantity($baseQuantityPerRun);
                }

                return $materials;
            },
        );

        $typeRepository = $this->createStub(InvTypeRepository::class);
        $typeRepository->method('find')->willReturnCallback(
            fn (int $typeId): ?InvType => isset($this->sde['typeNames'][(string) $typeId]) && !in_array($typeId, $this->typeIdsWithoutSdeName, true)
                ? (new InvType())->setTypeId($typeId)->setTypeName($this->sde['typeNames'][(string) $typeId])
                : null,
        );

        return new IndustryTreeService($productRepository, $materialRepository, $typeRepository, $entityManager, $this->bonusServiceOfThePilot());
    }

    /**
     * Without structure, every lookup returns no bonus. With structures, the pilot owns a Tatara in nullsec with
     * Standup L-Set Reactor Efficiency II (reactions) and a Raitaru without rig (fuel blocks), as in the Fernite golden;
     * the Raitaru can carry a material rig instead. Robotics has its own category, covered by no structure.
     */
    private function bonusServiceOfThePilot(): IndustryBonusService
    {
        $tatara = (new IndustryStructureConfig())->setName('Tatara')->setStructureType('tatara')->setSecurityType('nullsec')
            ->setRigs(['Standup L-Set Reactor Efficiency II']);
        $raitaru = (new IndustryStructureConfig())->setName('Raitaru')->setStructureType('raitaru')->setSecurityType('nullsec');
        $tataraForReactions = ['structure' => $tatara, 'bonus' => ['total' => self::TATARA_REACTOR_RIG_BONUS, 'base' => 0.0, 'rig' => self::TATARA_REACTOR_RIG_BONUS], 'category' => self::REACTION_CATEGORY];
        $raitaruForFuelBlocks = fn (): array => $this->raitaruHasMaterialRig
            ? ['structure' => $raitaru, 'bonus' => ['total' => self::RAITARU_WITH_RIG_TOTAL_MATERIAL_BONUS, 'base' => self::RAITARU_BASE_MATERIAL_BONUS, 'rig' => self::RAITARU_MATERIAL_RIG_BONUS], 'category' => self::FUEL_BLOCK_CATEGORY]
            : ['structure' => $raitaru, 'bonus' => ['total' => self::RAITARU_BASE_MATERIAL_BONUS, 'base' => self::RAITARU_BASE_MATERIAL_BONUS, 'rig' => 0.0], 'category' => self::FUEL_BLOCK_CATEGORY];
        $noStructure = ['structure' => null, 'bonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0], 'category' => null];
        $fuelBlocks = [self::HYDROGEN_FUEL_BLOCK, self::NITROGEN_FUEL_BLOCK];

        $bonusService = $this->createStub(IndustryBonusService::class);
        $bonusService->method('getCategoryForProduct')->willReturnCallback(
            fn (int $typeId, bool $isReaction = false): ?string => match (true) {
                !$this->pilotOwnsTataraAndRaitaru => null,
                $isReaction => self::REACTION_CATEGORY,
                in_array($typeId, $fuelBlocks, true) => self::FUEL_BLOCK_CATEGORY,
                $typeId === self::ROBOTICS => self::ROBOTICS_CATEGORY,
                default => null,
            },
        );
        $bonusService->method('findBestStructureForProduct')->willReturnCallback(
            fn (User $user, int $typeId, bool $isReaction = false): array => match (true) {
                !$this->pilotOwnsTataraAndRaitaru => $noStructure,
                $isReaction => $tataraForReactions,
                default => $raitaruForFuelBlocks(),
            },
        );
        $bonusService->method('findBestStructureForCategory')->willReturnCallback(
            fn (User $user, string $category, bool $isReaction = false): array => match ($category) {
                self::REACTION_CATEGORY => $tataraForReactions,
                self::FUEL_BLOCK_CATEGORY => $raitaruForFuelBlocks(),
                default => $noStructure,
            },
        );

        return $bonusService;
    }

    private static function activityIdOf(string $activity): int
    {
        return $activity === 'reaction' ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }
}
