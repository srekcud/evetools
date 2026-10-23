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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectFactory::class)]
#[CoversClass(IndustryStepCalculator::class)]
#[Group('issue-234')]
final class IndustryProjectFactoryBpcMaxRunsTest extends TestCase
{
    use BuildsProjectOnGoldenSde;

    private const string SNAPSHOT_DIR = __DIR__.'/../../../Fixtures/Characterization/IndustryProjectFactory/';

    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int HELIUM_FUEL_BLOCK = 4247;
    private const int OXYGEN_FUEL_BLOCK = 4312;
    private const int NOMAD = 28846;
    private const int CAPITAL_ELECTROLYTIC_CAPACITOR_UNIT = 29045;
    private const int CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE = 29049;
    private const int CAPITAL_NUCLEAR_REACTOR_UNIT = 29079;
    private const int FERNITE_CARBIDE = 16673;
    private const int SCORPION = 640;
    private const int SCORPION_BLUEPRINT = 1_000_640;
    private const int AUTO_INTEGRITY_PRESERVATION_SEAL = 57478;
    private const int LIFE_SUPPORT_BACKUP_UNIT = 57486;
    private const int CORE_TEMPERATURE_REGULATOR = 57479;
    private const int WIDOW = 22436;
    private const int SUSTAINED_SHIELD_EMITTER = 11558;
    private const int MAGPULSE_THRUSTER = 11533;
    private const int MARSHAL = 44996;
    private const int FENRIR = 20189;

    private const int ME_LEVEL = 10;
    private const int TE_LEVEL = 20;
    private const float DEFAULT_MAX_JOB_DURATION_DAYS = 2.0;
    private const float MAX_JOB_DURATION_DAYS_THAT_SPLITS_NO_FUEL_BLOCK_OF_ONE_NOMAD = 5.0;
    private const float MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING = 10_000.0;

    private const array HYDROGEN_FUEL_BLOCK_JOBS_OF_TWO_NOMADS = [
        [161, 6440, 5, 10, 20, 0, 642],
        [161, 6440, 5, 10, 20, 1, 642],
        [160, 6400, 5, 10, 20, 2, 642],
        [160, 6400, 5, 10, 20, 3, 642],
    ];

    private const array HYDROGEN_FUEL_BLOCK_JOBS_OF_ONE_NOMAD = [
        [161, 6440, 5, 10, 20, 0, 322],
        [161, 6415, 5, 10, 20, 1, 322],
    ];

    private User $pilot;
    private IndustryBonusService $bonusService;
    private IndustryCalculationService $calculationService;
    private IndustryProjectFactory $factory;
    private IndustryStepCalculator $stepCalculator;

    protected function setUp(): void
    {
        $this->pilot = new User();
        $this->goldenBlueprintsHaveTheirCopyActivity = true;

        $this->bonusService = new TataraAndRaitaruBonusService(
            static fn (): bool => false,
            static fn (int $typeId): bool => false,
            $this->createStub(IndustryRigCategoryRepository::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(InvTypeRepository::class),
            $this->createStub(IndustryActivityProductRepository::class),
        );
        $this->calculationService = $this->calculationServiceOnGoldenSde($this->bonusService, $this->createStub(IndustryStructureConfigRepository::class));
        $this->factory = $this->projectFactoryOnGoldenSde($this->bonusService, $this->calculationService);
        $this->stepCalculator = new IndustryStepCalculator($this->calculationService, $this->materialRepositoryOnGoldenSde(), $this->productRepositoryOnGoldenSde(), $this->entityManagerWithoutRows());
    }

    public function testHydrogenFuelBlockOfTwoNomadsIsSplitIntoFourJobsUnderTheBpcMaxRuns(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame(self::HYDROGEN_FUEL_BLOCK_JOBS_OF_TWO_NOMADS, $this->jobsOf($project, self::HYDROGEN_FUEL_BLOCK));
    }

    public function testNitrogenAndHeliumFuelBlocksOfTwoNomadsAreSplitIntoTwoJobsUnderTheBpcMaxRuns(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame(
            [
                self::NITROGEN_FUEL_BLOCK => [[106, 4240, 5, 10, 20, 0, 211], [105, 4180, 5, 10, 20, 1, 211]],
                self::HELIUM_FUEL_BLOCK => [[106, 4240, 5, 10, 20, 0, 212], [106, 4215, 5, 10, 20, 1, 212]],
            ],
            [
                self::NITROGEN_FUEL_BLOCK => $this->jobsOf($project, self::NITROGEN_FUEL_BLOCK),
                self::HELIUM_FUEL_BLOCK => $this->jobsOf($project, self::HELIUM_FUEL_BLOCK),
            ],
        );
    }

    public function testOtherProductsOfTwoNomadsKeepTheJobsPlannedWithoutBpcMaxRuns(): void
    {
        $this->goldenBlueprintsHaveTheirCopyActivity = false;
        $withoutBpcMaxRuns = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);
        $this->goldenBlueprintsHaveTheirCopyActivity = true;

        $project = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $fuelBlocksSplitByTheBpcMaxRuns = [self::HYDROGEN_FUEL_BLOCK, self::NITROGEN_FUEL_BLOCK, self::HELIUM_FUEL_BLOCK];
        self::assertSame([[86, 3435, 5, 10, 20, 0, null]], $this->jobsOf($project, self::OXYGEN_FUEL_BLOCK));
        self::assertSame(
            $this->jobsByProductOf($withoutBpcMaxRuns, $fuelBlocksSplitByTheBpcMaxRuns),
            $this->jobsByProductOf($project, $fuelBlocksSplitByTheBpcMaxRuns),
        );
        self::assertSame(704, $this->manufacturingAndReactionStepCountOf($project));
    }

    public function testStepsOfOneNomadAlreadySplitUnderTheBpcMaxRunsByTheMaximumDurationAreUnchanged(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame(self::HYDROGEN_FUEL_BLOCK_JOBS_OF_ONE_NOMAD, $this->jobsOf($project, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame(373, $this->manufacturingAndReactionStepCountOf($project));
        self::assertSame(
            json_decode((string) file_get_contents(self::SNAPSHOT_DIR.'nomad-1run-me10-no-structure-per-product.json'), true, flags: \JSON_THROW_ON_ERROR),
            $this->summaryPerProductOf($project),
        );
    }

    public function testFuelBlockOfOneNomadThatTheMaximumDurationDoesNotSplitIsSplitByTheBpcMaxRuns(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NO_FUEL_BLOCK_OF_ONE_NOMAD);

        self::assertSame(self::HYDROGEN_FUEL_BLOCK_JOBS_OF_ONE_NOMAD, $this->jobsOf($project, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame(173, $this->manufacturingAndReactionStepCountOf($project));
    }

    public function testStepThatTheMaximumDurationSplitsIntoMoreJobsThanTheBpcMaxRunsKeepsTheseJobs(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NO_FUEL_BLOCK_OF_ONE_NOMAD);

        self::assertSame(
            [[476, 476, 1, 10, 20, 0, 951], [475, 475, 1, 10, 20, 1, 951]],
            $this->jobsOf($project, self::CAPITAL_ELECTROLYTIC_CAPACITOR_UNIT),
        );
    }

    public function testCapitalComponentsOfOneNomadWithoutMaximumDurationAreSplitByTheBpcMaxRuns(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame(
            [[476, 476, 1, 10, 20, 0, 951], [475, 475, 1, 10, 20, 1, 951]],
            $this->jobsOf($project, self::CAPITAL_ELECTROLYTIC_CAPACITOR_UNIT),
        );
        self::assertSame(
            [
                self::CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE => [[684, 0, 1368], [684, 1, 1368]],
                self::CAPITAL_NUCLEAR_REACTOR_UNIT => [[350, 0, 700], [350, 1, 700]],
            ],
            [
                self::CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE => $this->runsAndSplitIndexAndTotalGroupRunsOf($project, self::CAPITAL_FERNITE_CARBIDE_COMPOSITE_ARMOR_PLATE),
                self::CAPITAL_NUCLEAR_REACTOR_UNIT => $this->runsAndSplitIndexAndTotalGroupRunsOf($project, self::CAPITAL_NUCLEAR_REACTOR_UNIT),
            ],
        );
    }

    public function testSplitByTheBpcMaxRunsRaisesTheNeedOfAnUnsplitReactionThroughTheMaterialsOfEachJob(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame(self::HYDROGEN_FUEL_BLOCK_JOBS_OF_ONE_NOMAD, $this->jobsOf($project, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame([[972, 9719983, 2, 0, 0, 0, null]], $this->jobsOf($project, self::FERNITE_CARBIDE));
        self::assertSame(71, $this->manufacturingAndReactionStepCountOf($project));
    }

    public function testReactionAboveItsMaxProductionLimitStaysOneJobOnTheNeedRaisedByItsSplitConsumers(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame([[1944, 19439969, 2, 0, 0, 0, null]], $this->jobsOf($project, self::FERNITE_CARBIDE));
    }

    public function testRootStepOfTwentyFiveScorpionsIsSplitByTheBpcMaxRunsAndKeepsTheRunsOfTheProject(): void
    {
        $project = $this->createProject(self::SCORPION, runs: 25, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame(25, $project->getRuns());
        self::assertSame(
            [[9, 9, 0, 10, 20, 0, 25], [8, 8, 0, 10, 20, 1, 25], [8, 8, 0, 10, 20, 2, 25]],
            $this->jobsOf($project, self::SCORPION),
        );
        self::assertSame(
            [
                [169, 507, 1, 10, 20, 0, 841],
                [168, 504, 1, 10, 20, 1, 841],
                [168, 504, 1, 10, 20, 2, 841],
                [168, 504, 1, 10, 20, 3, 841],
                [168, 503, 1, 10, 20, 4, 841],
            ],
            $this->jobsOf($project, self::AUTO_INTEGRITY_PRESERVATION_SEAL),
        );
        self::assertSame(
            [[141, 423, 1, 10, 20, 0, 421], [140, 420, 1, 10, 20, 1, 421], [140, 419, 1, 10, 20, 2, 421]],
            $this->jobsOf($project, self::LIFE_SUPPORT_BACKUP_UNIT),
        );
        self::assertSame([[25, 25, 1, 10, 20, 0, null]], $this->jobsOf($project, self::CORE_TEMPERATURE_REGULATOR));
    }

    public function testRootStepOfThreeWidowsWithABpcMaxRunsOfOneIsSplitIntoOneJobPerRun(): void
    {
        $project = $this->createProject(self::WIDOW, runs: 3, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame(3, $project->getRuns());
        self::assertSame(
            [[1, 1, 0, 10, 20, 0, 3], [1, 1, 0, 10, 20, 1, 3], [1, 1, 0, 10, 20, 2, 3]],
            $this->jobsOf($project, self::WIDOW),
        );
        self::assertSame(
            [[2207, 2207, 1, 10, 20, 0, 4413], [2206, 2206, 1, 10, 20, 1, 4413]],
            $this->jobsOf($project, self::SUSTAINED_SHIELD_EMITTER),
        );
        self::assertSame([[609, 609, 1, 10, 20, 0, null]], $this->jobsOf($project, self::MAGPULSE_THRUSTER));
    }

    public function testRegeneratedStepsOfTwoNomadsAreTheStepsOfTheCreationSplitByTheBpcMaxRuns(): void
    {
        $regenerated = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $this->factory->regenerateSteps($regenerated);

        $created = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);
        self::assertSame(self::HYDROGEN_FUEL_BLOCK_JOBS_OF_TWO_NOMADS, $this->jobsOf($regenerated, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame($this->stepsOf($created), $this->stepsOf($regenerated));
    }

    public function testRecalculatingTheStepsOfTwoJustCreatedNomadsChangesNoStep(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);
        $createdSteps = $this->stepsOf($project);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame(self::HYDROGEN_FUEL_BLOCK_JOBS_OF_TWO_NOMADS, $this->jobsOf($project, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame([], array_map(static fn (IndustryProjectStep $step): array => [$step->getProductTypeId(), $step->getSplitIndex()], $changedSteps));
        self::assertSame($createdSteps, $this->stepsOf($project));
    }

    public function testRegenerationKeepsTheJobOfFixedRunsAboveTheBpcMaxRuns(): void
    {
        $this->goldenBlueprintsHaveTheirCopyActivity = false;
        $project = $this->createProject(self::NOMAD, runs: 1, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);
        $this->goldenBlueprintsHaveTheirCopyActivity = true;
        $hydrogenFuelBlockSteps = $this->stepsOfProduct($project, self::HYDROGEN_FUEL_BLOCK);
        self::assertCount(1, $hydrogenFuelBlockSteps);
        $hydrogenFuelBlockSteps[0]->setRuns(322)->setRunsFixed(true);

        $this->factory->regenerateSteps($project);

        self::assertSame([[322, 12880, 5, 10, 20, 0, null]], $this->jobsOf($project, self::HYDROGEN_FUEL_BLOCK));
    }

    public function testCreationIsRefusedWhenABlueprintToCopyHasNoIndustryBlueprintInTheSde(): void
    {
        $this->typeIdsWithoutIndustryBlueprint = [self::SCORPION];

        $this->assertCreationOfTwentyFiveScorpionsIsRefusedNamingTheirBlueprint();
    }

    public function testCreationIsRefusedWhenABlueprintToCopyHasAMaxProductionLimitOfZero(): void
    {
        $this->typeIdsWithZeroMaxProductionLimit = [self::SCORPION];

        $this->assertCreationOfTwentyFiveScorpionsIsRefusedNamingTheirBlueprint();
    }

    public function testStepOfAsManyRunsAsTheBpcMaxRunsStaysOneJob(): void
    {
        $project = $this->createProject(self::SCORPION, runs: 10, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame([[10, 10, 0, 10, 20, 0, null]], $this->jobsOf($project, self::SCORPION));
    }

    public function testStepOfOneRunAboveTheBpcMaxRunsIsSplitIntoTwoJobs(): void
    {
        $project = $this->createProject(self::SCORPION, runs: 11, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame([[6, 6, 0, 10, 20, 0, 11], [5, 5, 0, 10, 20, 1, 11]], $this->jobsOf($project, self::SCORPION));
    }

    public function testBpcMaxRunsOfOneSplitsTwentyFiveFenrirsIntoTwentyFiveJobsBeyondTheLimitOfAManualSplit(): void
    {
        $project = $this->createProject(self::FENRIR, runs: 25, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame(
            array_map(static fn (int $splitIndex): array => [1, 1, 0, 10, 20, $splitIndex, 25], range(0, 24)),
            $this->jobsOf($project, self::FENRIR),
        );
    }

    public function testManufacturingBlueprintWithoutCopyActivityIsNotSplitByItsMaxProductionLimit(): void
    {
        $project = $this->createProject(self::MARSHAL, runs: 3, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame([[3, 3, 0, 10, 20, 0, null]], $this->jobsOf($project, self::MARSHAL));
        self::assertSame([], $this->copyStepsOfProduct($project, self::MARSHAL));
    }

    public function testStepWithoutBaseTimeIsSplitByTheBpcMaxRunsOnly(): void
    {
        $this->typeIdsWithoutBaseTime = [self::HYDROGEN_FUEL_BLOCK];

        $project = $this->createProject(self::NOMAD, runs: 1, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame(self::HYDROGEN_FUEL_BLOCK_JOBS_OF_ONE_NOMAD, $this->jobsOf($project, self::HYDROGEN_FUEL_BLOCK));
    }

    public function testCopyStepsAreNotSplitByTheBpcMaxRuns(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $copySteps = array_values(array_filter($project->getSteps()->toArray(), static fn (IndustryProjectStep $step): bool => $step->getActivityType() === 'copy'));
        self::assertSame(
            [],
            array_values(array_filter($copySteps, static fn (IndustryProjectStep $step): bool => $step->getSplitGroupId() !== null)),
        );
        self::assertCount(1, $this->copyStepsOfProduct($project, self::HYDROGEN_FUEL_BLOCK));
    }

    private function assertCreationOfTwentyFiveScorpionsIsRefusedNamingTheirBlueprint(): void
    {
        $persistedEntities = [];
        $projectEntityManager = $this->createStub(EntityManagerInterface::class);
        $projectEntityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persistedEntities): void {
            $persistedEntities[] = $entity;
        });
        $factory = $this->projectFactoryOnGoldenSde($this->bonusService, $this->calculationService, $projectEntityManager);

        $refusal = null;
        try {
            $factory->createProject($this->pilot, self::SCORPION, 25, self::ME_LEVEL, self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING, self::TE_LEVEL);
        } catch (\Exception $exception) {
            $refusal = $exception;
        }

        self::assertStringContainsString((string) self::SCORPION_BLUEPRINT, $refusal?->getMessage() ?? 'project created');
        self::assertSame([], $persistedEntities);
    }

    private function createProject(int $productTypeId, int $runs, float $maxJobDurationDays): IndustryProject
    {
        return $this->factory->createProject($this->pilot, $productTypeId, $runs, self::ME_LEVEL, $maxJobDurationDays, self::TE_LEVEL);
    }

    private function stepsOfProduct(IndustryProject $project, int $productTypeId): array
    {
        $steps = array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === $productTypeId && $step->getActivityType() !== 'copy',
        ));
        usort($steps, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());

        return $steps;
    }

    private function copyStepsOfProduct(IndustryProject $project, int $productTypeId): array
    {
        return array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === $productTypeId && $step->getActivityType() === 'copy',
        ));
    }

    private function jobsOf(IndustryProject $project, int $productTypeId): array
    {
        return array_map(
            static fn (IndustryProjectStep $step): array => [$step->getRuns(), $step->getQuantity(), $step->getDepth(), $step->getMeLevel(), $step->getTeLevel(), $step->getSplitIndex(), $step->getTotalGroupRuns()],
            $this->stepsOfProduct($project, $productTypeId),
        );
    }

    private function runsAndSplitIndexAndTotalGroupRunsOf(IndustryProject $project, int $productTypeId): array
    {
        return array_map(
            static fn (IndustryProjectStep $step): array => [$step->getRuns(), $step->getSplitIndex(), $step->getTotalGroupRuns()],
            $this->stepsOfProduct($project, $productTypeId),
        );
    }

    private function jobsByProductOf(IndustryProject $project, array $excludedProductTypeIds): array
    {
        $jobsByProduct = [];
        foreach ($project->getSteps() as $step) {
            $productTypeId = $step->getProductTypeId();
            if ($step->getActivityType() !== 'copy' && !\in_array($productTypeId, $excludedProductTypeIds, true)) {
                $jobsByProduct[$productTypeId] ??= $this->jobsOf($project, $productTypeId);
            }
        }
        ksort($jobsByProduct);

        return $jobsByProduct;
    }

    private function manufacturingAndReactionStepCountOf(IndustryProject $project): int
    {
        return \count(array_filter($project->getSteps()->toArray(), static fn (IndustryProjectStep $step): bool => $step->getActivityType() !== 'copy'));
    }

    private function stepsOf(IndustryProject $project): array
    {
        $splitGroupLabels = [];
        $steps = [];
        foreach ($project->getSteps() as $step) {
            $splitGroupId = $step->getSplitGroupId();
            if ($splitGroupId !== null && !isset($splitGroupLabels[$splitGroupId])) {
                $splitGroupLabels[$splitGroupId] = 'split-'.\count($splitGroupLabels);
            }
            $steps[] = [
                $step->getSortOrder(),
                $step->getProductTypeId(),
                $step->getBlueprintTypeId(),
                $step->getActivityType(),
                $step->getDepth(),
                $step->getRuns(),
                $step->getQuantity(),
                $step->getMeLevel(),
                $step->getTeLevel(),
                $splitGroupId === null ? null : $splitGroupLabels[$splitGroupId],
                $step->getSplitIndex(),
                $step->getTotalGroupRuns(),
            ];
        }

        return $steps;
    }

    private function summaryPerProductOf(IndustryProject $project): array
    {
        $typeNames = self::goldenTypeNames();
        $summary = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getActivityType() === 'copy') {
                continue;
            }
            $product = $typeNames[$step->getProductTypeId()];
            $summary[$product] ??= ['runs' => 0, 'jobs' => 0, 'totalGroupRuns' => [], 'meLevel' => $step->getMeLevel(), 'teLevel' => $step->getTeLevel()];
            $summary[$product]['runs'] += $step->getRuns();
            ++$summary[$product]['jobs'];
            if (!\in_array($step->getTotalGroupRuns(), $summary[$product]['totalGroupRuns'], true)) {
                $summary[$product]['totalGroupRuns'][] = $step->getTotalGroupRuns();
            }
        }
        ksort($summary);

        return $summary;
    }
}
