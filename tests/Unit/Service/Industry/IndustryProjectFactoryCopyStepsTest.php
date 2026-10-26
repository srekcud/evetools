<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\EsiCostIndexService;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\IndustryStepCalculator;
use App\Service\Industry\InventionService;
use App\Service\Industry\ProjectStepStructureResolver;
use App\Service\JitaMarketService;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectFactory::class)]
#[CoversClass(IndustryStepCalculator::class)]
#[CoversClass(InventionService::class)]
#[Group('issue-235')]
final class IndustryProjectFactoryCopyStepsTest extends TestCase
{
    use BuildsProjectOnGoldenSde;

    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int HELIUM_FUEL_BLOCK = 4247;
    private const int OXYGEN_FUEL_BLOCK = 4312;
    private const int NOMAD = 28846;
    private const int CAPITAL_ELECTROLYTIC_CAPACITOR_UNIT = 29045;
    private const int SCORPION = 640;
    private const int AUTO_INTEGRITY_PRESERVATION_SEAL = 57478;
    private const int LIFE_SUPPORT_BACKUP_UNIT = 57486;
    private const int WIDOW = 22436;
    private const int MARSHAL = 44996;

    private const int HYDROGEN_FUEL_BLOCK_BLUEPRINT = 1_004_246;
    private const int NITROGEN_FUEL_BLOCK_BLUEPRINT = 1_004_051;
    private const int HELIUM_FUEL_BLOCK_BLUEPRINT = 1_004_247;
    private const int OXYGEN_FUEL_BLOCK_BLUEPRINT = 1_004_312;
    private const int CAPITAL_ELECTROLYTIC_CAPACITOR_UNIT_BLUEPRINT = 1_029_045;
    private const int SCORPION_BLUEPRINT = 1_000_640;
    private const int AUTO_INTEGRITY_PRESERVATION_SEAL_BLUEPRINT = 1_057_478;
    private const int LIFE_SUPPORT_BACKUP_UNIT_BLUEPRINT = 1_057_486;
    private const int WIDOW_BLUEPRINT = 1_022_436;
    private const int MARSHAL_BLUEPRINT = 1_044_996;

    private const int ME_LEVEL = 10;
    private const int TE_LEVEL = 20;
    private const float DEFAULT_MAX_JOB_DURATION_DAYS = 2.0;
    private const float MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING = 10_000.0;

    private const array HYDROGEN_FUEL_BLOCK_COPY_RUNS_OF_TWO_NOMADS = [161, 161, 160, 160];

    private const array COPY_RUNS_OF_THE_OTHER_BLUEPRINTS_OF_TWO_NOMADS = [
        1_011_478 => [2],
        1_020_189 => [1, 1],
        1_021_009 => [36],
        1_021_017 => [10],
        1_021_025 => [27, 27],
        1_021_027 => [32, 32],
        1_021_037 => [10],
        1_028_846 => [1, 1],
        1_029_043 => [607, 607],
        1_029_045 => [634, 634, 634],
        1_029_049 => [684, 684, 684, 684],
        1_029_065 => [599, 599],
        1_029_075 => [634, 634, 634],
        1_029_079 => [350, 350, 350, 350],
        1_029_089 => [570, 570],
        1_057_473 => [30],
        1_057_477 => [2],
        1_057_478 => [120, 120],
        1_057_479 => [32, 31],
        1_057_486 => [120],
        1_057_487 => [2],
    ];

    private const float HYDROGEN_FUEL_BLOCK_ADJUSTED_PRICE = 1_250.0;
    private const float COPYING_COST_INDEX = 0.0625;
    private const float COST_DELTA = 0.000_001;

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

    public function testHydrogenFuelBlockCopyOfTwoNomadsIsSplitLikeItsFourManufacturingJobs(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame(self::HYDROGEN_FUEL_BLOCK_COPY_RUNS_OF_TWO_NOMADS, $this->copyRunsOf($project, self::HYDROGEN_FUEL_BLOCK_BLUEPRINT));
        self::assertSame($this->manufacturingRunsOf($project, self::HYDROGEN_FUEL_BLOCK), $this->copyRunsOf($project, self::HYDROGEN_FUEL_BLOCK_BLUEPRINT));
    }

    public function testNitrogenAndHeliumFuelBlockCopiesOfTwoNomadsFollowTheirTwoManufacturingJobs(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame(
            [
                self::NITROGEN_FUEL_BLOCK_BLUEPRINT => [106, 105],
                self::HELIUM_FUEL_BLOCK_BLUEPRINT => [106, 106],
            ],
            [
                self::NITROGEN_FUEL_BLOCK_BLUEPRINT => $this->copyRunsOf($project, self::NITROGEN_FUEL_BLOCK_BLUEPRINT),
                self::HELIUM_FUEL_BLOCK_BLUEPRINT => $this->copyRunsOf($project, self::HELIUM_FUEL_BLOCK_BLUEPRINT),
            ],
        );
    }

    public function testOxygenFuelBlockCopyOfTwoNomadsHasTheRunsOfItsManufacturingJob(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame([86], $this->copyRunsOf($project, self::OXYGEN_FUEL_BLOCK_BLUEPRINT));
    }

    public function testFuelBlockCopiesOfOneNomadHaveTheRunsOfTheirManufacturingJobs(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame(
            [
                self::HYDROGEN_FUEL_BLOCK_BLUEPRINT => [161, 161],
                self::OXYGEN_FUEL_BLOCK_BLUEPRINT => [44],
                self::NITROGEN_FUEL_BLOCK_BLUEPRINT => [106],
                self::HELIUM_FUEL_BLOCK_BLUEPRINT => [107],
            ],
            [
                self::HYDROGEN_FUEL_BLOCK_BLUEPRINT => $this->copyRunsOf($project, self::HYDROGEN_FUEL_BLOCK_BLUEPRINT),
                self::OXYGEN_FUEL_BLOCK_BLUEPRINT => $this->copyRunsOf($project, self::OXYGEN_FUEL_BLOCK_BLUEPRINT),
                self::NITROGEN_FUEL_BLOCK_BLUEPRINT => $this->copyRunsOf($project, self::NITROGEN_FUEL_BLOCK_BLUEPRINT),
                self::HELIUM_FUEL_BLOCK_BLUEPRINT => $this->copyRunsOf($project, self::HELIUM_FUEL_BLOCK_BLUEPRINT),
            ],
        );
    }

    public function testCapacitorUnitCopyOfOneNomadWithoutMaximumDurationIsSplitUnderTheBpcMaxRuns(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame([476, 475], $this->copyRunsOf($project, self::CAPITAL_ELECTROLYTIC_CAPACITOR_UNIT_BLUEPRINT));
    }

    public function testCopiesOfTwentyFiveScorpionsAreSplitUnderTheBpcMaxRuns(): void
    {
        $project = $this->createProject(self::SCORPION, runs: 25, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame(
            [
                self::SCORPION_BLUEPRINT => [9, 8, 8],
                self::AUTO_INTEGRITY_PRESERVATION_SEAL_BLUEPRINT => [169, 168, 168, 168, 168],
                self::LIFE_SUPPORT_BACKUP_UNIT_BLUEPRINT => [141, 140, 140],
            ],
            [
                self::SCORPION_BLUEPRINT => $this->copyRunsOf($project, self::SCORPION_BLUEPRINT),
                self::AUTO_INTEGRITY_PRESERVATION_SEAL_BLUEPRINT => $this->copyRunsOf($project, self::AUTO_INTEGRITY_PRESERVATION_SEAL_BLUEPRINT),
                self::LIFE_SUPPORT_BACKUP_UNIT_BLUEPRINT => $this->copyRunsOf($project, self::LIFE_SUPPORT_BACKUP_UNIT_BLUEPRINT),
            ],
        );
        self::assertSame(
            [
                self::AUTO_INTEGRITY_PRESERVATION_SEAL => [169, 168, 168, 168, 168],
                self::LIFE_SUPPORT_BACKUP_UNIT => [141, 140, 140],
            ],
            [
                self::AUTO_INTEGRITY_PRESERVATION_SEAL => $this->manufacturingRunsOf($project, self::AUTO_INTEGRITY_PRESERVATION_SEAL),
                self::LIFE_SUPPORT_BACKUP_UNIT => $this->manufacturingRunsOf($project, self::LIFE_SUPPORT_BACKUP_UNIT),
            ],
        );
    }

    public function testCopyOfThreeWidowsWithABpcMaxRunsOfOneIsOneCopyPerRun(): void
    {
        $project = $this->createProject(self::WIDOW, runs: 3, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame([1, 1, 1], $this->copyRunsOf($project, self::WIDOW_BLUEPRINT));
    }

    public function testEveryOtherCopyOfTwoNomadsHasTheRunsOfTheManufacturingJobsOfItsBlueprint(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame(
            self::COPY_RUNS_OF_THE_OTHER_BLUEPRINTS_OF_TWO_NOMADS,
            $this->copyRunsByBlueprintOf($project, [self::HYDROGEN_FUEL_BLOCK_BLUEPRINT, self::NITROGEN_FUEL_BLOCK_BLUEPRINT, self::HELIUM_FUEL_BLOCK_BLUEPRINT, self::OXYGEN_FUEL_BLOCK_BLUEPRINT]),
        );
    }

    public function testMaximumDurationThatSplitsTheManufacturingDoesNotSplitTheCopy(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame([238, 238, 238, 237], $this->manufacturingRunsOf($project, self::CAPITAL_ELECTROLYTIC_CAPACITOR_UNIT));
        self::assertSame([476, 475], $this->copyRunsOf($project, self::CAPITAL_ELECTROLYTIC_CAPACITOR_UNIT_BLUEPRINT));
    }

    public function testCopyOfAsManyRunsAsTheBpcMaxRunsStaysOneCopy(): void
    {
        $project = $this->createProject(self::SCORPION, runs: 10, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame([[10, 10, 0, 0, 0, 0, null]], $this->copyJobsOf($project, self::SCORPION_BLUEPRINT));
    }

    public function testCopyOfOneRunAboveTheBpcMaxRunsIsSplitIntoTwoCopies(): void
    {
        $project = $this->createProject(self::SCORPION, runs: 11, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame([6, 5], $this->copyRunsOf($project, self::SCORPION_BLUEPRINT));
    }

    public function testCopiesFollowTheFixedRunsOfAManufacturingJobAboveTheBpcMaxRuns(): void
    {
        $this->goldenBlueprintsHaveTheirCopyActivity = false;
        $project = $this->createProject(self::NOMAD, runs: 1, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);
        $this->goldenBlueprintsHaveTheirCopyActivity = true;
        $hydrogenFuelBlockSteps = $this->manufacturingStepsOf($project, self::HYDROGEN_FUEL_BLOCK);
        self::assertCount(1, $hydrogenFuelBlockSteps);
        $hydrogenFuelBlockSteps[0]->setRuns(322)->setRunsFixed(true);

        $this->factory->regenerateSteps($project);

        self::assertSame([322], $this->manufacturingRunsOf($project, self::HYDROGEN_FUEL_BLOCK));
        self::assertSame([161, 161], $this->copyRunsOf($project, self::HYDROGEN_FUEL_BLOCK_BLUEPRINT));
    }

    public function testManufacturingBlueprintWithoutCopyActivityHasNoCopyStep(): void
    {
        $project = $this->createProject(self::MARSHAL, runs: 3, maxJobDurationDays: self::MAX_JOB_DURATION_DAYS_THAT_SPLITS_NOTHING);

        self::assertSame([3], $this->manufacturingRunsOf($project, self::MARSHAL));
        self::assertSame([], $this->copyStepsOf($project, self::MARSHAL_BLUEPRINT));
    }

    public function testCopiesOfABlueprintFormASplitGroupOfTheirOwn(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $copySteps = $this->copyStepsOf($project, self::HYDROGEN_FUEL_BLOCK_BLUEPRINT);
        self::assertSame(
            [[161, 161, 5, 0, 0, 0, 642], [161, 161, 5, 0, 0, 1, 642], [160, 160, 5, 0, 0, 2, 642], [160, 160, 5, 0, 0, 3, 642]],
            $this->copyJobsOf($project, self::HYDROGEN_FUEL_BLOCK_BLUEPRINT),
        );
        $splitGroupIds = array_values(array_unique(array_map(static fn (IndustryProjectStep $step): ?string => $step->getSplitGroupId(), $copySteps)));
        self::assertCount(1, $splitGroupIds);
        self::assertNotNull($splitGroupIds[0]);
        self::assertSame(
            array_fill(0, 4, ['copy', self::HYDROGEN_FUEL_BLOCK_BLUEPRINT, self::HYDROGEN_FUEL_BLOCK]),
            array_values(array_map(
                static fn (IndustryProjectStep $step): array => [$step->getActivityType(), $step->getBlueprintTypeId(), $step->getProductTypeId()],
                array_filter($project->getSteps()->toArray(), static fn (IndustryProjectStep $step): bool => $step->getSplitGroupId() === $splitGroupIds[0]),
            )),
        );
        self::assertSame([null, null, null, null], array_map(static fn (IndustryProjectStep $step): mixed => $step->getStructureConfig(), $copySteps));
    }

    public function testSingleCopyStaysAnIntactStep(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        self::assertSame([[86, 86, 5, 0, 0, 0, null]], $this->copyJobsOf($project, self::OXYGEN_FUEL_BLOCK_BLUEPRINT));
        self::assertSame([null], array_map(static fn (IndustryProjectStep $step): ?string => $step->getSplitGroupId(), $this->copyStepsOf($project, self::OXYGEN_FUEL_BLOCK_BLUEPRINT)));
    }

    public function testRegeneratedCopiesOfTwoNomadsAreTheCopiesOfTheCreation(): void
    {
        $regenerated = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $this->factory->regenerateSteps($regenerated);

        $created = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);
        self::assertSame(self::HYDROGEN_FUEL_BLOCK_COPY_RUNS_OF_TWO_NOMADS, $this->copyRunsOf($regenerated, self::HYDROGEN_FUEL_BLOCK_BLUEPRINT));
        self::assertSame($this->allCopyStepsOf($created), $this->allCopyStepsOf($regenerated));
    }

    public function testRecalculatingTheStepsOfTwoJustCreatedNomadsChangesNoCopy(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 2, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);
        $createdCopySteps = $this->allCopyStepsOf($project);

        $changedSteps = $this->stepCalculator->recalculateStepQuantities($project);

        self::assertSame(self::HYDROGEN_FUEL_BLOCK_COPY_RUNS_OF_TWO_NOMADS, $this->copyRunsOf($project, self::HYDROGEN_FUEL_BLOCK_BLUEPRINT));
        self::assertSame([], array_values(array_filter($changedSteps, static fn (IndustryProjectStep $step): bool => $step->getActivityType() === 'copy')));
        self::assertSame($createdCopySteps, $this->allCopyStepsOf($project));
    }

    public function testCreationIsRefusedWhenABlueprintToCopyHasNoIndustryBlueprintInTheSde(): void
    {
        $this->typeIdsWithoutIndustryBlueprint = [self::HYDROGEN_FUEL_BLOCK];
        $persistedEntities = [];
        $projectEntityManager = $this->createStub(EntityManagerInterface::class);
        $projectEntityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persistedEntities): void {
            $persistedEntities[] = $entity;
        });
        $factory = $this->projectFactoryOnGoldenSde($this->bonusService, $this->calculationService, $projectEntityManager);

        $refusal = null;
        try {
            $factory->createProject($this->pilot, self::NOMAD, 2, self::ME_LEVEL, self::DEFAULT_MAX_JOB_DURATION_DAYS, self::TE_LEVEL);
        } catch (\Exception $exception) {
            $refusal = $exception;
        }

        self::assertStringContainsString((string) self::HYDROGEN_FUEL_BLOCK_BLUEPRINT, $refusal?->getMessage() ?? 'project created');
        self::assertSame([], $persistedEntities);
    }

    public function testCopyCostOfEachHydrogenFuelBlockCopyOfOneNomadFollowsItsRuns(): void
    {
        $project = $this->createProject(self::NOMAD, runs: 1, maxJobDurationDays: self::DEFAULT_MAX_JOB_DURATION_DAYS);

        $copyCosts = $this->inventionServiceOnGoldenSde()->getProjectCopyCosts($project);

        $hydrogenFuelBlockCopyCosts = array_values(array_map(
            static fn (array $copy): array => [$copy['runs'], $copy['cost']],
            array_filter($copyCosts['copies'], static fn (array $copy): bool => $copy['blueprintTypeId'] === self::HYDROGEN_FUEL_BLOCK_BLUEPRINT),
        ));
        self::assertEqualsWithDelta([[161, 10_087.656_25], [161, 10_087.656_25]], $hydrogenFuelBlockCopyCosts, self::COST_DELTA);
        self::assertEqualsWithDelta(20_175.3125, $copyCosts['totalCopyCost'], self::COST_DELTA);
    }

    private function inventionServiceOnGoldenSde(): InventionService
    {
        $recipes = self::goldenRecipes();
        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('findBy')->willReturnCallback(
            static function (array $criteria) use ($recipes): array {
                $productTypeId = $criteria['typeId'] - self::SYNTHETIC_BLUEPRINT_TYPE_ID_OFFSET;
                $recipe = $recipes[$productTypeId] ?? null;

                return $recipe !== null && ($criteria['activityId'] ?? null) === IndustryActivityType::Manufacturing->value && $recipe['activity'] === 'manufacturing'
                    ? [self::activityProduct($productTypeId, $recipe)]
                    : [];
            },
        );

        $costIndexService = $this->createStub(EsiCostIndexService::class);
        $costIndexService->method('getAdjustedPrice')->willReturnCallback(
            static fn (int $typeId): ?float => $typeId === self::HYDROGEN_FUEL_BLOCK ? self::HYDROGEN_FUEL_BLOCK_ADJUSTED_PRICE : null,
        );
        $costIndexService->method('getCostIndex')->willReturnCallback(
            static fn (int $solarSystemId, string $activity): ?float => $activity === 'copying' ? self::COPYING_COST_INDEX : null,
        );

        $userSettingsRepository = $this->createStub(IndustryUserSettingsRepository::class);
        $userSettingsRepository->method('findOneBy')->willReturn(null);
        $calculationService = $this->createStub(IndustryCalculationService::class);
        $calculationService->method('getStructureBonusForStep')->willReturn([
            'structure' => null,
            'materialBonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0],
            'timeBonus' => 0.0,
            'name' => null,
            'favoriteSystemWithoutSuitableStructure' => false,
        ]);

        $typeRepository = $this->typeRepositoryOnGoldenSde();

        return new InventionService(
            $productRepository,
            $this->createStub(IndustryActivityMaterialRepository::class),
            $typeRepository,
            $this->createStub(JitaMarketService::class),
            $costIndexService,
            new TypeNameResolver($typeRepository),
            new ProjectStepStructureResolver($userSettingsRepository, $calculationService),
        );
    }

    private function createProject(int $productTypeId, int $runs, float $maxJobDurationDays): IndustryProject
    {
        return $this->factory->createProject($this->pilot, $productTypeId, $runs, self::ME_LEVEL, $maxJobDurationDays, self::TE_LEVEL);
    }

    private function manufacturingStepsOf(IndustryProject $project, int $productTypeId): array
    {
        $steps = array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getProductTypeId() === $productTypeId && $step->getActivityType() === 'manufacturing',
        ));
        usort($steps, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());

        return $steps;
    }

    private function manufacturingRunsOf(IndustryProject $project, int $productTypeId): array
    {
        return array_map(static fn (IndustryProjectStep $step): int => $step->getRuns(), $this->manufacturingStepsOf($project, $productTypeId));
    }

    private function copyStepsOf(IndustryProject $project, int $blueprintTypeId): array
    {
        $steps = array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getBlueprintTypeId() === $blueprintTypeId && $step->getActivityType() === 'copy',
        ));
        usort($steps, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());

        return $steps;
    }

    private function copyRunsOf(IndustryProject $project, int $blueprintTypeId): array
    {
        return array_map(static fn (IndustryProjectStep $step): int => $step->getRuns(), $this->copyStepsOf($project, $blueprintTypeId));
    }

    private function copyJobsOf(IndustryProject $project, int $blueprintTypeId): array
    {
        return array_map(
            static fn (IndustryProjectStep $step): array => [$step->getRuns(), $step->getQuantity(), $step->getDepth(), $step->getMeLevel(), $step->getTeLevel(), $step->getSplitIndex(), $step->getTotalGroupRuns()],
            $this->copyStepsOf($project, $blueprintTypeId),
        );
    }

    private function copyRunsByBlueprintOf(IndustryProject $project, array $excludedBlueprintTypeIds): array
    {
        $copyRunsByBlueprint = [];
        foreach ($project->getSteps() as $step) {
            $blueprintTypeId = $step->getBlueprintTypeId();
            if ($step->getActivityType() === 'copy' && !\in_array($blueprintTypeId, $excludedBlueprintTypeIds, true)) {
                $copyRunsByBlueprint[$blueprintTypeId] ??= $this->copyRunsOf($project, $blueprintTypeId);
            }
        }
        ksort($copyRunsByBlueprint);

        return $copyRunsByBlueprint;
    }

    private function allCopyStepsOf(IndustryProject $project): array
    {
        $splitGroupLabels = [];
        $copySteps = [];
        foreach ($project->getSteps() as $step) {
            if ($step->getActivityType() !== 'copy') {
                continue;
            }
            $splitGroupId = $step->getSplitGroupId();
            if ($splitGroupId !== null && !isset($splitGroupLabels[$splitGroupId])) {
                $splitGroupLabels[$splitGroupId] = 'split-'.\count($splitGroupLabels);
            }
            $copySteps[] = [
                $step->getSortOrder(),
                $step->getProductTypeId(),
                $step->getBlueprintTypeId(),
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

        return $copySteps;
    }
}
