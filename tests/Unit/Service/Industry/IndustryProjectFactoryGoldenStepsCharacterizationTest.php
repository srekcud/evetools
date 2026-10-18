<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivity;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedCharacterSkillRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\Industry\IndustryBlacklistService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\IndustryStepCalculator;
use App\Service\Industry\IndustryTreeService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\TypeNameResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

/**
 * Characterization of the steps written by IndustryProjectFactory (createProject, splitLongJobs) and rewritten by
 * IndustryStepCalculator::recalculateStepQuantities(), before buildProductionTree() is replaced by
 * src/Industry/Application (docs/specs/industry-engine.md, §7 rank 7).
 *
 * Real: IndustryProjectFactory, IndustryStepCalculator, IndustryTreeService, IndustryCalculationService,
 * IndustryBlacklistService, and the time formula of IndustryBonusService. They run on the SDE extract of the goldens
 * (tests/Fixtures/Golden/sde-blueprint-recipes.json, base time per run included).
 * Stubbed: the SDE repositories, the database connection (no copying activity, no science skill), Mercure, and the
 * structure lookup of IndustryBonusService (the goldens fix the formula, not the structure choice).
 *
 * Steps are pinned in JSON snapshots in tests/Fixtures/Characterization/IndustryProjectFactory/ (the Nomad snapshots
 * are reduced to a summary per product). A missing snapshot fails the test; snapshots are (re)written only when the
 * environment variable UPDATE_SNAPSHOTS=1 is set. Blueprint type IDs are synthetic (product type ID + 1000000).
 * Snapshots sort the steps canonically and name the split groups split-0, split-1… in that order.
 */
#[CoversClass(IndustryProjectFactory::class)]
#[CoversClass(IndustryStepCalculator::class)]
final class IndustryProjectFactoryGoldenStepsCharacterizationTest extends TestCase
{
    private const string GOLDEN_DIR = __DIR__.'/../../../Fixtures/Golden/';
    private const string SNAPSHOT_DIR = __DIR__.'/../../../Fixtures/Characterization/IndustryProjectFactory/';
    private const int BLUEPRINT_TYPE_ID_OFFSET = 1_000_000;

    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;
    private const int TITANIUM_CARBIDE = 16671;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int OXYGEN_FUEL_BLOCK = 4312;
    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int NOMAD = 28846;
    private const int CAPITAL_ELECTROLYTIC_CAPACITOR_UNIT = 29045;
    private const int FERMIONIC_CONDENSATES = 17317;
    private const int ROBOTICS = 9848;
    private const int UNKNOWN_BLUEPRINT_TYPE_ID = 999_999_999;

    private const float DEFAULT_MAX_JOB_DURATION_DAYS = 2.0;
    private const float MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING = 10_000.0;

    private const array ACTIVITY_ORDER = ['reaction' => 0, 'copy' => 1, 'manufacturing' => 2];

    private const array FIVE_MANUFACTURING_STEPS_OF_ONE_NOMAD_IN_SORT_ORDER = [
        [0, 'Helium Fuel Block', 'manufacturing', 5, 0, 107, 4265],
        [1, 'Hydrogen Fuel Block', 'manufacturing', 5, 0, 162, 6480],
        [2, 'Hydrogen Fuel Block', 'manufacturing', 5, 1, 162, 6480],
        [3, 'Nitrogen Fuel Block', 'manufacturing', 5, 0, 107, 4260],
        [4, 'Oxygen Fuel Block', 'manufacturing', 5, 0, 46, 1815],
    ];

    /** @var array{recipes: array<string, array{name: string, activity: string, outputPerRun: int, baseTimeSeconds: int, materials: array<string, int>}>, typeNames: array<string, string>} */
    private array $sde;

    private bool $pilotOwnsTataraAndRaitaru = false;
    /** @var list<int> */
    private array $typeIdsWithoutBaseTime = [];

    private User $pilot;
    private IndustryProjectFactory $factory;
    private IndustryStepCalculator $stepCalculator;

    protected function setUp(): void
    {
        $this->sde = json_decode((string) file_get_contents(self::GOLDEN_DIR.'sde-blueprint-recipes.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->pilot = new User();

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $typeRepository = $this->typeRepositoryOnGoldenSde();
        $productRepository = $this->productRepositoryOnGoldenSde();
        $materialRepository = $this->materialRepositoryOnGoldenSde();
        $bonusService = $this->bonusServiceOfThePilot();

        $settingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $settingsRepository->method('findOneBy')->willReturn(null);
        $calculationService = new IndustryCalculationService(
            $typeRepository,
            new TypeNameResolver($typeRepository),
            $bonusService,
            $this->createStub(IndustryStructureConfigRepository::class),
            $settingsRepository,
            $entityManager,
            $this->createStub(StaStationRepository::class),
            $this->createStub(CachedStructureRepository::class),
        );

        $this->factory = new IndustryProjectFactory(
            new IndustryTreeService($productRepository, $materialRepository, $typeRepository, $entityManager, $bonusService),
            new IndustryBlacklistService($entityManager),
            $bonusService,
            $calculationService,
            $this->createStub(CachedCharacterSkillRepository::class),
            $typeRepository,
            $materialRepository,
            $this->activityRepositoryOnGoldenSde(),
            $entityManager,
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );
        $this->stepCalculator = new IndustryStepCalculator($calculationService, $materialRepository, $productRepository, $entityManager);
    }

    // ===========================================
    // createProject() on the goldens
    // ===========================================

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #2 (B1) : les 25 runs du projet sont passés à
    // l'arbre comme une quantité. L'étape écrite fait 1 run de 25 unités au lieu de 25 runs de 1000 unités
    // (golden nitrogen-fuel-block-25runs-me10-npc).
    public function testStepsOfTwentyFiveRunsOfNitrogenFuelBlockCurrentlyPlanOneRunOfTwentyFiveUnitsIssue2(): void
    {
        $project = $this->createProject(self::NITROGEN_FUEL_BLOCK, runs: 25, meLevel: 10, teLevel: 20);

        self::assertSame(25, $project->getRuns());
        self::assertSame(
            [['sortOrder' => 0, 'product' => 'Nitrogen Fuel Block', 'productTypeId' => self::NITROGEN_FUEL_BLOCK, 'blueprintTypeId' => 1_004_051, 'activityType' => 'manufacturing', 'depth' => 0, 'runs' => 1, 'quantity' => 25, 'meLevel' => 10, 'teLevel' => 20, 'splitGroup' => null, 'splitIndex' => 0, 'totalGroupRuns' => null]],
            $this->stepsOf($project),
        );
    }

    public function testRootStepKeepsTheExtremeMeAndTeOfTheProject(): void
    {
        $atZero = $this->createProject(self::NITROGEN_FUEL_BLOCK, runs: 1, meLevel: 0, teLevel: 0);
        $atMax = $this->createProject(self::NITROGEN_FUEL_BLOCK, runs: 1, meLevel: 10, teLevel: 20);

        self::assertSame([0, 0], $this->meAndTeOfTheOnlyStep($atZero));
        self::assertSame([10, 20], $this->meAndTeOfTheOnlyStep($atMax));
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issues #2 et #16. Fernite Carbide, 1 run, Tatara
    // nullsec (L-Set Reactor Efficiency II) et Raitaru sans rig :
    // - #2 : le run de la racine est lu comme une unité (quantité 1 au lieu de 10000) ; les réactions de profondeur 1
    //   suivent leur consommateur (100 × 0,9736 = 97,36 → 98) ;
    // - #16 : les trois Hydrogen Fuel Block (5 + 5 + 5, consommés par la racine et par les deux réactions de
    //   profondeur 1) sont fusionnés en une étape à la profondeur du premier nœud rencontré (1). À profondeur égale,
    //   les réactions passent avant la fabrication : l'ordre des étapes place le fuel block après les deux réactions
    //   qui le consomment.
    // Conformes : ME 0 / TE 0 sur les réactions, ME 10 / TE 20 sur le fuel block intermédiaire.
    public function testStepsOfOneRunOfFerniteCarbideInATataraCurrentlyMergeTheFuelBlocksAtTheFirstDepthIssues2And16(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $project = $this->createProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 10, teLevel: 20);

        self::assertSame(
            [
                ['Ceramic Powder', 'reaction', 1, 1, 98, 0, 0],
                ['Fernite Alloy', 'reaction', 1, 1, 98, 0, 0],
                ['Hydrogen Fuel Block', 'manufacturing', 1, 1, 15, 10, 20],
                ['Fernite Carbide', 'reaction', 0, 1, 1, 0, 0],
            ],
            array_map(
                static fn (array $step): array => [$step['product'], $step['activityType'], $step['depth'], $step['runs'], $step['quantity'], $step['meLevel'], $step['teLevel']],
                $this->stepsOf($project),
            ),
        );
        $this->assertMatchesSnapshot('fernite-carbide-1run-tatara-nullsec', $this->canonicalStepsOf($project));
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #69. Un Nomad, ME 10, sans structure, comparé
    // au golden Ravworks (fait foi) : les intermédiaires sont fusionnés par blueprint, mais leur quantité est la somme
    // des quantités déjà arrondies de chaque branche de l'arbre. Hydrogen Fuel Block 324 runs au lieu de 322, Nitrogen
    // Fuel Block 107 au lieu de 106 ; Fernite Alloy (550) et Capital Electrolytic Capacitor Unit (951) suivent le golden.
    public function testStepsOfOneNomadCurrentlyOverplanTheFuelBlocksSummedAcrossBranchesIssue69(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20);

        $steps = $this->stepsOf($project);
        self::assertSame([[1, 1, 0, 10, 20, 0, null]], $this->jobsOf($steps, self::NOMAD));
        self::assertNull($this->stepsOfProduct($steps, self::NOMAD)[0]['splitGroup']);
        self::assertSame(
            [[162, 6480, 5, 10, 20, 0, 324], [162, 6480, 5, 10, 20, 1, 324]],
            $this->jobsOf($steps, self::HYDROGEN_FUEL_BLOCK),
        );
        self::assertSame([[107, 4260, 5, 10, 20, 0, null]], $this->jobsOf($steps, self::NITROGEN_FUEL_BLOCK));
        self::assertSame(
            [self::FERNITE_ALLOY => 550, self::CAPITAL_ELECTROLYTIC_CAPACITOR_UNIT => 951],
            [self::FERNITE_ALLOY => $this->totalRunsOf($steps, self::FERNITE_ALLOY), self::CAPITAL_ELECTROLYTIC_CAPACITOR_UNIT => $this->totalRunsOf($steps, self::CAPITAL_ELECTROLYTIC_CAPACITOR_UNIT)],
        );
        self::assertCount(373, $steps);
        $this->assertMatchesSnapshot('nomad-1run-me10-no-structure-per-product', $this->summaryPerProductOf($project));
    }

    // Steps are ordered deepest first, reactions before manufacturing at equal depth.
    public function testStepsOfOneNomadAreOrderedByDepthThenReactionsBeforeManufacturing(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20);

        $levelsInStepOrder = [];
        foreach ($this->stepsOf($project) as $step) {
            $level = $step['depth'].' '.$step['activityType'];
            if ($levelsInStepOrder === [] || $levelsInStepOrder[array_key_last($levelsInStepOrder)] !== $level) {
                $levelsInStepOrder[] = $level;
            }
        }

        self::assertSame(
            ['5 manufacturing', '4 reaction', '3 reaction', '3 manufacturing', '2 reaction', '2 manufacturing', '1 manufacturing', '0 manufacturing'],
            $levelsInStepOrder,
        );
    }

    // Intermediate manufacturing steps take ME 10 / TE 20 whatever the ME / TE of the project; reactions take 0 / 0.
    public function testIntermediatesOfANomadAtMeZeroAndTeZeroKeepMeTenAndTeTwenty(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, meLevel: 0, teLevel: 0);

        $meAndTeByLevel = [];
        foreach ($this->stepsOf($project) as $step) {
            $level = match (true) {
                $step['depth'] === 0 => 'root',
                $step['activityType'] === 'reaction' => 'reaction',
                default => 'intermediate manufacturing',
            };
            $meAndTeByLevel[$level][$step['meLevel'].'/'.$step['teLevel']] = true;
        }
        ksort($meAndTeByLevel);

        self::assertSame(
            ['intermediate manufacturing' => ['10/20'], 'reaction' => ['0/0'], 'root' => ['0/0']],
            array_map(array_keys(...), $meAndTeByLevel),
        );
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #15. La quantité d'une réaction est recalculée
    // avec ME 10 en dur pour tout consommateur fabriqué, même la racine à ME 0 : Quantum Microprocessor, 32 runs,
    // ME 0, 17 Titanium Carbide par run → 17 × 32 × 0,9 = 489,6 → 490 au lieu de 17 × 32 = 544.
    public function testReactionOfAnMeZeroRootIsCurrentlyPlannedAtMeTenIssue15(): void
    {
        $project = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 32, meLevel: 0, teLevel: 20);

        $steps = $this->stepsOf($project);
        self::assertSame([[32, 32, 0, 0, 20, 0, null]], $this->jobsOf($steps, self::QUANTUM_MICROPROCESSOR));
        self::assertSame([[1, 490, 1, 0, 0, 0, null]], $this->jobsOf($steps, self::TITANIUM_CARBIDE));
    }

    // A manufactured consumer in a Raitaru (1 % base material bonus, no rig) needs fewer units of a reaction:
    // Quantum Microprocessor, 237 runs, 6 Phenolic Composites per run → 6 × 237 × 0.9 × 0.99 = 1266.98 → 1267
    // (1280 without the structure).
    public function testReactionConsumedByAManufacturedConsumerInARaitaruGetsTheBaseMaterialBonus(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $project = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 237, meLevel: 10, teLevel: 20);

        self::assertSame([[1, 1267, 1, 0, 0, 0, null]], $this->jobsOf($this->stepsOf($project), self::PHENOLIC_COMPOSITES));
    }

    // A reaction consumed by reactions in a Tatara gets the reactor rig (2.4 % × 1.1 = 2.64 %) on each consumer job:
    // Fernite Alloy = ceil(round(100 × 963 × 0.9736, 2)) + ceil(round(100 × 126 × 0.9736, 2)) = 93758 + 12268 = 106026
    // for the Fernite Carbide and the Plasmonic Metamaterials of one Nomad. The capital components consuming Fernite
    // Carbide have no category in the stub, so no structure bonus: ME 10 alone. The 365 day ceiling keeps the steps
    // unsplit, so the step quantity is the need.
    public function testReactionConsumedByAReactionInATataraGetsTheReactorRigBonus(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $project = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20, maxJobDurationDays: 365.0);

        $steps = $this->stepsOf($project);
        self::assertSame([[963, 9_622_187, 2, 0, 0, 0, null]], $this->jobsOf($steps, self::FERNITE_CARBIDE));
        self::assertSame([[531, 106_026, 4, 0, 0, 0, null]], $this->jobsOf($steps, self::FERNITE_ALLOY));
    }

    #[Group('issue-110')]
    public function testStepsOfEachLevelOfOneNomadAreOrderedByProductNameThenSplitIndex(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20);

        $stepsByLevel = [];
        foreach ($this->stepsOf($project) as $step) {
            $stepsByLevel[$step['depth'].' '.$step['activityType']][] = $step;
        }
        $productAndSplitIndexByLevel = array_map($this->productAndSplitIndexOfStepsOf(...), $stepsByLevel);
        $productAndSplitIndexByLevelInTheOrderOfR1 = array_map(
            fn (array $steps): array => $this->productAndSplitIndexOfStepsOf($this->sortedByProductNameThenSplitIndex($steps)),
            $stepsByLevel,
        );
        $levelsWithAnUnsplitStepAfterASplitGroup = array_keys(array_filter(
            array_map($this->hasAnUnsplitStepAfterASplitGroup(...), array_map($this->sortedByProductNameThenSplitIndex(...), $stepsByLevel)),
        ));

        self::assertSame(['5 manufacturing', '3 reaction', '2 reaction', '2 manufacturing', '1 manufacturing'], $levelsWithAnUnsplitStepAfterASplitGroup);
        self::assertSame(
            [['Helium Fuel Block', 0], ['Hydrogen Fuel Block', 0], ['Hydrogen Fuel Block', 1], ['Nitrogen Fuel Block', 0], ['Oxygen Fuel Block', 0]],
            $productAndSplitIndexByLevel['5 manufacturing'],
        );
        self::assertSame($productAndSplitIndexByLevelInTheOrderOfR1, $productAndSplitIndexByLevel);
    }

    #[Group('issue-110')]
    public function testTwoCreationsOfOneNomadGiveTheSameStepsInTheSameSortOrder(): void
    {
        $first = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20);
        $second = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20);

        self::assertSame(self::FIVE_MANUFACTURING_STEPS_OF_ONE_NOMAD_IN_SORT_ORDER, array_slice($this->stepsInSortOrderOf($first), 0, 5));
        self::assertSame($this->stepsInTheOrderOfR1Of($first), $this->stepsInSortOrderOf($first));
        self::assertSame($this->stepsInSortOrderOf($first), $this->stepsInSortOrderOf($second));
    }

    #[Group('issue-110')]
    public function testRegeneratedStepsOfOneNomadKeepTheSortOrderOfTheCreation(): void
    {
        $created = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20);
        $regenerated = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20);

        $this->factory->regenerateSteps($regenerated);

        self::assertSame(self::FIVE_MANUFACTURING_STEPS_OF_ONE_NOMAD_IN_SORT_ORDER, array_slice($this->stepsInSortOrderOf($regenerated), 0, 5));
        self::assertSame($this->stepsInTheOrderOfR1Of($regenerated), $this->stepsInSortOrderOf($regenerated));
        self::assertSame($this->stepsInSortOrderOf($created), $this->stepsInSortOrderOf($regenerated));
    }

    public function testProductWithoutBlueprintNorReactionIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No blueprint or reaction found for product type ID 9848');

        $this->createProject(self::ROBOTICS, runs: 1, meLevel: 10, teLevel: 20);
    }

    // ===========================================
    // splitLongJobs() (the real one, not the copy of JobSplitCalculationTest)
    // ===========================================

    // 25 runs of Quantum Microprocessor at TE 20 take 75 × 0.8 = 60 s each, 1500 s in all. With a 0.008 day ceiling
    // (691.2 s), a job holds at most 11 runs, so 3 jobs; the runs are spread evenly (9, 8, 8), not 11, 11, 3 as
    // JobSplitCalculationTest computes on its own copy of the algorithm.
    public function testLongJobIsSplitIntoJobsOfEvenRunsUnderTheMaximumDuration(): void
    {
        $project = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 25, meLevel: 10, teLevel: 20, maxJobDurationDays: 0.008);

        $steps = $this->stepsOf($project);
        $rootJobs = $this->stepsOfProduct($steps, self::QUANTUM_MICROPROCESSOR);
        self::assertSame(
            [[9, 9, 0, 10, 20, 0, 25], [8, 8, 0, 10, 20, 1, 25], [8, 8, 0, 10, 20, 2, 25]],
            $this->jobsOf($steps, self::QUANTUM_MICROPROCESSOR),
        );
        self::assertCount(1, array_unique(array_column($rootJobs, 'splitGroup')));
        self::assertNotNull($rootJobs[0]['splitGroup']);
        self::assertSame([count($steps) - 3, count($steps) - 2, count($steps) - 1], array_column($rootJobs, 'sortOrder'));
    }

    // Below one run per job, every job holds one run: 0.0001 day is 8.64 s for 60 s per run.
    public function testMaximumDurationShorterThanOneRunGivesOneJobPerRun(): void
    {
        $project = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 25, meLevel: 10, teLevel: 20, maxJobDurationDays: 0.0001);

        $steps = $this->stepsOf($project);
        self::assertSame(
            array_map(static fn (int $splitIndex): array => [1, 1, 0, 10, 20, $splitIndex, 25], range(0, 24)),
            $this->jobsOf($steps, self::QUANTUM_MICROPROCESSOR),
        );
        $splitGroups = array_unique(array_column($this->stepsOfProduct($steps, self::QUANTUM_MICROPROCESSOR), 'splitGroup'));
        self::assertCount(1, $splitGroups);
        self::assertNotNull(reset($splitGroups));
    }

    public function testOneRunLongerThanTheMaximumDurationStaysAnUnsplitStepThatKeepsTheNeed(): void
    {
        $atDefaultMaximumDuration = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 32, meLevel: 10, teLevel: 20);
        $atShortMaximumDuration = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 32, meLevel: 10, teLevel: 20, maxJobDurationDays: 0.1);

        $titaniumCarbideSteps = $this->stepsOfProduct($this->stepsOf($atShortMaximumDuration), self::TITANIUM_CARBIDE);
        self::assertSame([[1, 490, 1, 0, 0, 0, null]], $this->jobsOf($this->stepsOf($atShortMaximumDuration), self::TITANIUM_CARBIDE));
        self::assertSame([null], array_column($titaniumCarbideSteps, 'splitGroup'));
        self::assertSame(
            $this->jobsOf($this->stepsOf($atDefaultMaximumDuration), self::TITANIUM_CARBIDE),
            $this->jobsOf($this->stepsOf($atShortMaximumDuration), self::TITANIUM_CARBIDE),
        );
    }

    // TE 0 at the root: 75 s per run, 25 runs = 1875 s; at 0.02 day (1728 s) a job holds 23 runs, so 2 jobs.
    // TE 20: 60 s per run, 1500 s, one job.
    public function testTeOfTheRootChangesTheDurationThatDecidesTheSplit(): void
    {
        $atTeZero = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 25, meLevel: 10, teLevel: 0, maxJobDurationDays: 0.02);
        $atTeTwenty = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 25, meLevel: 10, teLevel: 20, maxJobDurationDays: 0.02);

        self::assertSame([[13, 13, 0, 10, 0, 0, 25], [12, 12, 0, 10, 0, 1, 25]], $this->jobsOf($this->stepsOf($atTeZero), self::QUANTUM_MICROPROCESSOR));
        self::assertSame([[25, 25, 0, 10, 20, 0, null]], $this->jobsOf($this->stepsOf($atTeTwenty), self::QUANTUM_MICROPROCESSOR));
    }

    #[Group('issue-112')]
    public function testSplitIntermediateTimedAtTeTwentyGivesItsLastJobTheRestOfTheNeed(): void
    {
        $justLongEnough = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 700, meLevel: 10, teLevel: 0, maxJobDurationDays: 0.01667);
        $justTooShort = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 700, meLevel: 10, teLevel: 0, maxJobDurationDays: 0.01666);

        $rootRunsPerJob = [...array_fill(0, 34, 19), ...array_fill(0, 3, 18)];
        self::assertSame([[2, 55, 2, 10, 20, 0, null]], $this->jobsOf($this->stepsOf($justLongEnough), self::OXYGEN_FUEL_BLOCK));
        self::assertSame([[1, 40, 2, 10, 20, 0, 2], [1, 15, 2, 10, 20, 1, 2]], $this->jobsOf($this->stepsOf($justTooShort), self::OXYGEN_FUEL_BLOCK));
        self::assertSame($rootRunsPerJob, array_column($this->stepsOfProduct($this->stepsOf($justLongEnough), self::QUANTUM_MICROPROCESSOR), 'runs'));
        self::assertSame($rootRunsPerJob, array_column($this->stepsOfProduct($this->stepsOf($justTooShort), self::QUANTUM_MICROPROCESSOR), 'runs'));
    }

    #[Group('issue-112')]
    public function testQuantitiesOfEachSplitGroupOfOneNomadAddUpToTheQuantityOfTheUnsplitStep(): void
    {
        $split = $this->stepsOf($this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20));
        $unsplit = $this->stepsOf($this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING));

        $splitProducts = array_unique(array_column(array_filter($split, static fn (array $step): bool => $step['splitGroup'] !== null), 'productTypeId', 'product'));
        self::assertSame([], array_filter($unsplit, static fn (array $step): bool => $step['splitGroup'] !== null));
        self::assertSame(
            array_map(fn (int $productTypeId): int => $this->totalQuantityOf($unsplit, $productTypeId), $splitProducts),
            array_map(fn (int $productTypeId): int => $this->totalQuantityOf($split, $productTypeId), $splitProducts),
        );
    }

    public function testStepWithoutBaseTimeInTheSdeIsNeverSplit(): void
    {
        $this->typeIdsWithoutBaseTime = [self::QUANTUM_MICROPROCESSOR];

        $project = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 25, meLevel: 10, teLevel: 20, maxJobDurationDays: 0.008);

        self::assertSame([[25, 25, 0, 10, 20, 0, null]], $this->jobsOf($this->stepsOf($project), self::QUANTUM_MICROPROCESSOR));
    }

    // Only the type without base time escapes the split: the other reactions of the same depth (10800 s per run) and
    // the root still split in 0.01 day (864 s).
    public function testOneTypeWithoutBaseTimeDoesNotStopTheSplitOfTheOthers(): void
    {
        $this->typeIdsWithoutBaseTime = [self::TITANIUM_CARBIDE];

        $project = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 32, meLevel: 10, teLevel: 20, maxJobDurationDays: 0.01);

        $steps = $this->stepsOf($project);
        $isSplitByProduct = [];
        foreach ($steps as $step) {
            if ($step['depth'] <= 1) {
                $isSplitByProduct[$step['product']] = $step['splitGroup'] !== null;
            }
        }
        ksort($isSplitByProduct);
        self::assertSame(
            ['Nanotransistors' => false, 'Nonlinear Metamaterials' => false, 'Phenolic Composites' => false, 'Quantum Microprocessor' => true, 'Titanium Carbide' => false],
            $isSplitByProduct,
        );
        self::assertCount(19, $steps);
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #73. À la création, les réactions sont calculées
    // sur le job non découpé du consommateur (17 × 32 × 0,9 = 489,6 → 490), puis le consommateur est découpé en
    // 11 + 11 + 10 runs, dont les besoins sont 169 + 169 + 153 = 491 (R13, spec §4.6). Titanium Carbide n'a pas de
    // durée de base ici, pour qu'il reste un job non découpé dont la quantité est le besoin.
    public function testSplitConsumerCurrentlyKeepsTheNeedOfTheUnsplitJobAtCreationIssue73(): void
    {
        $this->typeIdsWithoutBaseTime = [self::TITANIUM_CARBIDE];

        $project = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 32, meLevel: 10, teLevel: 20, maxJobDurationDays: 0.01);

        $steps = $this->stepsOf($project);
        self::assertSame([[11, 11, 0, 10, 20, 0, 32], [11, 11, 0, 10, 20, 1, 32], [10, 10, 0, 10, 20, 2, 32]], $this->jobsOf($steps, self::QUANTUM_MICROPROCESSOR));
        self::assertSame([[1, 490, 1, 0, 0, 0, null]], $this->jobsOf($steps, self::TITANIUM_CARBIDE));
    }

    // ===========================================
    // regenerateSteps() without database
    // ===========================================

    public function testRegeneratedStepsAreTheStepsOfANewProjectWithTheNewRuns(): void
    {
        $regenerated = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 40, meLevel: 10, teLevel: 20, maxJobDurationDays: 0.008);
        $regenerated->setRuns(25);

        $this->factory->regenerateSteps($regenerated);

        $created = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 25, meLevel: 10, teLevel: 20, maxJobDurationDays: 0.008);
        self::assertSame([9, 8, 8], array_column($this->stepsOfProduct($this->stepsOf($regenerated), self::QUANTUM_MICROPROCESSOR), 'runs'));
        self::assertSame($this->canonicalStepsOf($created), $this->canonicalStepsOf($regenerated));
    }

    public function testRegenerationKeepsAnUnsplitStepForOneRunLongerThanTheNewMaximumDuration(): void
    {
        $project = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 32, meLevel: 10, teLevel: 20);
        $project->setMaxJobDurationDays(0.1);

        $this->factory->regenerateSteps($project);

        $steps = $this->stepsOf($project);
        self::assertSame([[1, 490, 1, 0, 0, 0, null]], $this->jobsOf($steps, self::TITANIUM_CARBIDE));
        self::assertSame([null], array_column($this->stepsOfProduct($steps, self::TITANIUM_CARBIDE), 'splitGroup'));
    }

    public function testRegeneratedStepsOfANomadInATataraAreTheStepsOfANewProject(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;
        $regenerated = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20);

        $this->factory->regenerateSteps($regenerated);

        $created = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20);
        self::assertSame($this->canonicalStepsOf($created), $this->canonicalStepsOf($regenerated));
    }

    // ===========================================
    // IndustryStepCalculator::recalculateStepQuantities() on the steps of the goldens
    // ===========================================

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #16. Les besoins sont remis à zéro à chaque
    // profondeur et seules les profondeurs inférieures à la profondeur maximale consomment. Sur Fernite Carbide, le
    // Hydrogen Fuel Block (profondeur 1) ne garde que le besoin du job racine (5) : les 10 unités des réactions de
    // profondeur 1 sont perdues.
    public function testRecalculatedFuelBlockOfFerniteCarbideCurrentlyKeepsOnlyTheNeedOfTheRootIssue16(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;
        $project = $this->createProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 10, teLevel: 20);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame([self::HYDROGEN_FUEL_BLOCK], array_map(static fn (IndustryProjectStep $step): int => $step->getProductTypeId(), $changedSteps));
        self::assertSame([[1, 5, 1, 10, 20, 0, null]], $this->jobsOf($this->stepsOf($project), self::HYDROGEN_FUEL_BLOCK));
        self::assertSame([[1, 98, 1, 0, 0, 0, null]], $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY));
        self::assertSame([[1, 98, 1, 0, 0, 0, null]], $this->jobsOf($this->stepsOf($project), self::CERAMIC_POWDER));
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #16. Recalculer les étapes générées d'un Nomad
    // remet les besoins à zéro à chaque profondeur : un intermédiaire consommé à plusieurs profondeurs ne garde que le
    // besoin de la dernière profondeur qui le consomme. Hydrogen Fuel Block 324 → 9 runs, Nitrogen Fuel Block
    // 107 → 1 run (30 unités), Fernite Alloy 550 → 4 runs (golden Ravworks : 550 et 322).
    // Comportement suspecté faux, sans issue : un groupe découpé ne perd jamais de job ; chaque job garde au moins
    // 1 run, si bien que la somme des runs dépasse totalGroupRuns (Fernite Alloy : 35 jobs de 1 run pour 4 runs,
    // Fermionic Condensates : 5 jobs de 1 run pour 1 run).
    #[Group('issue-112')]
    public function testRecalculatingTheStepsOfOneNomadCurrentlyKeepsOnlyTheNeedOfTheDeepestConsumerIssue16(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20);

        $this->stepCalculator->recalculateStepQuantities($project);

        $steps = $this->stepsOf($project);
        self::assertSame([[5, 200, 5, 10, 20, 0, 9], [4, 124, 5, 10, 20, 1, 9]], $this->jobsOf($steps, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame([[1, 30, 5, 10, 20, 0, null]], $this->jobsOf($steps, self::NITROGEN_FUEL_BLOCK));
        self::assertSame([35, 4], [$this->totalRunsOf($steps, self::FERNITE_ALLOY), $this->stepsOfProduct($steps, self::FERNITE_ALLOY)[0]['totalGroupRuns']]);
        self::assertSame([5, 1], [$this->totalRunsOf($steps, self::FERMIONIC_CONDENSATES), $this->stepsOfProduct($steps, self::FERMIONIC_CONDENSATES)[0]['totalGroupRuns']]);
        $this->assertMatchesSnapshot('nomad-1run-me10-no-structure-recalculated-per-product', $this->summaryPerProductOf($project));
    }

    // R13 (spec §4.6): a split consumer recomputes its materials job by job. Quantum Microprocessor takes 17 Titanium
    // Carbide per run at ME 10: one job of 32 runs needs ceil(489.6) = 490, three jobs of 11 + 11 + 10 runs need
    // 169 + 169 + 153 = 491.
    public function testSplitConsumerRecomputesTheNeedOfItsMaterialJobByJob(): void
    {
        $oneJob = $this->projectOfStepsWithoutStructure([
            $this->step(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0, meLevel: 10),
            $this->step(self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 1, depth: 1, meLevel: 0),
        ]);
        $threeJobs = $this->projectOfStepsWithoutStructure([
            $this->step(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 11, quantity: 11, depth: 0, meLevel: 10),
            $this->step(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 11, quantity: 11, depth: 0, meLevel: 10),
            $this->step(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 10, quantity: 10, depth: 0, meLevel: 10),
            $this->step(self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 1, depth: 1, meLevel: 0),
        ]);

        $this->stepCalculator->recalculateStepQuantities($oneJob);
        $this->stepCalculator->recalculateStepQuantities($threeJobs);

        self::assertSame([[1, 490, 1, 0, 20, 0, null]], $this->jobsOf($this->stepsOf($oneJob), self::TITANIUM_CARBIDE));
        self::assertSame([[1, 491, 1, 0, 20, 0, null]], $this->jobsOf($this->stepsOf($threeJobs), self::TITANIUM_CARBIDE));
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, sans issue. Un groupe découpé reçoit le nouveau total de
    // runs réparti au prorata de ses anciens runs, le dernier job prenant le reste, mais chaque job garde au moins
    // 1 run : 98 Fernite Alloy à 200 unités par run demandent 1 run, réparti sur 2 jobs de 3 et 2 runs en
    // round(1 × 3 / 5) = 1 puis max(1, 1 − 1) = 1. Le groupe finit à 2 runs pour un besoin de 1.
    #[Group('issue-112')]
    public function testSplitChildGroupCurrentlyKeepsTwoRunsForANeedOfOne(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 1, quantity: 10_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 3, quantity: 600, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 1),
        ]);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame(
            [[1, 98, 1, 0, 20, 0, 1], [1, 0, 1, 0, 20, 1, 1]],
            $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY),
        );
        self::assertSame([[self::FERNITE_ALLOY, 0], [self::FERNITE_ALLOY, 1]], $this->productAndSplitIndexOf($changedSteps));
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, sans issue. Seuls les jobs dont les runs ou la quantité
    // changent reçoivent le nouveau totalGroupRuns : un job inchangé garde l'ancien. 11 runs de Fernite Carbide
    // demandent 1100 Fernite Alloy, soit 6 runs répartis sur 2 + 2 + 1 anciens runs : round(6 × 2 / 5) = 2,
    // round(6 × 2 / 5) = 2, puis 6 − 4 = 2. Le job 0 (2 runs, 400) est inchangé et garde totalGroupRuns 5 ; le job 1
    // ne change que de quantité (350 → 400) ; le job 2 passe de 1 à 2 runs.
    #[Group('issue-112')]
    public function testUnchangedJobOfARecalculatedSplitGroupCurrentlyKeepsTheOldTotalGroupRuns(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 11, quantity: 110_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0, totalGroupRuns: 5),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 350, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 1, totalGroupRuns: 5),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 1, quantity: 200, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 2, totalGroupRuns: 5),
        ]);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame(
            [[2, 400, 1, 0, 20, 0, 5], [2, 400, 1, 0, 20, 1, 6], [2, 300, 1, 0, 20, 2, 6]],
            $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY),
        );
        self::assertSame([[self::FERNITE_ALLOY, 1], [self::FERNITE_ALLOY, 2]], $this->productAndSplitIndexOf($changedSteps));
    }

    // CARACTÉRISATION : comportement actuel, suspecté faux, sans issue. Quand le produit de l'étape enfant est absent
    // du SDE (blueprint inconnu), le recalcul suppose 1 unité par run au lieu de signaler la donnée manquante :
    // les 490 Titanium Carbide de 32 Quantum Microprocessors deviennent 490 runs (1 run de 10000 unités en réalité).
    public function testChildWithoutProductInTheSdeIsCurrentlyRecalculatedAtOneUnitPerRun(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0, meLevel: 10),
            $this->step(self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 1, depth: 1, meLevel: 0, blueprintTypeId: self::UNKNOWN_BLUEPRINT_TYPE_ID),
        ]);

        $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame([[490, 490, 1, 0, 20, 0, null]], $this->jobsOf($this->stepsOf($project), self::TITANIUM_CARBIDE));
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createProject(int $productTypeId, int $runs, int $meLevel, int $teLevel, float $maxJobDurationDays = self::DEFAULT_MAX_JOB_DURATION_DAYS): IndustryProject
    {
        return $this->factory->createProject($this->pilot, $productTypeId, $runs, $meLevel, $maxJobDurationDays, $teLevel);
    }

    /**
     * @param list<IndustryProjectStep> $steps
     */
    private function projectOfStepsWithoutStructure(array $steps): IndustryProject
    {
        $project = (new IndustryProject())->setUser($this->pilot);
        foreach ($steps as $step) {
            $project->addStep($step);
        }

        return $project;
    }

    private function step(int $productTypeId, string $activityType, int $runs, int $quantity, int $depth, int $meLevel, ?string $splitGroupId = null, int $splitIndex = 0, ?int $totalGroupRuns = null, ?int $blueprintTypeId = null): IndustryProjectStep
    {
        return (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($blueprintTypeId ?? $productTypeId + self::BLUEPRINT_TYPE_ID_OFFSET)
            ->setTotalGroupRuns($totalGroupRuns)
            ->setActivityType($activityType)
            ->setRuns($runs)
            ->setQuantity($quantity)
            ->setDepth($depth)
            ->setMeLevel($meLevel)
            ->setSplitGroupId($splitGroupId)
            ->setSplitIndex($splitIndex);
    }

    /**
     * @return list<array{sortOrder: int, product: string, productTypeId: int, blueprintTypeId: int, activityType: string, depth: int, runs: int, quantity: int, meLevel: int, teLevel: int, splitGroup: string|null, splitIndex: int, totalGroupRuns: int|null}>
     */
    private function stepsOf(IndustryProject $project): array
    {
        $splitGroupLabels = [];
        $steps = [];
        foreach ($project->getSteps() as $step) {
            $splitGroupId = $step->getSplitGroupId();
            if ($splitGroupId !== null && !isset($splitGroupLabels[$splitGroupId])) {
                $splitGroupLabels[$splitGroupId] = 'split-'.count($splitGroupLabels);
            }
            $steps[] = [
                'sortOrder' => $step->getSortOrder(),
                'product' => $this->sde['typeNames'][(string) $step->getProductTypeId()],
                'productTypeId' => $step->getProductTypeId(),
                'blueprintTypeId' => $step->getBlueprintTypeId(),
                'activityType' => $step->getActivityType(),
                'depth' => $step->getDepth(),
                'runs' => $step->getRuns(),
                'quantity' => $step->getQuantity(),
                'meLevel' => $step->getMeLevel(),
                'teLevel' => $step->getTeLevel(),
                'splitGroup' => $splitGroupId === null ? null : $splitGroupLabels[$splitGroupId],
                'splitIndex' => $step->getSplitIndex(),
                'totalGroupRuns' => $step->getTotalGroupRuns(),
            ];
        }

        return $steps;
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @return list<array<string, mixed>>
     */
    private function stepsOfProduct(array $steps, int $productTypeId): array
    {
        return array_values(array_filter($steps, static fn (array $step): bool => $step['productTypeId'] === $productTypeId));
    }

    /**
     * Each job of the product as [runs, quantity, depth, meLevel, teLevel, splitIndex, totalGroupRuns].
     *
     * @param list<array<string, mixed>> $steps
     * @return list<list<int|null>>
     */
    private function jobsOf(array $steps, int $productTypeId): array
    {
        return array_map(
            static fn (array $step): array => [$step['runs'], $step['quantity'], $step['depth'], $step['meLevel'], $step['teLevel'], $step['splitIndex'], $step['totalGroupRuns']],
            $this->stepsOfProduct($steps, $productTypeId),
        );
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function totalRunsOf(array $steps, int $productTypeId): int
    {
        return array_sum(array_column($this->stepsOfProduct($steps, $productTypeId), 'runs'));
    }

    private function totalQuantityOf(array $steps, int $productTypeId): int
    {
        return array_sum(array_column($this->stepsOfProduct($steps, $productTypeId), 'quantity'));
    }

    /** @return array{int, int} */
    private function meAndTeOfTheOnlyStep(IndustryProject $project): array
    {
        self::assertCount(1, $project->getSteps());
        $step = $project->getSteps()->first();

        return [$step->getMeLevel(), $step->getTeLevel()];
    }

    /**
     * @return list<array{int, string, string, int, int, int, int}>
     */
    private function stepsInSortOrderOf(IndustryProject $project): array
    {
        $steps = $this->stepsOf($project);
        usort($steps, static fn (array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']);

        return array_map(
            static fn (array $step): array => [$step['sortOrder'], $step['product'], $step['activityType'], $step['depth'], $step['splitIndex'], $step['runs'], $step['quantity']],
            $steps,
        );
    }

    /**
     * @return list<array{int, string, string, int, int, int, int}>
     */
    private function stepsInTheOrderOfR1Of(IndustryProject $project): array
    {
        $steps = $this->stepsOf($project);
        usort($steps, static fn (array $a, array $b): int => [$b['depth'], self::ACTIVITY_ORDER[$a['activityType']], strcasecmp($a['product'], $b['product']), $a['splitIndex']]
            <=> [$a['depth'], self::ACTIVITY_ORDER[$b['activityType']], 0, $b['splitIndex']]);

        return array_map(
            static fn (int $sortOrder, array $step): array => [$sortOrder, $step['product'], $step['activityType'], $step['depth'], $step['splitIndex'], $step['runs'], $step['quantity']],
            array_keys($steps),
            $steps,
        );
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @return list<array<string, mixed>>
     */
    private function sortedByProductNameThenSplitIndex(array $steps): array
    {
        usort($steps, static fn (array $a, array $b): int => [strcasecmp($a['product'], $b['product']), $a['splitIndex']] <=> [0, $b['splitIndex']]);

        return $steps;
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @return list<array{string, int}>
     */
    private function productAndSplitIndexOfStepsOf(array $steps): array
    {
        return array_map(static fn (array $step): array => [$step['product'], $step['splitIndex']], $steps);
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function hasAnUnsplitStepAfterASplitGroup(array $steps): bool
    {
        foreach (array_slice(array_keys($steps), 1) as $position) {
            if ($steps[$position - 1]['splitGroup'] !== null && $steps[$position]['splitGroup'] === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function canonicalStepsOf(IndustryProject $project): array
    {
        $steps = $this->stepsOf($project);
        usort($steps, static fn (array $a, array $b): int => [$b['depth'], $a['activityType'], $a['product'], $a['splitIndex']]
            <=> [$a['depth'], $b['activityType'], $b['product'], $b['splitIndex']]);

        $splitGroupLabels = [];
        foreach ($steps as &$step) {
            unset($step['sortOrder']);
            if ($step['splitGroup'] !== null) {
                $splitGroupLabels[$step['splitGroup']] ??= 'split-'.count($splitGroupLabels);
                $step['splitGroup'] = $splitGroupLabels[$step['splitGroup']];
            }
        }
        unset($step);

        return $steps;
    }

    /**
     * Per product: total runs, number of jobs, the distinct totalGroupRuns of its jobs, ME and TE.
     *
     * @return array<string, array{runs: int, jobs: int, totalGroupRuns: list<int|null>, meLevel: int, teLevel: int}>
     */
    private function summaryPerProductOf(IndustryProject $project): array
    {
        $summary = [];
        foreach ($this->stepsOf($project) as $step) {
            $summary[$step['product']] ??= ['runs' => 0, 'jobs' => 0, 'totalGroupRuns' => [], 'meLevel' => $step['meLevel'], 'teLevel' => $step['teLevel']];
            $summary[$step['product']]['runs'] += $step['runs'];
            ++$summary[$step['product']]['jobs'];
            if (!in_array($step['totalGroupRuns'], $summary[$step['product']]['totalGroupRuns'], true)) {
                $summary[$step['product']]['totalGroupRuns'][] = $step['totalGroupRuns'];
            }
        }
        ksort($summary);

        return $summary;
    }

    /**
     * @param list<IndustryProjectStep> $steps
     * @return list<array{int, int}>
     */
    private function productAndSplitIndexOf(array $steps): array
    {
        return array_map(static fn (IndustryProjectStep $step): array => [$step->getProductTypeId(), $step->getSplitIndex()], $steps);
    }

    /**
     * @param array<mixed> $actual
     */
    private function assertMatchesSnapshot(string $name, array $actual): void
    {
        $snapshotFile = self::SNAPSHOT_DIR.$name.'.json';
        if (getenv('UPDATE_SNAPSHOTS') === '1') {
            file_put_contents($snapshotFile, json_encode($actual, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
        }
        if (!is_file($snapshotFile)) {
            self::fail("Missing snapshot {$snapshotFile}: run the test with UPDATE_SNAPSHOTS=1 to write it from the current output.");
        }

        self::assertSame(json_decode((string) file_get_contents($snapshotFile), true, flags: \JSON_THROW_ON_ERROR), $actual);
    }

    private function typeRepositoryOnGoldenSde(): InvTypeRepository
    {
        $typeRepository = $this->createStub(InvTypeRepository::class);
        $typeRepository->method('find')->willReturnCallback(
            fn (int $typeId): ?InvType => isset($this->sde['typeNames'][(string) $typeId])
                ? (new InvType())->setTypeId($typeId)->setTypeName($this->sde['typeNames'][(string) $typeId])
                : null,
        );

        return $typeRepository;
    }

    private function productRepositoryOnGoldenSde(): IndustryActivityProductRepository
    {
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBlueprintForProduct')->willReturnCallback(
            function (int $productTypeId, int $activityId): ?IndustryActivityProduct {
                $recipe = $this->recipeOfProduct($productTypeId);
                if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
                    return null;
                }

                return $this->activityProduct($productTypeId, $recipe);
            },
        );
        $productRepository->method('findProductsForBlueprints')->willReturnCallback(
            function (array $blueprintTypeIds, array $activityIds): array {
                $products = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    $productTypeId = $blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET;
                    $recipe = $this->recipeOfProduct($productTypeId);
                    if ($recipe !== null && in_array(self::activityIdOf($recipe['activity']), $activityIds, true)) {
                        $products[$blueprintTypeId.'-'.self::activityIdOf($recipe['activity'])] = $this->activityProduct($productTypeId, $recipe);
                    }
                }

                return $products;
            },
        );

        return $productRepository;
    }

    private function materialRepositoryOnGoldenSde(): IndustryActivityMaterialRepository
    {
        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findBy')->willReturnCallback(
            fn (array $criteria): array => $this->activityMaterials($criteria['typeId'], $criteria['activityId']),
        );
        $materialRepository->method('findMaterialEntitiesForBlueprints')->willReturnCallback(
            function (array $blueprintTypeIds, array $activityIds): array {
                $materialsByBlueprint = [];
                foreach (array_unique($blueprintTypeIds) as $blueprintTypeId) {
                    foreach (array_unique($activityIds) as $activityId) {
                        $materials = $this->activityMaterials($blueprintTypeId, $activityId);
                        if ($materials !== []) {
                            $materialsByBlueprint[$blueprintTypeId.'-'.$activityId] = $materials;
                        }
                    }
                }

                return $materialsByBlueprint;
            },
        );

        return $materialRepository;
    }

    private function activityRepositoryOnGoldenSde(): IndustryActivityRepository
    {
        $activityRepository = $this->createStub(IndustryActivityRepository::class);
        $activityRepository->method('findByTypeIdsAndActivityIds')->willReturnCallback(
            function (array $blueprintTypeIds, array $activityIds): array {
                $activities = [];
                foreach ($blueprintTypeIds as $blueprintTypeId) {
                    if (in_array($blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET, $this->typeIdsWithoutBaseTime, true)) {
                        continue;
                    }
                    $recipe = $this->recipeOfProduct($blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET);
                    $activityId = $recipe === null ? null : self::activityIdOf($recipe['activity']);
                    if ($activityId !== null && in_array($activityId, $activityIds, true)) {
                        $activities[$blueprintTypeId.'-'.$activityId] = (new IndustryActivity())
                            ->setTypeId($blueprintTypeId)
                            ->setActivityId($activityId)
                            ->setTime($recipe['baseTimeSeconds']);
                    }
                }

                return $activities;
            },
        );

        return $activityRepository;
    }

    /**
     * @return list<IndustryActivityMaterial>
     */
    private function activityMaterials(int $blueprintTypeId, int $activityId): array
    {
        $recipe = $this->recipeOfProduct($blueprintTypeId - self::BLUEPRINT_TYPE_ID_OFFSET);
        if ($recipe === null || self::activityIdOf($recipe['activity']) !== $activityId) {
            return [];
        }

        $materials = [];
        foreach ($recipe['materials'] as $materialTypeId => $baseQuantityPerRun) {
            $materials[] = (new IndustryActivityMaterial())
                ->setTypeId($blueprintTypeId)
                ->setActivityId($activityId)
                ->setMaterialTypeId((int) $materialTypeId)
                ->setQuantity($baseQuantityPerRun);
        }

        return $materials;
    }

    /**
     * @param array{activity: string, outputPerRun: int} $recipe
     */
    private function activityProduct(int $productTypeId, array $recipe): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($productTypeId + self::BLUEPRINT_TYPE_ID_OFFSET)
            ->setActivityId(self::activityIdOf($recipe['activity']))
            ->setProductTypeId($productTypeId)
            ->setQuantity($recipe['outputPerRun']);
    }

    /**
     * @return array{name: string, activity: string, outputPerRun: int, baseTimeSeconds: int, materials: array<string, int>}|null
     */
    private function recipeOfProduct(int $productTypeId): ?array
    {
        return $this->sde['recipes'][(string) $productTypeId] ?? null;
    }

    /**
     * Without structure, every lookup returns no bonus. With structures, the pilot owns a Tatara in nullsec with
     * Standup L-Set Reactor Efficiency II and a Raitaru without rig, as in the Fernite golden. As in
     * IndustryBonusService, a reaction product takes the category of its formula when looked up as a reaction, other
     * types the category of their group (fuel blocks: ammunition, Quantum Microprocessor: advanced component); the
     * Tatara serves reactions only (no base material bonus, the rig on composite reactions), the Raitaru manufacturing
     * only (1 % base material bonus whatever the category). Only the structure lookups are replaced: the time formula
     * of IndustryBonusService stays real.
     */
    private function bonusServiceOfThePilot(): IndustryBonusService
    {
        $pilotOwnsTataraAndRaitaru = fn (): bool => $this->pilotOwnsTataraAndRaitaru;
        $isReactionProduct = fn (int $typeId): bool => ($this->recipeOfProduct($typeId)['activity'] ?? null) === 'reaction';

        return new class($pilotOwnsTataraAndRaitaru, $isReactionProduct, $this->createStub(IndustryRigCategoryRepository::class), $this->createStub(IndustryStructureConfigRepository::class), $this->createStub(InvTypeRepository::class), $this->createStub(IndustryActivityProductRepository::class)) extends IndustryBonusService {
            private const array FUEL_BLOCKS = [4246, 4051, 4312, 4247];
            private const int QUANTUM_MICROPROCESSOR = 11540;
            private const string FUEL_BLOCK_CATEGORY = 'ammunition';
            private const string ADVANCED_COMPONENT_CATEGORY = 'advanced_component';
            private const string REACTION_CATEGORY = 'composite_reaction';
            // Raitaru without rig: 1 % structure material bonus, 15 % time bonus (IndustryBonusService::STRUCTURE_*_BONUSES)
            private const float RAITARU_BASE_MATERIAL_BONUS = 1.0;
            private const float RAITARU_TIME_BONUS = 15.0;
            // Tatara in nullsec with Standup L-Set Reactor Efficiency II: no base material bonus, 2.4 % × 1.1 on
            // composite reactions; 25 % reaction time bonus
            private const float TATARA_REACTOR_RIG_BONUS = 2.64;
            private const float TATARA_TIME_BONUS = 25.0;

            public function __construct(
                private readonly \Closure $pilotOwnsTataraAndRaitaru,
                private readonly \Closure $isReactionProduct,
                IndustryRigCategoryRepository $categoryRepository,
                IndustryStructureConfigRepository $structureRepository,
                InvTypeRepository $invTypeRepository,
                IndustryActivityProductRepository $activityProductRepository,
            ) {
                parent::__construct($categoryRepository, $structureRepository, $invTypeRepository, $activityProductRepository);
            }

            public function getCategoryForProduct(int $typeId, bool $isReaction = false): ?string
            {
                return match (true) {
                    !($this->pilotOwnsTataraAndRaitaru)() => null,
                    $isReaction && ($this->isReactionProduct)($typeId) => self::REACTION_CATEGORY,
                    in_array($typeId, self::FUEL_BLOCKS, true) => self::FUEL_BLOCK_CATEGORY,
                    $typeId === self::QUANTUM_MICROPROCESSOR => self::ADVANCED_COMPONENT_CATEGORY,
                    default => null,
                };
            }

            public function findBestStructureForProduct(User $user, int $typeId, bool $isReaction = false): array
            {
                if (!($this->pilotOwnsTataraAndRaitaru)()) {
                    return self::noStructure();
                }

                $category = $this->getCategoryForProduct($typeId, $isReaction);

                return $isReaction ? self::tatara($category) : self::raitaru($category);
            }

            public function findBestStructureForCategory(User $user, string $category, bool $isReaction = false): array
            {
                return $isReaction ? self::tatara($category) : self::raitaru($category);
            }

            public function findBestStructureForProductTimeBonus(User $user, int $typeId, bool $isReaction = false): array
            {
                $category = $this->getCategoryForProduct($typeId, $isReaction);

                return match (true) {
                    $category === null => ['structure' => null, 'bonus' => 0.0, 'category' => null],
                    $isReaction => ['structure' => self::tatara($category)['structure'], 'bonus' => self::TATARA_TIME_BONUS, 'category' => $category],
                    default => ['structure' => self::raitaru($category)['structure'], 'bonus' => self::RAITARU_TIME_BONUS, 'category' => $category],
                };
            }

            /** @return array{structure: IndustryStructureConfig, bonus: array{total: float, base: float, rig: float}, category: string|null} */
            private static function tatara(?string $category): array
            {
                $tatara = (new IndustryStructureConfig())->setName('Tatara')->setStructureType('tatara')->setSecurityType('nullsec')
                    ->setRigs(['Standup L-Set Reactor Efficiency II']);
                $rigBonus = $category === self::REACTION_CATEGORY ? self::TATARA_REACTOR_RIG_BONUS : 0.0;

                return ['structure' => $tatara, 'bonus' => ['total' => $rigBonus, 'base' => 0.0, 'rig' => $rigBonus], 'category' => $category];
            }

            /** @return array{structure: IndustryStructureConfig, bonus: array{total: float, base: float, rig: float}, category: string|null} */
            private static function raitaru(?string $category): array
            {
                $raitaru = (new IndustryStructureConfig())->setName('Raitaru')->setStructureType('raitaru')->setSecurityType('nullsec');

                return ['structure' => $raitaru, 'bonus' => ['total' => self::RAITARU_BASE_MATERIAL_BONUS, 'base' => self::RAITARU_BASE_MATERIAL_BONUS, 'rig' => 0.0], 'category' => $category];
            }

            /** @return array{structure: null, bonus: array{total: float, base: float, rig: float}, category: null} */
            private static function noStructure(): array
            {
                return ['structure' => null, 'bonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0], 'category' => null];
            }
        };
    }

    private static function activityIdOf(string $activity): int
    {
        return $activity === 'reaction' ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
    }
}
