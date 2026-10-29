<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityProduct;
use App\Enum\IndustryActivityType;
use App\Enum\ProjectCorrectionStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use Doctrine\ORM\EntityManagerInterface;

class MultiUnitProjectStepsRegeneration extends ProjectByProjectCorrection
{
    public function __construct(
        IndustryProjectRepository $projectRepository,
        EntityManagerInterface $entityManager,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly IndustryProjectFactory $projectFactory,
        IndustryCalculationService $calculationService,
    ) {
        parent::__construct($projectRepository, $entityManager, $calculationService);
    }

    /**
     * @return list<ProjectCorrectionOutcome>
     */
    public function run(bool $dryRun): array
    {
        return $this->correctProjects(
            $this->hasRootStepsBelowItsProducedQuantity(...),
            fn (IndustryProject $project): ProjectCorrectionOutcome => $this->regenerated($project, $dryRun),
        );
    }

    private function hasRootStepsBelowItsProducedQuantity(IndustryProject $project): bool
    {
        if ($project->getStatus() !== ProjectStatus::Active) {
            return false;
        }

        $producer = $this->producerOf($project);
        if ($producer->getQuantity() <= 1) {
            return false;
        }

        $rootSteps = self::rootStepsOf($project, $producer);

        return $rootSteps !== []
            && array_sum(array_map(static fn (IndustryProjectStep $step): int => $step->getQuantity(), $rootSteps)) < $project->getRuns() * $producer->getQuantity();
    }

    private function regenerated(IndustryProject $project, bool $dryRun): ProjectCorrectionOutcome
    {
        $producer = $this->producerOf($project);
        $before = self::snapshotOf($project, self::rootJobValuesOf(self::rootStepsOf($project, $producer)));
        $preview = $this->projectFactory->stepRegenerationPreviewOf($project);

        $meTeChanges = self::meTeChangesOf($preview);
        if ($meTeChanges !== []) {
            return $this->outcomeOf($project, ProjectCorrectionStatus::Skipped, reason: 'ME/TE would change on '.implode(', ', $meTeChanges));
        }

        $rootJobValuesAfter = self::rootJobValuesAfterPreview($preview, $producer);
        if ($rootJobValuesAfter === $before['rootJobs']) {
            return $this->outcomeOf($project, ProjectCorrectionStatus::Unchanged);
        }

        if ($dryRun) {
            $after = ['stepCount' => $preview->stepCountAfter, 'rootJobs' => $rootJobValuesAfter, 'jobMatchCount' => $before['jobMatchCount'], 'purchaseCount' => $before['purchaseCount']];
        } else {
            $this->projectFactory->regenerateSteps($project);
            $after = self::snapshotOf($project, self::rootJobValuesOf(self::rootStepsOf($project, $producer)));
        }

        return $this->outcomeOf($project, ProjectCorrectionStatus::Changed, self::describe($before, $after));
    }

    private function producerOf(IndustryProject $project): IndustryActivityProduct
    {
        return SplitGroupMaterials::producerOf($this->activityProductRepository, $project->getProductTypeId());
    }

    /**
     * @return list<IndustryProjectStep>
     */
    private static function rootStepsOf(IndustryProject $project, IndustryActivityProduct $producer): array
    {
        $rootSteps = array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getDepth() === 0
                && $step->getBlueprintTypeId() === $producer->getTypeId()
                && $step->getActivityType() === self::activityTypeOf($producer),
        );
        usort($rootSteps, static fn (IndustryProjectStep $a, IndustryProjectStep $b): int => $a->getSplitIndex() <=> $b->getSplitIndex());

        return $rootSteps;
    }

    private static function activityTypeOf(IndustryActivityProduct $producer): string
    {
        return $producer->getActivityId() === IndustryActivityType::Reaction->value ? 'reaction' : 'manufacturing';
    }

    /**
     * @param list<IndustryProjectStep> $rootSteps
     * @return list<array{int, int}>
     */
    private static function rootJobValuesOf(array $rootSteps): array
    {
        return array_map(static fn (IndustryProjectStep $step): array => [$step->getRuns(), $step->getQuantity()], $rootSteps);
    }

    /**
     * @return list<array{int, int}>
     */
    private static function rootJobValuesAfterPreview(StepRegenerationPreview $preview, IndustryActivityProduct $producer): array
    {
        $rootLines = array_filter(
            $preview->lines,
            static fn (StepRegenerationPreviewLine $line): bool => $line->depth === 0
                && $line->blueprintTypeId === $producer->getTypeId()
                && $line->activityType === self::activityTypeOf($producer),
        );
        usort($rootLines, static fn (StepRegenerationPreviewLine $a, StepRegenerationPreviewLine $b): int => $a->splitIndex <=> $b->splitIndex);

        return array_map(static fn (StepRegenerationPreviewLine $line): array => [$line->after->runs, $line->after->quantity], $rootLines);
    }

    /**
     * @return list<string>
     */
    private static function meTeChangesOf(StepRegenerationPreview $preview): array
    {
        $changedLines = array_filter(
            $preview->lines,
            static fn (StepRegenerationPreviewLine $line): bool => $line->before !== null
                && ($line->before->meLevel !== $line->after->meLevel || $line->before->teLevel !== $line->after->teLevel),
        );

        return array_values(array_map(
            static fn (StepRegenerationPreviewLine $line): string => \sprintf(
                '%d %s job %d: %d/%d -> %d/%d',
                $line->blueprintTypeId,
                $line->activityType,
                $line->splitIndex,
                $line->before?->meLevel,
                $line->before?->teLevel,
                $line->after->meLevel,
                $line->after->teLevel,
            ),
            $changedLines,
        ));
    }

    /**
     * @param list<array{int, int}> $rootJobs
     * @return array{stepCount: int, rootJobs: list<array{int, int}>, jobMatchCount: int, purchaseCount: int}
     */
    private static function snapshotOf(IndustryProject $project, array $rootJobs): array
    {
        $steps = $project->getSteps();

        return [
            'stepCount' => $steps->count(),
            'rootJobs' => $rootJobs,
            'jobMatchCount' => array_sum(array_map(static fn (IndustryProjectStep $step): int => $step->getJobMatches()->count(), $steps->toArray())),
            'purchaseCount' => array_sum(array_map(static fn (IndustryProjectStep $step): int => $step->getPurchases()->count(), $steps->toArray())),
        ];
    }

    /**
     * @param array{stepCount: int, rootJobs: list<array{int, int}>, jobMatchCount: int, purchaseCount: int} $before
     * @param array{stepCount: int, rootJobs: list<array{int, int}>, jobMatchCount: int, purchaseCount: int} $after
     */
    private static function describe(array $before, array $after): string
    {
        [$jobsBefore, $runsBefore, $unitsBefore] = self::rootTotals($before['rootJobs']);
        [$jobsAfter, $runsAfter, $unitsAfter] = self::rootTotals($after['rootJobs']);

        return \sprintf(
            'root jobs %d, runs %d, units %d -> jobs %d, runs %d, units %d; steps %d -> %d; job matches %d -> %d, purchases %d -> %d',
            $jobsBefore,
            $runsBefore,
            $unitsBefore,
            $jobsAfter,
            $runsAfter,
            $unitsAfter,
            $before['stepCount'],
            $after['stepCount'],
            $before['jobMatchCount'],
            $after['jobMatchCount'],
            $before['purchaseCount'],
            $after['purchaseCount'],
        );
    }

    /**
     * @param list<array{int, int}> $rootJobs
     * @return array{int, int, int}
     */
    private static function rootTotals(array $rootJobs): array
    {
        return [count($rootJobs), array_sum(array_column($rootJobs, 0)), array_sum(array_column($rootJobs, 1))];
    }
}
