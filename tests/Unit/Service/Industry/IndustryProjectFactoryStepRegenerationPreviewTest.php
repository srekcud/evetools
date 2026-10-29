<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Service\Industry\IndustryProjectFactory;
use App\Service\Industry\StepJobValues;
use App\Service\Industry\StepRegenerationPreview;
use App\Service\Industry\StepRegenerationPreviewLine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectFactory::class)]
#[CoversClass(StepRegenerationPreview::class)]
#[Group('issue-284')]
final class IndustryProjectFactoryStepRegenerationPreviewTest extends TestCase
{
    use BuildsProjectsOfTheOldRootCalculationOnGoldenSde;

    private const string NITROGEN_FUEL_BLOCK_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-284000004051';
    private const string TWIN_NITROGEN_FUEL_BLOCK_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-284000014051';
    private const string FERNITE_CARBIDE_PROJECT_ID = '0192f0c4-7b1e-7a3c-9d2e-284000016673';

    protected function setUp(): void
    {
        $this->buildFactoryOnGoldenSde();
    }

    public function testPreviewTakesTheRootStepOfTheOldCalculationOfTwentyFiveRunsOfNitrogenFuelBlockBackAtTwentyFiveRunsOfOneThousandUnits(): void
    {
        $project = $this->nitrogenFuelBlockProjectOfTheOldCalculation(self::NITROGEN_FUEL_BLOCK_PROJECT_ID);
        $rootStep = $this->rootJobsOf($project)[0];

        $preview = $this->factory->stepRegenerationPreviewOf($project);

        self::assertSame(
            [[self::NITROGEN_FUEL_BLOCK_BLUEPRINT, 'manufacturing', 0, 0, [1, 25, 10, 20], [25, 1000, 10, 20]]],
            self::linesOf($preview),
        );
        self::assertSame([$rootStep], self::existingStepsOf($preview));
        self::assertSame(1, $preview->stepCountAfter);
    }

    public function testPreviewWritesNothingAndPublishesNothing(): void
    {
        $project = $this->nitrogenFuelBlockProjectOfTheOldCalculation(self::NITROGEN_FUEL_BLOCK_PROJECT_ID);
        $rootStep = $this->rootJobsOf($project)[0];
        $rootStep->addJobMatch((new IndustryStepJobMatch())->setEsiJobId(6_120_457)->setRuns(1));

        $this->factory->stepRegenerationPreviewOf($project);

        self::assertSame([$rootStep], $project->getSteps()->toArray());
        self::assertSame([1, 25, 10, 20, 1], [$rootStep->getRuns(), $rootStep->getQuantity(), $rootStep->getMeLevel(), $rootStep->getTeLevel(), $rootStep->getJobMatches()->count()]);
        self::assertSame([], $this->writesOfTheEntityManager());
        self::assertSame([], $this->topicsAndStatusesOfThePublishedUpdates());
    }

    public function testPreviewOfARootStepAtMeEightShowsTheMeOfTheProjectItWouldTake(): void
    {
        $project = $this->nitrogenFuelBlockProjectOfTheOldCalculation(self::NITROGEN_FUEL_BLOCK_PROJECT_ID);
        $this->rootJobsOf($project)[0]->setMeLevel(8);

        $preview = $this->factory->stepRegenerationPreviewOf($project);

        self::assertSame(
            [[self::NITROGEN_FUEL_BLOCK_BLUEPRINT, 'manufacturing', 0, 0, [1, 25, 8, 20], [25, 1000, 10, 20]]],
            self::linesOf($preview),
        );
    }

    public function testPreviewOfARootStepOfFixedRunsKeepsItsRunsAndCapsItsQuantityAtTheOutputOfThoseRuns(): void
    {
        $project = $this->nitrogenFuelBlockProjectOfTheOldCalculation(self::NITROGEN_FUEL_BLOCK_PROJECT_ID);
        $this->rootJobsOf($project)[0]->setRunsFixed(true);

        $preview = $this->factory->stepRegenerationPreviewOf($project);

        self::assertSame(
            [[self::NITROGEN_FUEL_BLOCK_BLUEPRINT, 'manufacturing', 0, 0, [1, 25, 10, 20], [1, 40, 10, 20]]],
            self::linesOf($preview),
        );
    }

    public function testPreviewTakesTheRootReactionOfTheOldCalculationOfTenRunsOfFerniteCarbideBackAtOneHundredThousandUnitsWithItsIntermediates(): void
    {
        $project = $this->ferniteCarbideProjectOfTheOldCalculation(self::FERNITE_CARBIDE_PROJECT_ID);

        $preview = $this->factory->stepRegenerationPreviewOf($project);

        self::assertSame(
            [
                [self::CERAMIC_POWDER_REACTION_FORMULA, 'reaction', 1, 0, [5, 974, 0, 0], [5, 974, 0, 0]],
                [self::FERNITE_ALLOY_REACTION_FORMULA, 'reaction', 1, 0, [5, 974, 0, 0], [5, 974, 0, 0]],
                [self::HYDROGEN_FUEL_BLOCK_BLUEPRINT, 'manufacturing', 1, 0, [3, 100, 10, 20], [3, 100, 10, 20]],
                [self::FERNITE_CARBIDE_REACTION_FORMULA, 'reaction', 0, 0, [1, 10, 0, 0], [10, 100_000, 0, 0]],
            ],
            self::linesOf($preview),
        );
        self::assertSame(4, $preview->stepCountAfter);
    }

    public function testPreviewOfACeramicPowderReactionAtMeTenAndTeTwentyShowsItWouldBeAtMeZeroAndTeZero(): void
    {
        $project = $this->ferniteCarbideProjectOfTheOldCalculation(self::FERNITE_CARBIDE_PROJECT_ID);
        $this->jobsOfProduct($project, self::CERAMIC_POWDER)[0]->setMeLevel(10)->setTeLevel(20);

        $preview = $this->factory->stepRegenerationPreviewOf($project);

        self::assertSame(
            [[self::CERAMIC_POWDER_REACTION_FORMULA, 'reaction', 1, 0, [5, 974, 10, 20], [5, 974, 0, 0]]],
            array_values(array_filter(self::linesOf($preview), static fn (array $line): bool => $line[0] === self::CERAMIC_POWDER_REACTION_FORMULA)),
        );
    }

    public function testPlannedJobThatNoExistingStepTakesBackHasNoValuesBefore(): void
    {
        $project = $this->ferniteCarbideProjectOfTheOldCalculation(self::FERNITE_CARBIDE_PROJECT_ID);
        $project->getSteps()->removeElement($this->rootJobsOf($project)[0]);

        $preview = $this->factory->stepRegenerationPreviewOf($project);

        $rootLines = array_values(array_filter($preview->lines, static fn (StepRegenerationPreviewLine $line): bool => $line->blueprintTypeId === self::FERNITE_CARBIDE_REACTION_FORMULA));
        self::assertSame(
            [[self::FERNITE_CARBIDE_REACTION_FORMULA, 'reaction', 0, 0, null, [10, 100_000, 0, 0]]],
            array_map(self::lineOf(...), $rootLines),
        );
        self::assertNull($rootLines[0]->existingStep);
        self::assertSame(4, $preview->stepCountAfter);
    }

    public function testStepOutOfThePlanIsCountedInTheStepsAfterTheRegeneration(): void
    {
        $project = $this->nitrogenFuelBlockProjectOfTheOldCalculation(self::NITROGEN_FUEL_BLOCK_PROJECT_ID);
        $project->addStep(
            (new IndustryProjectStep())
                ->setBlueprintTypeId(self::HYDROGEN_FUEL_BLOCK_BLUEPRINT)
                ->setProductTypeId(self::HYDROGEN_FUEL_BLOCK)
                ->setActivityType('manufacturing')
                ->setDepth(0)
                ->setRuns(3)
                ->setQuantity(120)
                ->setMeLevel(10)
                ->setTeLevel(20)
                ->setSortOrder(1),
        );

        $preview = $this->factory->stepRegenerationPreviewOf($project);

        self::assertSame(
            [[self::NITROGEN_FUEL_BLOCK_BLUEPRINT, 'manufacturing', 0, 0, [1, 25, 10, 20], [25, 1000, 10, 20]]],
            self::linesOf($preview),
        );
        self::assertSame(2, $preview->stepCountAfter);
    }

    public function testRegenerationAfterAPreviewGivesTheSameStepsAsARegenerationAlone(): void
    {
        $previewedProject = $this->nitrogenFuelBlockProjectOfTheOldCalculation(self::NITROGEN_FUEL_BLOCK_PROJECT_ID);
        $this->rootJobsOf($previewedProject)[0]->setRunsFixed(true);
        $twinProject = $this->nitrogenFuelBlockProjectOfTheOldCalculation(self::TWIN_NITROGEN_FUEL_BLOCK_PROJECT_ID);
        $this->rootJobsOf($twinProject)[0]->setRunsFixed(true);

        $this->factory->stepRegenerationPreviewOf($previewedProject);
        $this->factory->regenerateSteps($previewedProject);
        $this->factory->regenerateSteps($twinProject);

        self::assertSame(self::canonicalStepsOf($twinProject), self::canonicalStepsOf($previewedProject));
        self::assertSame([[1, 40]], array_map(static fn (IndustryProjectStep $job): array => [$job->getRuns(), $job->getQuantity()], $this->rootJobsOf($previewedProject)));
    }

    private static function linesOf(StepRegenerationPreview $preview): array
    {
        return array_map(self::lineOf(...), $preview->lines);
    }

    private static function lineOf(StepRegenerationPreviewLine $line): array
    {
        return [
            $line->blueprintTypeId,
            $line->activityType,
            $line->depth,
            $line->splitIndex,
            $line->before === null ? null : self::valuesOf($line->before),
            self::valuesOf($line->after),
        ];
    }

    private static function valuesOf(StepJobValues $values): array
    {
        return [$values->runs, $values->quantity, $values->meLevel, $values->teLevel];
    }

    private static function existingStepsOf(StepRegenerationPreview $preview): array
    {
        return array_map(static fn (StepRegenerationPreviewLine $line): ?IndustryProjectStep => $line->existingStep, $preview->lines);
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
                $step->isRunsFixed(),
                $step->getStructureConfig(),
            ],
            $project->getSteps()->toArray(),
        );
        sort($steps);

        return $steps;
    }
}
