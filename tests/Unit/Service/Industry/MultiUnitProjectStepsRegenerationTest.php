<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStepPurchase;
use App\Enum\ProjectCorrectionStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\MultiUnitProjectStepsRegeneration;
use App\Service\Industry\ProjectCorrectionOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(MultiUnitProjectStepsRegeneration::class)]
#[Group('issue-284')]
final class MultiUnitProjectStepsRegenerationTest extends TestCase
{
    use BuildsProjectsOfTheOldRootCalculationOnGoldenSde;

    private const string NITROGEN_FUEL_BLOCK_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-284000004051';
    private const string TWIN_NITROGEN_FUEL_BLOCK_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-284000014051';
    private const string FERNITE_CARBIDE_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-284000016673';
    private const string SCORPION_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-284000000640';
    private const string ROBOTICS_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-284000009848';
    private const string HYDROGEN_FUEL_BLOCK_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-284000004246';
    private const int HYDROGEN_FUEL_BLOCK_RUNS = 30;
    private const int ROBOTICS_RUNS = 6;
    private const int SCORPION_RUNS = 10;

    private const string NITROGEN_FUEL_BLOCK_CHANGE = 'root jobs 1, runs 1, units 25 -> jobs 1, runs 25, units 1000; steps 1 -> 1; job matches 1 -> 1, purchases 1 -> 1';

    protected function setUp(): void
    {
        $this->buildFactoryOnGoldenSde();
    }

    public function testRootStepOfTheOldCalculationOfTwentyFiveRunsOfNitrogenFuelBlockIsRegeneratedAtOneThousandUnitsKeepingItsJobMatchAndPurchase(): void
    {
        $project = $this->nitrogenFuelBlockProjectOfTheOldCalculationWithAJobMatchAndAPurchase();
        $rootStep = $this->rootJobsOf($project)[0];

        $outcomes = $this->regenerationOf($project)->run(false);

        self::assertSame(
            [[self::NITROGEN_FUEL_BLOCK_PROJECT_ID, 'Nitrogen Fuel Block', self::NITROGEN_FUEL_BLOCK_RUNS, ProjectCorrectionStatus::Changed, self::NITROGEN_FUEL_BLOCK_CHANGE, null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([$rootStep], $project->getSteps()->toArray());
        self::assertSame([25, 1000, 10, 20, 1, 1], self::runsQuantityMeTeJobMatchesAndPurchasesOf($rootStep));
        self::assertContains('flush', $this->entityManagerCalls);
        self::assertSame(
            [['/user/'.self::PILOT_ID.'/sync/industry-project', 'started'], ['/user/'.self::PILOT_ID.'/sync/industry-project', 'completed']],
            $this->topicsAndStatusesOfThePublishedUpdates(),
        );
    }

    public function testRegeneratedProjectIsNotSelectedAgain(): void
    {
        $project = $this->nitrogenFuelBlockProjectOfTheOldCalculationWithAJobMatchAndAPurchase();
        $regeneration = $this->regenerationOf($project);
        $regeneration->run(false);

        self::assertSame([], $regeneration->run(false));
    }

    public function testDryRunReportsTheSameChangeAndNeitherWritesNorPublishes(): void
    {
        $project = $this->nitrogenFuelBlockProjectOfTheOldCalculationWithAJobMatchAndAPurchase();
        $rootStep = $this->rootJobsOf($project)[0];

        $outcomes = $this->regenerationOf($project)->run(true);

        self::assertSame(
            [[self::NITROGEN_FUEL_BLOCK_PROJECT_ID, 'Nitrogen Fuel Block', self::NITROGEN_FUEL_BLOCK_RUNS, ProjectCorrectionStatus::Changed, self::NITROGEN_FUEL_BLOCK_CHANGE, null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([1, 25, 10, 20, 1, 1], self::runsQuantityMeTeJobMatchesAndPurchasesOf($rootStep));
        self::assertSame([], $this->writesOfTheEntityManager());
        self::assertSame([], $this->topicsAndStatusesOfThePublishedUpdates());
    }

    public function testRootReactionOfTheOldCalculationOfTenRunsOfFerniteCarbideIsRegeneratedAtOneHundredThousandUnits(): void
    {
        $project = $this->ferniteCarbideProjectOfTheOldCalculation(self::FERNITE_CARBIDE_PROJECT_ID);

        $outcomes = $this->regenerationOf($project)->run(false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', self::FERNITE_CARBIDE_RUNS, ProjectCorrectionStatus::Changed, 'root jobs 1, runs 1, units 10 -> jobs 1, runs 10, units 100000; steps 4 -> 4; job matches 0 -> 0, purchases 0 -> 0', null]],
            self::summaryOf($outcomes),
        );
        self::assertSame(
            [self::FERNITE_CARBIDE => [[10, 100_000]], self::CERAMIC_POWDER => [[5, 974]], self::FERNITE_ALLOY => [[5, 974]], self::HYDROGEN_FUEL_BLOCK => [[3, 100]]],
            [
                self::FERNITE_CARBIDE => self::runsAndQuantityOfEachJob($this->jobsOfProduct($project, self::FERNITE_CARBIDE)),
                self::CERAMIC_POWDER => self::runsAndQuantityOfEachJob($this->jobsOfProduct($project, self::CERAMIC_POWDER)),
                self::FERNITE_ALLOY => self::runsAndQuantityOfEachJob($this->jobsOfProduct($project, self::FERNITE_ALLOY)),
                self::HYDROGEN_FUEL_BLOCK => self::runsAndQuantityOfEachJob($this->jobsOfProduct($project, self::HYDROGEN_FUEL_BLOCK)),
            ],
        );
    }

    public function testProductOfOneUnitPerRunIsNotSelected(): void
    {
        $project = $this->factory->createProject($this->pilot, self::SCORPION, self::SCORPION_RUNS, self::PROJECT_ME_LEVEL, self::MAX_JOB_DURATION_DAYS, self::PROJECT_TE_LEVEL);

        self::assertSame([], $this->regenerationOf($this->identified($project, self::SCORPION_PROJECT_ID))->run(false));
    }

    public function testRootStepAtAnMeOtherThanTheMeOfTheProjectSkipsTheProjectAndWritesNothing(): void
    {
        $project = $this->nitrogenFuelBlockProjectOfTheOldCalculation(self::NITROGEN_FUEL_BLOCK_PROJECT_ID);
        $rootStep = $this->rootJobsOf($project)[0]->setMeLevel(8);

        $outcomes = $this->regenerationOf($project)->run(false);

        self::assertSame(
            [[self::NITROGEN_FUEL_BLOCK_PROJECT_ID, 'Nitrogen Fuel Block', self::NITROGEN_FUEL_BLOCK_RUNS, ProjectCorrectionStatus::Skipped, null, 'ME/TE would change on 1004051 manufacturing job 0: 8/20 -> 10/20']],
            self::summaryOf($outcomes),
        );
        self::assertSame([1, 25, 8, 20, 0, 0], self::runsQuantityMeTeJobMatchesAndPurchasesOf($rootStep));
        self::assertSame([], $this->writesOfTheEntityManager());
        self::assertSame([], $this->topicsAndStatusesOfThePublishedUpdates());
    }

    public function testCeramicPowderReactionAtMeTenAndTeTwentySkipsTheProjectNamingItsReactionFormula(): void
    {
        $project = $this->ferniteCarbideProjectOfTheOldCalculation(self::FERNITE_CARBIDE_PROJECT_ID);
        $this->jobsOfProduct($project, self::CERAMIC_POWDER)[0]->setMeLevel(10)->setTeLevel(20);

        $outcomes = $this->regenerationOf($project)->run(false);

        self::assertSame(
            [[self::FERNITE_CARBIDE_PROJECT_ID, 'Fernite Carbide', self::FERNITE_CARBIDE_RUNS, ProjectCorrectionStatus::Skipped, null, 'ME/TE would change on 1016660 reaction job 0: 10/20 -> 0/0']],
            self::summaryOf($outcomes),
        );
        self::assertSame([[1, 10]], self::runsAndQuantityOfEachJob($this->rootJobsOf($project)));
    }

    public function testRootStepOfFixedRunsIsRegeneratedAtTheOutputOfItsRunsThenLeftUnchanged(): void
    {
        $project = $this->nitrogenFuelBlockProjectOfTheOldCalculation(self::NITROGEN_FUEL_BLOCK_PROJECT_ID);
        $this->rootJobsOf($project)[0]->setRunsFixed(true);
        $regeneration = $this->regenerationOf($project);

        $firstOutcomes = $regeneration->run(false);
        $secondOutcomes = $regeneration->run(false);

        self::assertSame(
            [[self::NITROGEN_FUEL_BLOCK_PROJECT_ID, 'Nitrogen Fuel Block', self::NITROGEN_FUEL_BLOCK_RUNS, ProjectCorrectionStatus::Changed, 'root jobs 1, runs 1, units 25 -> jobs 1, runs 1, units 40; steps 1 -> 1; job matches 0 -> 0, purchases 0 -> 0', null]],
            self::summaryOf($firstOutcomes),
        );
        self::assertSame(
            [[self::NITROGEN_FUEL_BLOCK_PROJECT_ID, 'Nitrogen Fuel Block', self::NITROGEN_FUEL_BLOCK_RUNS, ProjectCorrectionStatus::Unchanged, null, null]],
            self::summaryOf($secondOutcomes),
        );
        self::assertSame([[1, 40]], self::runsAndQuantityOfEachJob($this->rootJobsOf($project)));
    }

    public function testCompletedProjectIsNotSelected(): void
    {
        $project = $this->nitrogenFuelBlockProjectOfTheOldCalculation(self::NITROGEN_FUEL_BLOCK_PROJECT_ID)->setStatus(ProjectStatus::Completed);

        self::assertSame([], $this->regenerationOf($project)->run(false));
        self::assertSame([[1, 25]], self::runsAndQuantityOfEachJob($this->rootJobsOf($project)));
    }

    public function testProjectWhoseProductHasNoProducerInTheSdeFailsAndTheNextProjectIsRegenerated(): void
    {
        $roboticsProject = $this->identified(
            (new IndustryProject())->setUser($this->pilot)->setProductTypeId(self::ROBOTICS)->setRuns(self::ROBOTICS_RUNS),
            self::ROBOTICS_PROJECT_ID,
        );
        $nitrogenFuelBlockProject = $this->nitrogenFuelBlockProjectOfTheOldCalculationWithAJobMatchAndAPurchase();

        $outcomes = $this->regenerationOf($roboticsProject, $nitrogenFuelBlockProject)->run(false);

        self::assertSame(
            [
                [self::ROBOTICS_PROJECT_ID, 'Robotics', self::ROBOTICS_RUNS, ProjectCorrectionStatus::Failed, null, 'No blueprint or reaction found for product type ID 9848'],
                [self::NITROGEN_FUEL_BLOCK_PROJECT_ID, 'Nitrogen Fuel Block', self::NITROGEN_FUEL_BLOCK_RUNS, ProjectCorrectionStatus::Changed, self::NITROGEN_FUEL_BLOCK_CHANGE, null],
            ],
            self::summaryOf($outcomes),
        );
    }

    public function testProjectWhosePreviewFailsKeepsItsStepsAndTheNextProjectIsRegenerated(): void
    {
        $this->typeIdsMissingFromSde = [self::HYDROGEN_ISOTOPES];
        $this->buildFactoryOnGoldenSde();
        $hydrogenFuelBlockProject = $this->hydrogenFuelBlockProjectOfTheOldCalculationBuiltByHand();
        $nitrogenFuelBlockProject = $this->nitrogenFuelBlockProjectOfTheOldCalculationWithAJobMatchAndAPurchase();

        $outcomes = $this->regenerationOf($hydrogenFuelBlockProject, $nitrogenFuelBlockProject)->run(false);

        self::assertSame(
            [
                [self::HYDROGEN_FUEL_BLOCK_PROJECT_ID, 'Hydrogen Fuel Block', self::HYDROGEN_FUEL_BLOCK_RUNS, ProjectCorrectionStatus::Failed, null, 'Types missing from the SDE: 17889'],
                [self::NITROGEN_FUEL_BLOCK_PROJECT_ID, 'Nitrogen Fuel Block', self::NITROGEN_FUEL_BLOCK_RUNS, ProjectCorrectionStatus::Changed, self::NITROGEN_FUEL_BLOCK_CHANGE, null],
            ],
            self::summaryOf($outcomes),
        );
        self::assertSame([[1, self::HYDROGEN_FUEL_BLOCK_RUNS]], self::runsAndQuantityOfEachJob($this->rootJobsOf($hydrogenFuelBlockProject)));
        self::assertSame(
            [['/user/'.self::PILOT_ID.'/sync/industry-project', 'started'], ['/user/'.self::PILOT_ID.'/sync/industry-project', 'completed']],
            $this->topicsAndStatusesOfThePublishedUpdates(),
        );
    }

    public function testProjectWithoutRootStepIsNotSelected(): void
    {
        $project = $this->ferniteCarbideProjectOfTheOldCalculation(self::FERNITE_CARBIDE_PROJECT_ID);
        $project->getSteps()->removeElement($this->rootJobsOf($project)[0]);

        self::assertSame([], $this->regenerationOf($project)->run(false));
        self::assertSame(3, $project->getSteps()->count());
    }

    public function testProjectWhoseRootStepHasItsOutputQuantityIsNotSelectedEvenIfAnIntermediateKeepsTheMeAndTeOfAnOldCalculation(): void
    {
        $project = $this->ferniteCarbideProjectOfTheOldCalculation(self::FERNITE_CARBIDE_PROJECT_ID);
        $this->rootJobsOf($project)[0]->setRuns(self::FERNITE_CARBIDE_RUNS)->setQuantity(100_000);
        $this->jobsOfProduct($project, self::CERAMIC_POWDER)[0]->setMeLevel(10)->setTeLevel(20);

        self::assertSame([], $this->regenerationOf($project)->run(false));
    }

    public function testRootSplitInTwoJobsIsSummedAndItsSecondJobIsAbsorbedWithItsJobMatch(): void
    {
        $project = $this->nitrogenFuelBlockProjectOfTheOldCalculation(self::NITROGEN_FUEL_BLOCK_PROJECT_ID);
        $firstRootJob = $this->rootJobsOf($project)[0]->setQuantity(12)->setSplitGroupId('split-of-the-old-calculation')->setSplitIndex(0)->setTotalGroupRuns(2);
        $project->addStep(
            (new IndustryProjectStep())
                ->setBlueprintTypeId(self::NITROGEN_FUEL_BLOCK_BLUEPRINT)
                ->setProductTypeId(self::NITROGEN_FUEL_BLOCK)
                ->setActivityType('manufacturing')
                ->setDepth(0)
                ->setRuns(1)
                ->setQuantity(13)
                ->setMeLevel(self::PROJECT_ME_LEVEL)
                ->setTeLevel(self::PROJECT_TE_LEVEL)
                ->setSortOrder(1)
                ->setSplitGroupId('split-of-the-old-calculation')
                ->setSplitIndex(1)
                ->setTotalGroupRuns(2)
                ->addJobMatch((new IndustryStepJobMatch())->setEsiJobId(6_120_458)->setRuns(1)),
        );

        $outcomes = $this->regenerationOf($project)->run(false);

        self::assertSame(
            [[self::NITROGEN_FUEL_BLOCK_PROJECT_ID, 'Nitrogen Fuel Block', self::NITROGEN_FUEL_BLOCK_RUNS, ProjectCorrectionStatus::Changed, 'root jobs 2, runs 2, units 25 -> jobs 1, runs 25, units 1000; steps 2 -> 1; job matches 1 -> 1, purchases 0 -> 0', null]],
            self::summaryOf($outcomes),
        );
        self::assertSame([$firstRootJob], $project->getSteps()->toArray());
        self::assertSame([25, 1000, 10, 20, 1, 0], self::runsQuantityMeTeJobMatchesAndPurchasesOf($firstRootJob));
    }

    public function testRegenerationByTheCommandGivesTheSameStepsAsARegenerationAlone(): void
    {
        $regeneratedByTheService = $this->nitrogenFuelBlockProjectOfTheOldCalculationWithAJobMatchAndAPurchase();
        $regeneratedAlone = $this->nitrogenFuelBlockProjectOfTheOldCalculation(self::TWIN_NITROGEN_FUEL_BLOCK_PROJECT_ID);

        $this->regenerationOf($regeneratedByTheService)->run(false);
        $this->factory->regenerateSteps($regeneratedAlone);

        self::assertSame(self::canonicalStepsOf($regeneratedAlone), self::canonicalStepsOf($regeneratedByTheService));
    }

    public function testNoProjectGivesNoOutcome(): void
    {
        self::assertSame([], $this->regenerationOf()->run(false));
    }

    private function nitrogenFuelBlockProjectOfTheOldCalculationWithAJobMatchAndAPurchase(): IndustryProject
    {
        $project = $this->nitrogenFuelBlockProjectOfTheOldCalculation(self::NITROGEN_FUEL_BLOCK_PROJECT_ID);
        $this->rootJobsOf($project)[0]
            ->addJobMatch((new IndustryStepJobMatch())->setEsiJobId(6_120_457)->setRuns(1))
            ->addPurchase((new IndustryStepPurchase())->setTypeId(17888)->setQuantity(405));

        return $project;
    }

    private function hydrogenFuelBlockProjectOfTheOldCalculationBuiltByHand(): IndustryProject
    {
        $project = (new IndustryProject())
            ->setUser($this->pilot)
            ->setProductTypeId(self::HYDROGEN_FUEL_BLOCK)
            ->setRuns(self::HYDROGEN_FUEL_BLOCK_RUNS)
            ->setMeLevel(self::PROJECT_ME_LEVEL)
            ->setTeLevel(self::PROJECT_TE_LEVEL)
            ->setMaxJobDurationDays(self::MAX_JOB_DURATION_DAYS)
            ->addStep(
                (new IndustryProjectStep())
                    ->setBlueprintTypeId(self::HYDROGEN_FUEL_BLOCK_BLUEPRINT)
                    ->setProductTypeId(self::HYDROGEN_FUEL_BLOCK)
                    ->setActivityType('manufacturing')
                    ->setDepth(0)
                    ->setRuns(1)
                    ->setQuantity(self::HYDROGEN_FUEL_BLOCK_RUNS)
                    ->setMeLevel(self::PROJECT_ME_LEVEL)
                    ->setTeLevel(self::PROJECT_TE_LEVEL)
                    ->setSortOrder(0),
            );

        return $this->identified($project, self::HYDROGEN_FUEL_BLOCK_PROJECT_ID);
    }

    private function regenerationOf(IndustryProject ...$projectsInCreationOrder): MultiUnitProjectStepsRegeneration
    {
        $projectsById = [];
        foreach ($projectsInCreationOrder as $project) {
            $projectsById[$project->getId()?->toRfc4122()] = $project;
        }
        $projectRepository = $this->createStub(IndustryProjectRepository::class);
        $projectRepository->method('findIdsInCreationOrder')->willReturn(array_map(
            static fn (IndustryProject $project): ?Uuid => $project->getId(),
            array_values($projectsInCreationOrder),
        ));
        $projectRepository->method('find')->willReturnCallback(
            static fn (mixed $id): ?IndustryProject => $projectsById[(string) $id] ?? null,
        );

        return self::autowired(MultiUnitProjectStepsRegeneration::class, [
            $projectRepository,
            $this->entityManager,
            $this->productRepositoryOnGoldenSde(),
            $this->factory,
            $this->calculationService,
        ]);
    }

    private static function summaryOf(array $outcomes): array
    {
        return array_map(
            static fn (ProjectCorrectionOutcome $outcome): array => [$outcome->projectId, $outcome->label, $outcome->runs, $outcome->status, $outcome->change, $outcome->reason],
            $outcomes,
        );
    }

    private static function runsQuantityMeTeJobMatchesAndPurchasesOf(IndustryProjectStep $step): array
    {
        return [$step->getRuns(), $step->getQuantity(), $step->getMeLevel(), $step->getTeLevel(), $step->getJobMatches()->count(), $step->getPurchases()->count()];
    }

    private static function runsAndQuantityOfEachJob(array $jobs): array
    {
        return array_map(static fn (IndustryProjectStep $job): array => [$job->getRuns(), $job->getQuantity()], $jobs);
    }

    private static function canonicalStepsOf(IndustryProject $project): array
    {
        $steps = array_map(
            static fn (IndustryProjectStep $step): array => [
                $step->getSortOrder(),
                $step->getBlueprintTypeId(),
                $step->getActivityType(),
                $step->getDepth(),
                $step->getSplitIndex(),
                $step->getRuns(),
                $step->getQuantity(),
                $step->getMeLevel(),
                $step->getTeLevel(),
                $step->getStructureConfig(),
            ],
            $project->getSteps()->toArray(),
        );
        sort($steps);

        return $steps;
    }
}
