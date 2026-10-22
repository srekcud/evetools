<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Enum\IndustryActivityType;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryStructureConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Matches ESI industry jobs to project steps.
 *
 * Uses a greedy approach: for each step, finds all jobs for that blueprint
 * and assigns them until the step's runs are covered. Jobs already assigned
 * to another step are skipped to avoid double-matching. Cancelled jobs are ignored.
 */
class IndustryJobMatcher
{
    private const string JOB_MATCH_MODE_MANUAL = 'manual';
    private const string JOB_MATCH_MODE_NONE = 'none';
    private const int ESI_REACTION_ACTIVITY_ID = 9;
    private const array JOB_ACTIVITY_IDS_BY_STEP_ACTIVITY_TYPE = [
        'manufacturing' => [IndustryActivityType::Manufacturing->value],
        'reaction' => [self::ESI_REACTION_ACTIVITY_ID, IndustryActivityType::Reaction->value],
        'copy' => [IndustryActivityType::Copying->value],
        'invention' => [IndustryActivityType::Invention->value],
    ];

    public function __construct(
        private readonly IndustryStepCalculator $stepCalculator,
        private readonly IndustryCalculationService $calculationService,
        private readonly CachedIndustryJobRepository $jobRepository,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function matchEsiJobs(IndustryProject $project): void
    {
        $user = $project->getUser();
        $characterIds = [];
        foreach ($user->getCharacters() as $character) {
            $characterIds[] = $character->getId();
        }

        if (empty($characterIds)) {
            return;
        }

        $projectStartDate = $project->getEffectiveJobsStartDate();

        $this->clearRecomputableMatches($project);
        $this->entityManager->flush();

        $assignedJobIds = array_flip([
            ...$this->jobIdsMatchedToOtherProjects($project),
            ...$this->jobIdsKeptBySteps($project),
        ]);

        $stepsToRecalculate = false;

        foreach ($project->getSteps() as $step) {
            if (!$this->isAutoMatchable($step)) {
                continue;
            }

            // Find all jobs for this blueprint (no run count filter)
            $jobs = $this->onlyJobsWithOutput($this->jobRepository->findManufacturingJobsByBlueprint(
                $step->getBlueprintTypeId(),
                $characterIds,
                null,
                $projectStartDate,
            ));

            if (empty($jobs)) {
                continue;
            }

            $remainingRuns = $step->getRuns();

            foreach ($jobs as $job) {
                if ($remainingRuns <= 0) {
                    break;
                }

                $jobId = $job->getJobId();
                if (isset($assignedJobIds[$jobId])) {
                    continue;
                }

                // Only match jobs whose runs fit within what this step needs
                if ($job->getRuns() > $remainingRuns) {
                    continue;
                }

                $assignedJobIds[$jobId] = true;
                $remainingRuns -= $job->getRuns();

                $match = $this->createJobMatch($job, $step, $project, $stepsToRecalculate);
                $step->addJobMatch($match);
            }

            // Fallback: try matching one oversized job if no exact matches covered all runs
            if ($remainingRuns > 0) {
                foreach ($jobs as $job) {
                    $jobId = $job->getJobId();
                    if (isset($assignedJobIds[$jobId])) {
                        continue;
                    }
                    if ($job->getRuns() <= $remainingRuns) {
                        continue; // Would have been handled above
                    }

                    $assignedJobIds[$jobId] = true;

                    $match = $this->createJobMatch($job, $step, $project, $stepsToRecalculate);
                    $step->addJobMatch($match);
                    break; // Only one fallback job
                }
            }
        }

        $this->persistAndRecalculate($project, $stepsToRecalculate);
    }

    public function isJobOfStepActivity(CachedIndustryJob $job, IndustryProjectStep $step): bool
    {
        $stepActivityType = $step->getActivityType();

        return array_key_exists($stepActivityType, self::JOB_ACTIVITY_IDS_BY_STEP_ACTIVITY_TYPE)
            && in_array($job->getActivityId(), self::JOB_ACTIVITY_IDS_BY_STEP_ACTIVITY_TYPE[$stepActivityType], true);
    }

    public function linkJobToStep(CachedIndustryJob $job, IndustryProjectStep $step): void
    {
        $project = $step->getProject();
        $stepStructureCorrected = false;

        $step->addJobMatch($this->createJobMatch($job, $step, $project, $stepStructureCorrected));

        $this->persistAndRecalculate($project, $stepStructureCorrected);
    }

    private function persistAndRecalculate(IndustryProject $project, bool $stepsToRecalculate): void
    {
        $this->entityManager->flush();

        if ($stepsToRecalculate) {
            $this->stepCalculator->recalculateStepQuantities($project);
        }
    }

    private function clearRecomputableMatches(IndustryProject $project): void
    {
        $recomputableSteps = $project->getSteps()->filter(
            fn (IndustryProjectStep $step): bool => !$this->keepsItsJobMatches($step),
        );
        foreach ($recomputableSteps as $step) {
            foreach ($step->getJobMatches()->toArray() as $match) {
                $step->getJobMatches()->removeElement($match);
                $this->entityManager->remove($match);
            }
        }
    }

    /**
     * @return iterable<int>
     */
    private function jobIdsMatchedToOtherProjects(IndustryProject $project): iterable
    {
        $otherMatches = $this->entityManager->createQuery(
            'SELECT m.esiJobId FROM App\Entity\IndustryStepJobMatch m
             JOIN m.step s
             WHERE s.project != :project'
        )->setParameter('project', $project)->getScalarResult();

        return array_column($otherMatches, 'esiJobId');
    }

    /**
     * @return iterable<int>
     */
    private function jobIdsKeptBySteps(IndustryProject $project): iterable
    {
        foreach ($project->getSteps()->filter($this->keepsItsJobMatches(...)) as $step) {
            foreach ($step->getJobMatches() as $match) {
                yield $match->getEsiJobId();
            }
        }
    }

    private function isManuallyMatched(IndustryProjectStep $step): bool
    {
        return $step->getJobMatchMode() === self::JOB_MATCH_MODE_MANUAL;
    }

    private function keepsItsJobMatches(IndustryProjectStep $step): bool
    {
        return $step->isPurchased() || $this->isManuallyMatched($step);
    }

    private function isAutoMatchable(IndustryProjectStep $step): bool
    {
        return !$this->keepsItsJobMatches($step)
            && $step->getActivityType() !== 'copy'
            && $step->getJobMatchMode() !== self::JOB_MATCH_MODE_NONE;
    }

    /**
     * A cancelled job never produced anything: it can neither cover runs nor drive the structure.
     *
     * @param CachedIndustryJob[] $jobs
     *
     * @return CachedIndustryJob[]
     */
    private function onlyJobsWithOutput(array $jobs): array
    {
        return array_filter($jobs, static fn (CachedIndustryJob $job): bool => !$job->hasNoOutput());
    }

    /**
     * Create a job match entity from a cached ESI job, with facility auto-correction.
     */
    private function createJobMatch(
        CachedIndustryJob $job,
        IndustryProjectStep $step,
        IndustryProject $project,
        bool &$stepsToRecalculate,
    ): IndustryStepJobMatch {
        $match = new IndustryStepJobMatch();
        $match->setEsiJobId($job->getJobId());
        $match->copyJobState($job);
        $match->setCharacterName($job->getCharacter()->getName());

        // Capture facility info from ESI job
        $stationId = $job->getStationId();
        if ($stationId !== null) {
            $match->setFacilityId($stationId);
            $match->setFacilityName($this->calculationService->resolveFacilityName($stationId));

            // Try to auto-correct step's structure config
            $currentConfig = $step->getStructureConfig();
            $currentLocationId = $currentConfig?->getLocationId();

            if ($currentLocationId !== $stationId) {
                $facilityConfig = $this->structureConfigRepository
                    ->findByUserAndLocationId($project->getUser(), $stationId);

                if ($facilityConfig !== null && $facilityConfig->getId() !== $currentConfig?->getId()) {
                    // Record what was planned before correction
                    $match->setPlannedStructureName($currentConfig?->getName() ?? 'Aucune structure');
                    $currentBonus = $this->calculationService->getStructureBonusForStep($step);
                    $match->setPlannedMaterialBonus($currentBonus['materialBonus']['total']);

                    // Auto-correct
                    $step->setStructureConfig($facilityConfig);
                    $stepsToRecalculate = true;
                }
            }
        }

        return $match;
    }
}
