<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Enum\ProjectCorrectionStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;

class ReactionStepMeLevelReset extends ProjectByProjectCorrection
{
    private const string REACTION_ACTIVITY_TYPE = 'reaction';
    private const string MERCURE_SYNC_TYPE = 'industry-project';

    public function __construct(
        IndustryProjectRepository $projectRepository,
        EntityManagerInterface $entityManager,
        IndustryCalculationService $calculationService,
        private readonly IndustryStepCalculator $stepCalculator,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
        parent::__construct($projectRepository, $entityManager, $calculationService);
    }

    /**
     * @return list<ProjectCorrectionOutcome>
     */
    public function run(bool $dryRun): array
    {
        return $this->correctProjects(
            static fn (IndustryProject $project): bool => $project->getStatus() === ProjectStatus::Active
                && self::reactionStepsWithMaterialEfficiencyOf($project) !== [],
            fn (IndustryProject $project): ProjectCorrectionOutcome => $this->reset($project, $dryRun),
        );
    }

    private function reset(IndustryProject $project, bool $dryRun): ProjectCorrectionOutcome
    {
        $stepsToReset = self::reactionStepsWithMaterialEfficiencyOf($project);
        $meLevelsBefore = array_values(array_unique(array_map(static fn (IndustryProjectStep $step): int => $step->getMeLevel(), $stepsToReset)));
        sort($meLevelsBefore);
        $stepCountBefore = $project->getSteps()->count();

        foreach ($stepsToReset as $step) {
            $step->setMeLevel(0);
        }
        $recalculatedSteps = $this->stepCalculator->applyStepQuantities($project);

        $change = \sprintf(
            '%d reaction steps ME %s -> 0, %d steps recalculated, steps %d -> %d',
            count($stepsToReset),
            implode('/', $meLevelsBefore),
            count($recalculatedSteps),
            $stepCountBefore,
            $project->getSteps()->count(),
        );

        if (!$dryRun) {
            $this->entityManager->flush();
            $this->mercurePublisher->syncCompleted(
                $project->getUser()->getId()?->toRfc4122() ?? throw new \LogicException('A project owner read from the database has no identifier'),
                self::MERCURE_SYNC_TYPE,
                'Reaction material efficiency reset',
                ['projectId' => $project->getId()?->toRfc4122() ?? ''],
            );
        }

        return $this->outcomeOf($project, ProjectCorrectionStatus::Changed, $change);
    }

    /**
     * @return list<IndustryProjectStep>
     */
    private static function reactionStepsWithMaterialEfficiencyOf(IndustryProject $project): array
    {
        return array_values(array_filter(
            $project->getSteps()->toArray(),
            static fn (IndustryProjectStep $step): bool => $step->getActivityType() === self::REACTION_ACTIVITY_TYPE && $step->getMeLevel() !== 0,
        ));
    }
}
