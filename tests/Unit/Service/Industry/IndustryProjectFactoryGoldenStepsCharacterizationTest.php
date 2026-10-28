<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\User;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\IndustryStepCalculator;
use App\Service\Industry\SplitGroupQuantities;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

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
#[CoversClass(SplitGroupQuantities::class)]
final class IndustryProjectFactoryGoldenStepsCharacterizationTest extends TestCase
{
    use BuildsProjectOnGoldenSde;

    private const string SNAPSHOT_DIR = __DIR__.'/../../../Fixtures/Characterization/IndustryProjectFactory/';

    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;
    private const int TITANIUM_CARBIDE = 16671;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int OXYGEN_FUEL_BLOCK = 4312;
    private const int HELIUM_FUEL_BLOCK = 4247;
    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const int NOMAD = 28846;
    private const int CAPITAL_ELECTROLYTIC_CAPACITOR_UNIT = 29045;
    private const int FERMIONIC_CONDENSATES = 17317;
    private const int ROBOTICS = 9848;
    private const int NANOTRANSISTORS = 16681;
    private const int WIDOW = 22436;
    private const int MAGPULSE_THRUSTER = 11533;
    private const int SUSTAINED_SHIELD_EMITTER = 11558;
    private const int TITANIUM_CHROMIDE = 16654;
    private const int SYLRAMIC_FIBERS = 16678;
    private const int FERROGEL = 16683;
    private const int SCORPION = 640;
    private const int AUTO_INTEGRITY_PRESERVATION_SEAL = 57478;
    private const int CORE_TEMPERATURE_REGULATOR = 57479;
    private const int LIFE_SUPPORT_BACKUP_UNIT = 57486;
    private const int MACHARIEL = 17738;
    private const int OXY_ORGANIC_SOLVENTS = 57454;
    private const int THERMOSETTING_POLYMER = 57455;
    private const int PRESSURIZED_OXIDIZERS = 57456;
    private const int REINFORCED_CARBON_FIBER = 57457;
    private const int UNKNOWN_BLUEPRINT_TYPE_ID = 999_999_999;

    private const float DEFAULT_MAX_JOB_DURATION_DAYS = 2.0;

    private const array PRODUCTS_OF_A_QUANTUM_MICROPROCESSOR_WITHOUT_TITANIUM_CARBIDE_NOR_PHENOLIC_COMPOSITES = [
        'Ferrofluid',
        'Helium Fuel Block',
        'Hydrogen Fuel Block',
        'Nanotransistors',
        'Neo Mercurite',
        'Nitrogen Fuel Block',
        'Nonlinear Metamaterials',
        'Oxygen Fuel Block',
        'Platinum Technite',
        'Quantum Microprocessor',
        'Sulfuric Acid',
        'Titanium Chromide',
    ];
    private const float MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING = 10_000.0;

    private const array ACTIVITY_ORDER = ['reaction' => 0, 'copy' => 1, 'manufacturing' => 2];

    private const array FIVE_MANUFACTURING_STEPS_OF_ONE_NOMAD_IN_SORT_ORDER = [
        [0, 'Helium Fuel Block', 'manufacturing', 5, 0, 107, 4245],
        [1, 'Hydrogen Fuel Block', 'manufacturing', 5, 0, 161, 6440],
        [2, 'Hydrogen Fuel Block', 'manufacturing', 5, 1, 161, 6415],
        [3, 'Nitrogen Fuel Block', 'manufacturing', 5, 0, 106, 4225],
        [4, 'Oxygen Fuel Block', 'manufacturing', 5, 0, 44, 1735],
    ];

    /** @var array{recipes: array<string, array{name: string, activity: string, outputPerRun: int, baseTimeSeconds: int, materials: array<string, int>}>, typeNames: array<string, string>} */
    private array $sde;

    private bool $pilotOwnsTataraAndRaitaru = false;

    private User $pilot;
    private IndustryProjectFactory $factory;
    private IndustryStepCalculator $stepCalculator;
    private IndustryCalculationService $calculationService;

    protected function setUp(): void
    {
        $this->sde = json_decode((string) file_get_contents(self::GOLDEN_DIR.'sde-blueprint-recipes.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->pilot = new User();

        $bonusService = $this->bonusServiceOfThePilot();
        $this->calculationService = $this->calculationServiceOnGoldenSde($bonusService, $this->createStub(IndustryStructureConfigRepository::class));
        $this->factory = $this->projectFactoryOnGoldenSde($bonusService, $this->calculationService);
        $this->stepCalculator = new IndustryStepCalculator($this->calculationService, $this->materialRepositoryOnGoldenSde(), $this->productRepositoryOnGoldenSde(), $this->entityManagerWithoutRows());
    }

    // ===========================================
    // createProject() on the goldens
    // ===========================================

    #[Group('issue-2')]
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

    #[Group('issue-2')]
    public function testStepsOfOneRunOfFerniteCarbideInATataraCurrentlyPlanTheRootReactionForOneUnitIssue2(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $project = $this->createProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 10, teLevel: 20);

        self::assertSame(
            [['product' => 'Fernite Carbide', 'productTypeId' => self::FERNITE_CARBIDE, 'blueprintTypeId' => 1_016_673, 'activityType' => 'reaction', 'depth' => 0, 'runs' => 1, 'quantity' => 1, 'meLevel' => 0, 'teLevel' => 0, 'splitGroup' => null, 'splitIndex' => 0, 'totalGroupRuns' => null]],
            array_values(array_filter($this->canonicalStepsOf($project), static fn (array $step): bool => $step['depth'] === 0)),
        );
        self::assertSame(['Ceramic Powder', 'Fernite Alloy', 'Hydrogen Fuel Block', 'Fernite Carbide'], array_column($this->stepsOf($project), 'product'));
    }

    public function testStepsOfOneRunOfFerniteCarbideInATataraMergeTheFuelBlocksOfTheRootAndOfBothReactionsInOneStep(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $project = $this->createProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 10, teLevel: 20);

        self::assertSame(
            [
                ['Ceramic Powder', 'reaction', 1, 1, 98, 0, 0],
                ['Fernite Alloy', 'reaction', 1, 1, 98, 0, 0],
                ['Hydrogen Fuel Block', 'manufacturing', 1, 1, 15, 10, 20],
            ],
            array_map(
                static fn (array $step): array => [$step['product'], $step['activityType'], $step['depth'], $step['runs'], $step['quantity'], $step['meLevel'], $step['teLevel']],
                array_values(array_filter($this->stepsOf($project), static fn (array $step): bool => $step['depth'] > 0)),
            ),
        );
        $this->assertMatchesSnapshot(
            'fernite-carbide-1run-tatara-nullsec',
            array_values(array_filter($this->canonicalStepsOf($project), static fn (array $step): bool => $step['depth'] > 0)),
        );
    }

    #[Group('issue-69')]
    public function testStepsOfOneNomadPlanTheFuelBlocksOnTheNeedAggregatedAcrossThePlan(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20);

        $steps = $this->stepsOf($project);
        self::assertSame([[1, 1, 0, 10, 20, 0, null]], $this->jobsOf($steps, self::NOMAD));
        self::assertNull($this->stepsOfProduct($steps, self::NOMAD)[0]['splitGroup']);
        self::assertSame(
            [[161, 6440, 5, 10, 20, 0, 322], [161, 6415, 5, 10, 20, 1, 322]],
            $this->jobsOf($steps, self::HYDROGEN_FUEL_BLOCK),
        );
        self::assertSame([[106, 4225, 5, 10, 20, 0, null]], $this->jobsOf($steps, self::NITROGEN_FUEL_BLOCK));
        self::assertSame([[44, 1735, 5, 10, 20, 0, null]], $this->jobsOf($steps, self::OXYGEN_FUEL_BLOCK));
        self::assertSame([[107, 4245, 5, 10, 20, 0, null]], $this->jobsOf($steps, self::HELIUM_FUEL_BLOCK));
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

    #[Group('issue-15')]
    public function testReactionConsumedByAnMeZeroRootIsPlannedAtTheMeOfTheRoot(): void
    {
        $project = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 32, meLevel: 0, teLevel: 20);

        $steps = $this->stepsOf($project);
        self::assertSame([[32, 32, 0, 0, 20, 0, null]], $this->jobsOf($steps, self::QUANTUM_MICROPROCESSOR));
        self::assertSame([[1, 544, 1, 0, 0, 0, null]], $this->jobsOf($steps, self::TITANIUM_CARBIDE));
    }

    #[Group('issue-15')]
    public function testReactionConsumedByAnMeTenRootIsPlannedAtMeTen(): void
    {
        $project = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 32, meLevel: 10, teLevel: 20);

        $steps = $this->stepsOf($project);
        self::assertSame([[32, 32, 0, 10, 20, 0, null]], $this->jobsOf($steps, self::QUANTUM_MICROPROCESSOR));
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
    #[Group('issue-73')]
    public function testNeedOfEachSplitGroupOfOneNomadFollowsTheMaterialsOfEachJobOfItsConsumers(): void
    {
        $split = $this->stepsOf($this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20));
        $unsplit = $this->stepsOf($this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING));

        $splitProducts = array_unique(array_column(array_filter($split, static fn (array $step): bool => $step['splitGroup'] !== null), 'productTypeId', 'product'));
        self::assertSame([], array_filter($unsplit, static fn (array $step): bool => $step['splitGroup'] !== null));
        self::assertSame(
            array_map(fn (int $productTypeId): int => $this->totalQuantityOf($unsplit, $productTypeId) - $this->materialsOfTheJobsConsuming($unsplit, $productTypeId), $splitProducts),
            array_map(fn (int $productTypeId): int => $this->totalQuantityOf($split, $productTypeId) - $this->materialsOfTheJobsConsuming($split, $productTypeId), $splitProducts),
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

    #[Group('issue-73')]
    public function testSplitConsumerNeedsTheMaterialsOfEachOfItsJobsAtCreation(): void
    {
        $this->typeIdsWithoutBaseTime = [self::TITANIUM_CARBIDE];

        $project = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 32, meLevel: 10, teLevel: 20, maxJobDurationDays: 0.01);

        $steps = $this->stepsOf($project);
        self::assertSame([[11, 11, 0, 10, 20, 0, 32], [11, 11, 0, 10, 20, 1, 32], [10, 10, 0, 10, 20, 2, 32]], $this->jobsOf($steps, self::QUANTUM_MICROPROCESSOR));
        self::assertSame([[1, 491, 1, 0, 0, 0, null]], $this->jobsOf($steps, self::TITANIUM_CARBIDE));
        self::assertSame([[1, 174, 1, 0, 0, 0, null]], $this->jobsOf($steps, self::PHENOLIC_COMPOSITES));
        self::assertSame([[1, 58, 1, 0, 0, 0, null]], $this->jobsOf($steps, self::NANOTRANSISTORS));
    }

    #[Group('issue-73')]
    public function testSplitManufacturingConsumerOfAManufacturedIntermediateNeedsTheMaterialsOfEachOfItsJobsAtCreation(): void
    {
        $project = $this->createProject(self::WIDOW, runs: 3, meLevel: 10, teLevel: 20);

        $steps = $this->stepsOf($project);
        self::assertSame([[1, 1, 0, 10, 20, 0, 3], [1, 1, 0, 10, 20, 1, 3], [1, 1, 0, 10, 20, 2, 3]], $this->jobsOf($steps, self::WIDOW));
        self::assertSame([[609, 609, 1, 10, 20, 0, null]], $this->jobsOf($steps, self::MAGPULSE_THRUSTER));
        self::assertSame(
            [[1104, 1104, 1, 10, 20, 0, 4413], [1103, 1103, 1, 10, 20, 1, 4413], [1103, 1103, 1, 10, 20, 2, 4413], [1103, 1103, 1, 10, 20, 3, 4413]],
            $this->jobsOf($steps, self::SUSTAINED_SHIELD_EMITTER),
        );
    }

    #[Group('issue-73')]
    public function testSplitReactionConsumerInATataraNeedsTheFuelBlocksOfEachOfItsJobsWithTheRaitaruBonus(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $project = $this->createProject(self::TITANIUM_CHROMIDE, runs: 4000, meLevel: 0, teLevel: 0, maxJobDurationDays: 0.05);

        $steps = $this->stepsOf($project);
        self::assertSame(
            array_map(static fn (int $splitIndex): array => [1, 200, 0, 0, 0, $splitIndex, 20], range(0, 19)),
            $this->jobsOf($steps, self::TITANIUM_CHROMIDE),
        );
        self::assertSame([[3, 100, 1, 10, 20, 0, null]], $this->jobsOf($steps, self::OXYGEN_FUEL_BLOCK));
    }

    #[Group('issue-73')]
    public function testSplitManufacturingConsumerInARaitaruNeedsTheIntermediatesOfEachOfItsJobsWithTheRaitaruBonus(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $project = $this->createProject(self::SCORPION, runs: 5, meLevel: 10, teLevel: 20, maxJobDurationDays: 0.1);

        $steps = $this->stepsOf($project);
        self::assertSame(
            array_map(static fn (int $splitIndex): array => [1, 1, 0, 10, 20, $splitIndex, 5], range(0, 4)),
            $this->jobsOf($steps, self::SCORPION),
        );
        self::assertSame(
            [self::AUTO_INTEGRITY_PRESERVATION_SEAL => [167, 500], self::LIFE_SUPPORT_BACKUP_UNIT => [84, 250], self::CORE_TEMPERATURE_REGULATOR => [5, 5]],
            array_map(
                fn (int $productTypeId): array => [$this->totalRunsOf($steps, $productTypeId), $this->totalQuantityOf($steps, $productTypeId)],
                [self::AUTO_INTEGRITY_PRESERVATION_SEAL => self::AUTO_INTEGRITY_PRESERVATION_SEAL, self::LIFE_SUPPORT_BACKUP_UNIT => self::LIFE_SUPPORT_BACKUP_UNIT, self::CORE_TEMPERATURE_REGULATOR => self::CORE_TEMPERATURE_REGULATOR],
            ),
        );
    }

    #[Group('issue-73')]
    public function testSplitManufacturingConsumerWithoutRigCategoryNeedsTheReactionsOfEachOfItsJobsWithoutStructureBonus(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $project = $this->createProject(self::SUSTAINED_SHIELD_EMITTER, runs: 10, meLevel: 10, teLevel: 20, maxJobDurationDays: 0.002);

        $steps = $this->stepsOf($project);
        self::assertSame(
            array_map(static fn (int $splitIndex): array => [1, 1, 0, 10, 20, $splitIndex, 10], range(0, 9)),
            $this->jobsOf($steps, self::SUSTAINED_SHIELD_EMITTER),
        );
        self::assertSame(
            [self::TITANIUM_CARBIDE => [[1, 200, 1, 0, 0, 0, null]], self::SYLRAMIC_FIBERS => [[1, 90, 1, 0, 0, 0, null]], self::FERROGEL => [[1, 10, 1, 0, 0, 0, null]]],
            [self::TITANIUM_CARBIDE => $this->jobsOf($steps, self::TITANIUM_CARBIDE), self::SYLRAMIC_FIBERS => $this->jobsOf($steps, self::SYLRAMIC_FIBERS), self::FERROGEL => $this->jobsOf($steps, self::FERROGEL)],
        );
    }

    #[Group('issue-69')]
    public function testReactionConsumedByTwoBranchesIsPlannedOnTheNeedOfBothConsumerSteps(): void
    {
        $project = $this->createProject(self::MACHARIEL, runs: 1, meLevel: 10, teLevel: 20);

        $steps = $this->stepsOf($project);
        self::assertSame([[3, 450, 2, 0, 0, 0, null]], $this->jobsOf($steps, self::PRESSURIZED_OXIDIZERS));
        self::assertSame([[8, 1422, 2, 0, 0, 0, null]], $this->jobsOf($steps, self::REINFORCED_CARBON_FIBER));
        self::assertSame([[2, 11, 3, 0, 0, 0, null]], $this->jobsOf($steps, self::OXY_ORGANIC_SOLVENTS));
    }

    #[Group('issue-69')]
    #[Group('issue-73')]
    public function testIntermediateConsumedByASplitGroupAndAnUnsplitStepIsPlannedOnTheNeedOfEveryJob(): void
    {
        $project = $this->createProject(self::CORE_TEMPERATURE_REGULATOR, runs: 10, meLevel: 10, teLevel: 20);

        $steps = $this->stepsOf($project);
        self::assertSame([[12, 2400, 2, 0, 0, 0, 23], [11, 2200, 2, 0, 0, 1, 23]], $this->jobsOf($steps, self::THERMOSETTING_POLYMER));
        self::assertSame([[5, 46, 2, 0, 0, 0, null]], $this->jobsOf($steps, self::OXY_ORGANIC_SOLVENTS));
        self::assertSame([[4, 140, 3, 10, 20, 0, null]], $this->jobsOf($steps, self::OXYGEN_FUEL_BLOCK));
    }

    #[Group('issue-69')]
    public function testIntermediatesOfTenScorpionsInATataraAndARaitaruAreAggregatedWithTheBonusRuleOfEachPath(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;

        $project = $this->createProject(self::SCORPION, runs: 10, meLevel: 10, teLevel: 20);

        $steps = $this->stepsOf($project);
        self::assertSame([[7, 255, 4, 10, 20, 0, null]], $this->jobsOf($steps, self::OXYGEN_FUEL_BLOCK));
        self::assertSame([[6, 220, 4, 10, 20, 0, null]], $this->jobsOf($steps, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame([[7, 68, 3, 0, 0, 0, null]], $this->jobsOf($steps, self::OXY_ORGANIC_SOLVENTS));
        self::assertSame([[12, 2400, 2, 0, 0, 0, 23], [11, 2100, 2, 0, 0, 1, 23]], $this->jobsOf($steps, self::PRESSURIZED_OXIDIZERS));
        self::assertSame(
            [[15, 3000, 2, 0, 0, 0, 45], [15, 3000, 2, 0, 0, 1, 45], [15, 3000, 2, 0, 0, 2, 45]],
            $this->jobsOf($steps, self::REINFORCED_CARBON_FIBER),
        );
        self::assertSame(
            [[111, 333, 1, 10, 20, 0, 333], [111, 333, 1, 10, 20, 1, 333], [111, 332, 1, 10, 20, 2, 333]],
            $this->jobsOf($steps, self::AUTO_INTEGRITY_PRESERVATION_SEAL),
        );
    }

    public function testComponentsExcludedFromAProjectAreNeitherPlannedNorAreTheirOwnIntermediates(): void
    {
        $project = $this->factory->createProject($this->pilot, self::QUANTUM_MICROPROCESSOR, 32, 10, self::DEFAULT_MAX_JOB_DURATION_DAYS, 20, null, [self::TITANIUM_CARBIDE, self::PHENOLIC_COMPOSITES, self::TITANIUM_CARBIDE]);

        self::assertSame(self::PRODUCTS_OF_A_QUANTUM_MICROPROCESSOR_WITHOUT_TITANIUM_CARBIDE_NOR_PHENOLIC_COMPOSITES, $this->sortedProductNamesOf($project));
    }

    public function testRegeneratedStepsOfAProjectPlanNeitherItsExcludedComponentsNorTheBlacklist(): void
    {
        $this->pilot->setIndustryBlacklistTypeIds([self::TITANIUM_CARBIDE]);
        $project = (new IndustryProject())
            ->setUser($this->pilot)
            ->setProductTypeId(self::QUANTUM_MICROPROCESSOR)
            ->setRuns(32)
            ->setMeLevel(10)
            ->setTeLevel(20)
            ->setMaxJobDurationDays(self::DEFAULT_MAX_JOB_DURATION_DAYS)
            ->setExcludedTypeIds([self::PHENOLIC_COMPOSITES]);

        $this->factory->regenerateSteps($project);

        self::assertSame(self::PRODUCTS_OF_A_QUANTUM_MICROPROCESSOR_WITHOUT_TITANIUM_CARBIDE_NOR_PHENOLIC_COMPOSITES, $this->sortedProductNamesOf($project));
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

    #[Group('issue-73')]
    public function testRegeneratedStepsOfASplitConsumerNeedTheMaterialsOfEachOfItsJobsAsAtCreation(): void
    {
        $this->typeIdsWithoutBaseTime = [self::TITANIUM_CARBIDE];
        $regenerated = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 32, meLevel: 10, teLevel: 20);
        $regenerated->setMaxJobDurationDays(0.01);

        $this->factory->regenerateSteps($regenerated);

        $created = $this->createProject(self::QUANTUM_MICROPROCESSOR, runs: 32, meLevel: 10, teLevel: 20, maxJobDurationDays: 0.01);
        self::assertSame([[1, 491, 1, 0, 0, 0, null]], $this->jobsOf($this->stepsOf($regenerated), self::TITANIUM_CARBIDE));
        self::assertSame($this->canonicalStepsOf($created), $this->canonicalStepsOf($regenerated));
    }

    #[Group('issue-73')]
    public function testRegeneratedSplitReactionConsumerInATataraNeedsTheFuelBlocksOfEachOfItsJobsWithTheRaitaruBonus(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;
        $regenerated = $this->createProject(self::TITANIUM_CHROMIDE, runs: 4000, meLevel: 0, teLevel: 0, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);
        $regenerated->setMaxJobDurationDays(0.05);

        $this->factory->regenerateSteps($regenerated);

        $steps = $this->stepsOf($regenerated);
        self::assertCount(20, $this->stepsOfProduct($steps, self::TITANIUM_CHROMIDE));
        self::assertSame([[3, 100, 1, 10, 20, 0, null]], $this->jobsOf($steps, self::OXYGEN_FUEL_BLOCK));
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

    #[Group('issue-16')]
    public function testRecalculatedFuelBlockOfFerniteCarbideSumsTheNeedOfTheRootAndOfTheReactions(): void
    {
        $this->pilotOwnsTataraAndRaitaru = true;
        $project = $this->createProject(self::FERNITE_CARBIDE, runs: 1, meLevel: 10, teLevel: 20);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame([], $this->productAndSplitIndexOf($changedSteps));
        self::assertSame([[1, 15, 1, 10, 20, 0, null]], $this->jobsOf($this->stepsOf($project), self::HYDROGEN_FUEL_BLOCK));
        self::assertSame([[1, 98, 1, 0, 0, 0, null]], $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY));
        self::assertSame([[1, 98, 1, 0, 0, 0, null]], $this->jobsOf($this->stepsOf($project), self::CERAMIC_POWDER));
    }

    #[Group('issue-16')]
    #[Group('issue-112')]
    public function testRecalculatingTheStepsOfOneNomadSumsTheNeedOfEveryConsumerOfAMaterialConsumedAtSeveralDepths(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        $steps = $this->stepsOf($project);
        self::assertSame([[161, 6440, 5, 10, 20, 0, 322], [161, 6415, 5, 10, 20, 1, 322]], $this->jobsOf($steps, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame([[106, 4225, 5, 10, 20, 0, null]], $this->jobsOf($steps, self::NITROGEN_FUEL_BLOCK));
        self::assertSame([[44, 1735, 5, 10, 20, 0, null]], $this->jobsOf($steps, self::OXYGEN_FUEL_BLOCK));
        self::assertSame([[107, 4245, 5, 10, 20, 0, null]], $this->jobsOf($steps, self::HELIUM_FUEL_BLOCK));
        self::assertSame([550, 550], [$this->totalRunsOf($steps, self::FERNITE_ALLOY), $this->stepsOfProduct($steps, self::FERNITE_ALLOY)[0]['totalGroupRuns']]);
        self::assertSame([70, 70], [$this->totalRunsOf($steps, self::FERMIONIC_CONDENSATES), $this->stepsOfProduct($steps, self::FERMIONIC_CONDENSATES)[0]['totalGroupRuns']]);
        self::assertSame([], $this->sortedProductAndSplitIndexOf($changedSteps));
        $this->assertMatchesSnapshot('nomad-1run-me10-no-structure-recalculated-per-product', $this->summaryPerProductOf($project));
    }

    #[Group('issue-69')]
    public function testRecalculatingTheStepsOfAJustCreatedNomadChangesNoStep(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20);
        $createdSteps = $this->stepsOf($project);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame([], $this->productAndSplitIndexOf($changedSteps));
        self::assertSame($createdSteps, $this->stepsOf($project));
    }

    #[Group('issue-16')]
    public function testSecondRecalculationOfOneNomadChangesNoStep(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, meLevel: 10, teLevel: 20);
        $this->stepCalculator->recalculateStepQuantities($project);
        $stepsAfterTheFirstRecalculation = $this->stepsOf($project);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame([], $this->productAndSplitIndexOf($changedSteps));
        self::assertSame($stepsAfterTheFirstRecalculation, $this->stepsOf($project));
    }

    #[Group('issue-16')]
    public function testMaterialConsumedAtASingleDepthKeepsTheNeedOfItsConsumer(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 3, quantity: 30_000, depth: 0, meLevel: 0),
            $this->step(self::CERAMIC_POWDER, 'reaction', runs: 9, quantity: 1800, depth: 1, meLevel: 0),
        ]);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame([[2, 300, 1, 0, 20, 0, null]], $this->jobsOf($this->stepsOf($project), self::CERAMIC_POWDER));
        self::assertSame([[self::CERAMIC_POWDER, 0]], $this->productAndSplitIndexOf($changedSteps));
    }

    #[Group('issue-16')]
    #[Group('issue-112')]
    public function testSplitGroupOfThreeAndTwoRunsSharesANewTotalOfSevenRunsAsFourAndThree(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 14, quantity: 140_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 3, quantity: 600, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0, totalGroupRuns: 5),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 1, totalGroupRuns: 5),
        ]);

        $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame(
            [[4, 800, 1, 0, 20, 0, 7], [3, 600, 1, 0, 20, 1, 7]],
            $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY),
        );
    }

    #[Group('issue-16')]
    public function testMaterialConsumedByAnUnsplitStepAndBySplitJobsAtAnotherDepthSumsTheNeedOfEveryJob(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 6, quantity: 60_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 3, quantity: 600, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0, totalGroupRuns: 6),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 3, quantity: 550, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 1, totalGroupRuns: 6),
            $this->step(self::HYDROGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 1, depth: 2, meLevel: 10),
        ]);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        $steps = $this->stepsOf($project);
        self::assertSame([[2, 400, 1, 0, 20, 0, 3], [1, 200, 1, 0, 20, 1, 3]], $this->jobsOf($steps, self::FERNITE_ALLOY));
        self::assertSame([[2, 45, 2, 10, 20, 0, null]], $this->jobsOf($steps, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame(
            [[self::HYDROGEN_FUEL_BLOCK, 0], [self::FERNITE_ALLOY, 0], [self::FERNITE_ALLOY, 1]],
            $this->sortedProductAndSplitIndexOf($changedSteps),
        );
    }

    #[Group('issue-16')]
    public function testNeedOfAMaterialFollowsTheFinalRunsOfItsConsumersWhateverTheOrderAndTheDepthOfTheSteps(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 1, quantity: 200, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0, totalGroupRuns: 2),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 1, quantity: 200, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 1, totalGroupRuns: 2),
            $this->step(self::HYDROGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 40, depth: 1, meLevel: 10),
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 8, quantity: 80_000, depth: 0, meLevel: 0),
        ]);

        $this->stepCalculator->recalculateStepQuantities($project);

        $steps = $this->stepsOf($project);
        self::assertSame([[2, 400, 1, 0, 20, 0, 4], [2, 400, 1, 0, 20, 1, 4]], $this->jobsOf($steps, self::FERNITE_ALLOY));
        self::assertSame([[2, 60, 1, 10, 20, 0, null]], $this->jobsOf($steps, self::HYDROGEN_FUEL_BLOCK));
    }

    #[Group('issue-16')]
    public function testCopyStepsNeitherConsumeNorReceiveANeed(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::QUANTUM_MICROPROCESSOR, 'manufacturing', runs: 32, quantity: 32, depth: 0, meLevel: 10),
            $this->step(self::QUANTUM_MICROPROCESSOR, 'copy', runs: 9, quantity: 9, depth: 0, meLevel: 0),
            $this->step(self::TITANIUM_CARBIDE, 'reaction', runs: 1, quantity: 1, depth: 1, meLevel: 0),
            $this->step(self::PHENOLIC_COMPOSITES, 'reaction', runs: 1, quantity: 1, depth: 1, meLevel: 0),
            $this->step(self::OXYGEN_FUEL_BLOCK, 'manufacturing', runs: 1, quantity: 1, depth: 2, meLevel: 10),
            $this->step(self::OXYGEN_FUEL_BLOCK, 'copy', runs: 4, quantity: 4, depth: 2, meLevel: 0),
        ]);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        $steps = $this->stepsOf($project);
        self::assertSame([[1, 490, 1, 0, 20, 0, null]], $this->jobsOf($steps, self::TITANIUM_CARBIDE));
        self::assertSame([[1, 173, 1, 0, 20, 0, null]], $this->jobsOf($steps, self::PHENOLIC_COMPOSITES));
        self::assertSame([[1, 10, 2, 10, 20, 0, null], [4, 4, 2, 0, 20, 0, null]], $this->jobsOf($steps, self::OXYGEN_FUEL_BLOCK));
        self::assertSame([[32, 32, 0, 10, 20, 0, null], [9, 9, 0, 0, 20, 0, null]], $this->jobsOf($steps, self::QUANTUM_MICROPROCESSOR));
        self::assertSame(
            [[self::OXYGEN_FUEL_BLOCK, 0], [self::TITANIUM_CARBIDE, 0], [self::PHENOLIC_COMPOSITES, 0]],
            $this->sortedProductAndSplitIndexOf($changedSteps),
        );
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

    #[Group('issue-131')]
    public function testSplitChildGroupCurrentlyKeepsTwoRunsForANeedOfOneIssue131(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 1, quantity: 10_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 3, quantity: 600, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 1),
        ]);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame(
            [[1, 100, 1, 0, 20, 0, 1], [1, 0, 1, 0, 20, 1, 1]],
            $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY),
        );
        self::assertSame([[self::FERNITE_ALLOY, 0], [self::FERNITE_ALLOY, 1]], $this->productAndSplitIndexOf($changedSteps));
    }

    #[Group('issue-252')]
    public function testJobOfARecalculatedSplitGroupWhoseRunsAndQuantityStayTakesTheNewTotalGroupRuns(): void
    {
        $project = $this->ferniteCarbideOfElevenRunsWithAnAlloyGroupOfTwoTwoAndOneRuns();

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame(
            [[2, 400, 1, 0, 20, 0, 6], [2, 400, 1, 0, 20, 1, 6], [2, 300, 1, 0, 20, 2, 6]],
            $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY),
        );
        self::assertSame(
            [[self::FERNITE_ALLOY, 0], [self::FERNITE_ALLOY, 1], [self::FERNITE_ALLOY, 2]],
            $this->sortedProductAndSplitIndexOf($changedSteps),
        );
    }

    #[Group('issue-252')]
    public function testSplitGroupWhoseJobsKeepTheirRunsAndQuantityTakesTheRunsOfTheNeedAsTotalGroupRunsOnEveryJob(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 12, quantity: 120_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0, totalGroupRuns: 5),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 1, totalGroupRuns: 5),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 2, totalGroupRuns: 5),
        ]);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame(
            [[2, 400, 1, 0, 20, 0, 6], [2, 400, 1, 0, 20, 1, 6], [2, 400, 1, 0, 20, 2, 6]],
            $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY),
        );
        self::assertSame(
            [[self::FERNITE_ALLOY, 0], [self::FERNITE_ALLOY, 1], [self::FERNITE_ALLOY, 2]],
            $this->sortedProductAndSplitIndexOf($changedSteps),
        );
    }

    #[Group('issue-252')]
    public function testRecalculationThatOnlyRewritesTheTotalGroupRunsFlushesTheSteps(): void
    {
        $stepCalculator = $this->stepCalculatorExpectingOneFlush();
        $project =$this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 12, quantity: 120_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 3, quantity: 600, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0, totalGroupRuns: 4),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 3, quantity: 600, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 1, totalGroupRuns: 4),
        ]);

        $stepCalculator->recalculateStepQuantities($project);
    }

    #[Group('issue-252')]
    public function testSecondRecalculationOfARecalculatedSplitGroupChangesNoJob(): void
    {
        $project = $this->ferniteCarbideOfElevenRunsWithAnAlloyGroupOfTwoTwoAndOneRuns();
        $this->stepCalculator->recalculateStepQuantities($project);
        $stepsAfterTheFirstRecalculation = $this->stepsOf($project);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame([], $this->productAndSplitIndexOf($changedSteps));
        self::assertSame($stepsAfterTheFirstRecalculation, $this->stepsOf($project));
    }

    #[Group('issue-252')]
    public function testJobWithoutTotalGroupRunsWhoseRunsAndQuantityStayTakesTheTotalGroupRunsOfItsGroup(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 7, quantity: 70_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 3, quantity: 600, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 1),
        ]);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame(
            [[2, 400, 1, 0, 20, 0, 4], [2, 300, 1, 0, 20, 1, 4]],
            $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY),
        );
        self::assertSame(
            [[self::FERNITE_ALLOY, 0], [self::FERNITE_ALLOY, 1]],
            $this->sortedProductAndSplitIndexOf($changedSteps),
        );
    }

    #[Group('issue-252')]
    #[Group('issue-131')]
    public function testSplitGroupKeepingMoreRunsThanItsNeedTakesTheRunsOfTheNeedAsTotalGroupRunsOnEveryJob(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 1, quantity: 10_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 1, quantity: 100, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0, totalGroupRuns: 2),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 1, quantity: 0, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 1, totalGroupRuns: 2),
        ]);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame(
            [[1, 100, 1, 0, 20, 0, 1], [1, 0, 1, 0, 20, 1, 1]],
            $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY),
        );
        self::assertSame(
            [[self::FERNITE_ALLOY, 0], [self::FERNITE_ALLOY, 1]],
            $this->sortedProductAndSplitIndexOf($changedSteps),
        );
    }

    #[Group('issue-252')]
    #[Group('issue-185')]
    public function testSplitGroupWithAFixedJobTakesTheSumOfTheRunsOfItsJobsAsTotalGroupRunsOnEveryJob(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 11, quantity: 110_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 4, quantity: 800, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0, totalGroupRuns: 5)->setRunsFixed(true),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 300, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 1, totalGroupRuns: 5),
        ]);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame(
            [[4, 800, 1, 0, 20, 0, 6], [2, 300, 1, 0, 20, 1, 6]],
            $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY),
        );
        self::assertSame(
            [[self::FERNITE_ALLOY, 0], [self::FERNITE_ALLOY, 1]],
            $this->sortedProductAndSplitIndexOf($changedSteps),
        );
    }

    #[Group('issue-252')]
    public function testSplitGroupOfOneJobWhoseRunsAndTotalGroupRunsMatchTheNeedChangesNoJob(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 9, quantity: 90_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 5, quantity: 900, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0, totalGroupRuns: 5),
        ]);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame([[5, 900, 1, 0, 20, 0, 5]], $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY));
        self::assertSame([], $this->productAndSplitIndexOf($changedSteps));
    }

    #[Group('issue-252')]
    public function testSplitGroupOfOneJobWhoseRunsMatchTheNeedTakesTheRunsOfTheNeedAsTotalGroupRuns(): void
    {
        $project = $this->ferniteCarbideOfThirteenRunsWithAnAlloyGroupOfOneJobOfSevenRunsAndAStaleTotalOfFour();

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame([[7, 1300, 1, 0, 20, 0, 7]], $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY));
        self::assertSame([[self::FERNITE_ALLOY, 0]], $this->productAndSplitIndexOf($changedSteps));
    }

    #[Group('issue-252')]
    public function testSplitGroupOfOneJobWhoseRunsChangeTakesTheRunsOfTheNeedAsTotalGroupRuns(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 15, quantity: 150_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 3, quantity: 600, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0, totalGroupRuns: 3),
        ]);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame([[8, 1500, 1, 0, 20, 0, 8]], $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY));
        self::assertSame([[self::FERNITE_ALLOY, 0]], $this->productAndSplitIndexOf($changedSteps));
    }

    #[Group('issue-252')]
    public function testRecalculationThatOnlyRewritesTheTotalGroupRunsOfASplitGroupOfOneJobFlushesTheStep(): void
    {
        $stepCalculator = $this->stepCalculatorExpectingOneFlush();

        $stepCalculator->recalculateStepQuantities($this->ferniteCarbideOfThirteenRunsWithAnAlloyGroupOfOneJobOfSevenRunsAndAStaleTotalOfFour());
    }

    #[Group('issue-112')]
    #[Group('issue-252')]
    public function testRecalculatedSplitGroupSharesTheNeedBySplitIndexWhateverTheOrderOfTheSteps(): void
    {
        $project = $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 11, quantity: 110_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 1, quantity: 200, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 2, totalGroupRuns: 5),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0, totalGroupRuns: 5),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 350, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 1, totalGroupRuns: 5),
        ]);

        $this->stepCalculator->recalculateStepQuantities($project);

        $jobs = $this->jobsOf($this->stepsOf($project), self::FERNITE_ALLOY);
        usort($jobs, static fn (array $a, array $b): int => $a[5] <=> $b[5]);
        self::assertSame(
            [[2, 400, 1, 0, 20, 0, 6], [3, 600, 1, 0, 20, 1, 6], [1, 100, 1, 0, 20, 2, 6]],
            $jobs,
        );
    }

    #[Group('issue-137')]
    public function testChildWithoutProductInTheSdeIsCurrentlyRecalculatedAtOneUnitPerRunIssue137(): void
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

    private function sortedProductNamesOf(IndustryProject $project): array
    {
        $productNames = array_values(array_unique(array_column($this->stepsOf($project), 'product')));
        sort($productNames);

        return $productNames;
    }

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

    private function ferniteCarbideOfElevenRunsWithAnAlloyGroupOfTwoTwoAndOneRuns(): IndustryProject
    {
        return $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 11, quantity: 110_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 400, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0, totalGroupRuns: 5),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 2, quantity: 350, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 1, totalGroupRuns: 5),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 1, quantity: 200, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 2, totalGroupRuns: 5),
        ]);
    }

    private function ferniteCarbideOfThirteenRunsWithAnAlloyGroupOfOneJobOfSevenRunsAndAStaleTotalOfFour(): IndustryProject
    {
        return $this->projectOfStepsWithoutStructure([
            $this->step(self::FERNITE_CARBIDE, 'reaction', runs: 13, quantity: 130_000, depth: 0, meLevel: 0),
            $this->step(self::FERNITE_ALLOY, 'reaction', runs: 7, quantity: 1300, depth: 1, meLevel: 0, splitGroupId: 'fernite-alloy', splitIndex: 0, totalGroupRuns: 4),
        ]);
    }

    private function stepCalculatorExpectingOneFlush(): IndustryStepCalculator
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        return new IndustryStepCalculator($this->calculationService, $this->materialRepositoryOnGoldenSde(), $this->productRepositoryOnGoldenSde(), $entityManager);
    }

    private function step(int $productTypeId, string $activityType, int $runs, int $quantity, int $depth, int $meLevel, ?string $splitGroupId = null, int $splitIndex = 0, ?int $totalGroupRuns = null, ?int $blueprintTypeId = null): IndustryProjectStep
    {
        return (new IndustryProjectStep())
            ->setProductTypeId($productTypeId)
            ->setBlueprintTypeId($blueprintTypeId ?? $productTypeId + self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET)
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

    private function materialsOfTheJobsConsuming(array $steps, int $materialTypeId): int
    {
        $materials = 0;
        foreach ($steps as $consumer) {
            $baseQuantityPerRun = $this->recipeOfProduct($consumer['productTypeId'])['materials'][(string) $materialTypeId] ?? null;
            if ($baseQuantityPerRun !== null) {
                $materials += $this->calculationService->calculateMaterialQuantity($baseQuantityPerRun, $consumer['runs'], $consumer['meLevel']);
            }
        }

        return $materials;
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

    private function sortedProductAndSplitIndexOf(array $steps): array
    {
        $productAndSplitIndex = $this->productAndSplitIndexOf($steps);
        sort($productAndSplitIndex);

        return $productAndSplitIndex;
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

        return new TataraAndRaitaruBonusService($pilotOwnsTataraAndRaitaru, $isReactionProduct, $this->createStub(IndustryRigCategoryRepository::class), $this->createStub(IndustryStructureConfigRepository::class), $this->createStub(InvTypeRepository::class), $this->createStub(IndustryActivityProductRepository::class));
    }
}
