<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\UnassignedJob;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Enum\ParticipantStatus;
use App\Exception\InventionDataMissingException;
use App\Exception\ProducerMissingFromSdeException;
use App\Industry\Domain\JobMatching\AssignmentCandidate;
use App\Industry\Domain\JobMatching\CandidateJob;
use App\Industry\Domain\JobMatching\CandidateLine;
use App\Industry\Domain\JobMatching\CandidateProject;
use App\Industry\Domain\JobMatching\EligiblePair;
use App\Industry\Domain\JobMatching\JobMatchPlanner;
use App\Industry\Domain\JobMatching\JobToAssign;
use App\Industry\Domain\JobMatching\MatchSignal;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\UnassignedJobRepository;
use App\Service\Industry\Project\ProjectContainerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class IndustryJobMatcher
{
    /** @var array<string, int|null> */
    private array $locationIdsByStructureConfigId = [];

    public function __construct(
        private readonly IndustryCalculationService $calculationService,
        private readonly CachedIndustryJobRepository $jobRepository,
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly IndustryProjectJobMatchRepository $jobMatchRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly ProjectContainerService $containerService,
        private readonly IndustryProjectLineReservationRepository $reservationRepository,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly UnassignedJobRepository $unassignedJobRepository,
    ) {
    }

    /**
     * @param list<IndustryProject> $projects
     *
     * @return array<string, list<LineMatchFailure>>
     */
    public function matchOpenProjects(array $projects): array
    {
        $failures = [];
        $released = [];
        foreach ($projects as $project) {
            $failures[$project->getId()->toRfc4122()] = [];
            if ($this->released($project)) {
                $released[] = $project;
            }
        }

        $candidateProjects = [];
        $candidateLines = [];
        $candidateJobs = [];
        $eligiblePairs = [];
        $linesById = [];
        $jobsById = [];
        $jobsByQuery = [];
        $unavailableJobIds = [];
        $retiredJobIds = [];
        $dismissedJobIds = array_flip($this->unassignedJobRepository->findDismissedEsiJobIds());

        foreach ($released as $project) {
            $unavailableJobIds += $this->unavailableJobIdsOf($project);
        }

        foreach ($released as $project) {
            $projectId = $project->getId()->toRfc4122();
            $characterIds = $this->characterIdsOf($project);
            $candidateProjects[] = new CandidateProject(
                $projectId,
                $project->getCreatedAt(),
                $this->producerInstallerIdsOf($project),
                $this->containerItemIdsOf($project),
                $this->dedicatedInstallerIdsOf($project),
            );
            if ($characterIds === []) {
                continue;
            }

            $candidates = $this->candidatesOf($project);
            foreach ($candidates['failures'] as $failure) {
                $failures[$projectId][] = $this->reported($project, $failure);
            }

            $reservingInstallerIds = $candidates['lines'] === [] ? [] : $this->reservingInstallerIdsByLineId($project);
            foreach ($candidates['lines'] as [$line, $remainingRuns, $blueprintTypeId]) {
                $lineId = $line->getId()->toRfc4122();
                $linesById[$lineId] = $line;
                $candidateLines[] = new CandidateLine(
                    $lineId,
                    $projectId,
                    $remainingRuns,
                    $this->plannedStructureIdOf($line),
                    $reservingInstallerIds[$lineId] ?? [],
                );

                $queryKey = $blueprintTypeId.'|'.implode(',', $line->esiJobActivityIds()).'|'.implode(',', array_map(static fn (Uuid|int|null $id): string => (string) $id, $characterIds)).'|'.$project->getCreatedAt()->format('U.u');
                $jobsByQuery[$queryKey] ??= $this->jobRepository->findJobsByBlueprintAndActivities($blueprintTypeId, $line->esiJobActivityIds(), $characterIds, null, $project->getCreatedAt());
                foreach ($jobsByQuery[$queryKey] as $job) {
                    if (isset($dismissedJobIds[$job->getJobId()])) {
                        continue;
                    }

                    if ($job->hasNoOutput() || isset($unavailableJobIds[$job->getJobId()])) {
                        $retiredJobIds[$job->getJobId()] = true;

                        continue;
                    }

                    if (!isset($jobsById[$job->getJobId()])) {
                        $jobsById[$job->getJobId()] = $job;
                        $candidateJobs[] = new CandidateJob(
                            $job->getJobId(),
                            $job->getRuns(),
                            $this->installerIdOf($job->getCharacter()),
                            $job->getBlueprintLocationId(),
                            $job->getOutputLocationId(),
                            $job->getStationId(),
                        );
                    }

                    $eligiblePairs[] = new EligiblePair($job->getJobId(), $lineId);
                }
            }
        }

        $plan = JobMatchPlanner::plan($candidateProjects, $candidateLines, $candidateJobs, $eligiblePairs);
        foreach ($plan->assignments as $assignment) {
            $line = $linesById[$assignment->lineId];
            $line->addJobMatch($this->createJobMatch(
                $jobsById[$assignment->jobId],
                $line,
                false,
                array_map(static fn (MatchSignal $signal): string => $signal->value, $assignment->signals),
            ));
        }

        $this->recordUnassignedJobs($plan->unassignedJobs, $jobsById, [...array_keys($retiredJobIds), ...array_keys($unavailableJobIds)]);

        $this->entityManager->flush();

        return $failures;
    }

    public function expectedBlueprintTypeIdOf(IndustryProjectBomLine $line): int
    {
        if (IndustryProjectBomLine::ACTIVITY_INVENTION !== $line->getActivityType()) {
            return $this->productRepository->producerOf($line->getTypeId())->getTypeId();
        }

        $t2Manufacturing = $this->productRepository->findBlueprintForProduct($line->getTypeId(), IndustryActivityType::Manufacturing->value)
            ?? throw new InventionDataMissingException($line->getTypeId());

        return ($this->productRepository->findBlueprintForProduct($t2Manufacturing->getTypeId(), IndustryActivityType::Invention->value)
            ?? throw new InventionDataMissingException($line->getTypeId()))->getTypeId();
    }

    public function isJobOfBomLineActivity(CachedIndustryJob $job, IndustryProjectBomLine $line): bool
    {
        return $line->isMatchableJob()
            && \in_array($job->getActivityId(), $line->esiJobActivityIds(), true);
    }

    public function linkJobToBomLine(CachedIndustryJob $job, IndustryProjectBomLine $line, ?int $runs = null): IndustryProjectJobMatch
    {
        $match = $this->createJobMatch($job, $line, true, null, $runs);
        $line->addJobMatch($match);
        $this->forgetUnassignedJobs([$job->getJobId()]);
        $this->entityManager->flush();

        return $match;
    }

    /**
     * @param list<JobToAssign>         $unassignedJobs
     * @param array<int, CachedIndustryJob> $jobsById
     * @param list<int>                 $retiredJobIds
     */
    private function recordUnassignedJobs(array $unassignedJobs, array $jobsById, array $retiredJobIds): void
    {
        $unassignedIds = array_map(static fn (JobToAssign $unassigned): int => $unassigned->jobId, $unassignedJobs);
        $stored = $this->unassignedJobRepository->findByEsiJobIds([...array_keys($jobsById), ...$retiredJobIds]);

        foreach ($unassignedJobs as $unassigned) {
            $row = $stored[$unassigned->jobId] ?? (new UnassignedJob())->setJob($jobsById[$unassigned->jobId]);
            $row->setCandidates(array_map(static fn (AssignmentCandidate $candidate): array => [
                'projectId' => $candidate->projectId,
                'lineId' => $candidate->lineId,
                'score' => $candidate->score,
                'signals' => array_map(static fn (MatchSignal $signal): string => $signal->value, $candidate->signals),
                'oversized' => $candidate->oversized,
            ], $unassigned->candidates));
            $this->entityManager->persist($row);
        }

        $removed = [];
        foreach ($stored as $jobId => $row) {
            if (!\in_array($jobId, $unassignedIds, true)) {
                $removed[spl_object_id($row)] = $row;
            }
        }
        foreach ($this->unassignedJobRepository->findWithoutOutput() as $row) {
            $removed[spl_object_id($row)] = $row;
        }
        foreach ($removed as $row) {
            $this->entityManager->remove($row);
        }
    }

    /**
     * @param list<int> $esiJobIds
     */
    private function forgetUnassignedJobs(array $esiJobIds): void
    {
        foreach ($this->unassignedJobRepository->findByEsiJobIds($esiJobIds) as $row) {
            $this->entityManager->remove($row);
        }
    }

    private function reported(IndustryProject $project, LineMatchFailure $failure): LineMatchFailure
    {
        $this->logger->warning('Failed to match jobs of line', $failure->logContext($project->getId()->toRfc4122()));

        return $failure;
    }

    private function released(IndustryProject $project): bool
    {
        try {
            $this->releaseMatchesWithoutOutput($project);

            return true;
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to release the matches without output of project', [
                'projectId' => $project->getId()->toRfc4122(),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return list<string>|null
     */
    private function producerInstallerIdsOf(IndustryProject $project): ?array
    {
        $producerEveCharacterIds = $project->getProducerCharacterIds();
        if ($producerEveCharacterIds === null) {
            return null;
        }

        $installerIds = [];
        foreach ($this->usersWhoseJobsAreMatched($project) as $user) {
            foreach ($user->getCharacters() as $character) {
                if (\in_array($character->getEveCharacterId(), $producerEveCharacterIds, true)) {
                    $installerIds[] = $this->installerIdOf($character);
                }
            }
        }

        return $installerIds;
    }

    /**
     * @return list<string>
     */
    private function dedicatedInstallerIdsOf(IndustryProject $project): array
    {
        $installerIds = [];
        foreach ($this->usersWhoseJobsAreMatched($project) as $user) {
            foreach ($user->getCharacters() as $character) {
                if ($character->getActiveProject() === $project) {
                    $installerIds[] = $this->installerIdOf($character);
                }
            }
        }

        return $installerIds;
    }

    /**
     * @return list<int>|null
     */
    private function containerItemIdsOf(IndustryProject $project): ?array
    {
        $scope = $this->containerService->containerScopeOf($project);

        return $scope->isKnown() ? $scope->containerItemIds : null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function reservingInstallerIdsByLineId(IndustryProject $project): array
    {
        $installerIds = [];
        foreach ($this->reservationRepository->findByProject($project) as $reservation) {
            $user = $reservation->getParticipant()->getUser();
            if ($user === null) {
                continue;
            }

            foreach ($user->getCharacters() as $character) {
                $installerIds[$reservation->getBomLine()->getId()->toRfc4122()][] = $this->installerIdOf($character);
            }
        }

        return $installerIds;
    }

    private function installerIdOf(Character $character): string
    {
        return ($character->getId() ?? throw new \LogicException('A character matched to a job must be persisted'))->toRfc4122();
    }

    private function plannedStructureIdOf(IndustryProjectBomLine $line): ?int
    {
        $plannedStructureId = $line->getPlannedStructureId();
        if ($plannedStructureId === null || !Uuid::isValid($plannedStructureId)) {
            return null;
        }

        if (!\array_key_exists($plannedStructureId, $this->locationIdsByStructureConfigId)) {
            $this->locationIdsByStructureConfigId[$plannedStructureId] = $this->structureConfigRepository->find($plannedStructureId)?->getLocationId();
        }

        return $this->locationIdsByStructureConfigId[$plannedStructureId];
    }

    /**
     * @return array{lines: list<array{0: IndustryProjectBomLine, 1: int, 2: int}>, failures: list<LineMatchFailure>}
     */
    private function candidatesOf(IndustryProject $project): array
    {
        $lines = [];
        $failures = [];
        foreach ($this->recomputableJobLinesOf($project) as $line) {
            $runs = $line->getRuns();
            if ($runs === null) {
                $failures[] = LineMatchFailure::noRuns($line);
                continue;
            }

            $remainingRuns = $runs - $line->getMatchedRuns();
            if ($remainingRuns <= 0) {
                continue;
            }

            try {
                $blueprintTypeId = $this->expectedBlueprintTypeIdOf($line);
            } catch (ProducerMissingFromSdeException) {
                $failures[] = LineMatchFailure::producerMissingFromSde($line);
                continue;
            } catch (InventionDataMissingException) {
                $failures[] = LineMatchFailure::inventionDataMissing($line);
                continue;
            }

            $lines[] = [$line, $remainingRuns, $blueprintTypeId];
        }

        return ['lines' => $lines, 'failures' => $failures];
    }

    /**
     * @return array<int, true>
     */
    private function unavailableJobIdsOf(IndustryProject $project): array
    {
        $jobLines = $project->getBomItems()->filter(
            static fn (IndustryProjectBomLine $line): bool => $line->isMatchableJob(),
        );

        return array_fill_keys([
            ...$this->jobMatchRepository->findEsiJobIdsMatchedToOtherProjects($project),
            ...$this->jobIdsMatchedTo($jobLines->toArray()),
        ], true);
    }

    /**
     * @return list<Uuid|int|null>
     */
    private function characterIdsOf(IndustryProject $project): array
    {
        $characterIds = [];
        foreach ($this->usersWhoseJobsAreMatched($project) as $user) {
            foreach ($user->getCharacters() as $character) {
                $characterIds[] = $character->getId();
            }
        }

        return $characterIds;
    }

    private function releaseMatchesWithoutOutput(IndustryProject $project): void
    {
        foreach ($this->recomputableJobLinesOf($project) as $line) {
            foreach ($line->getJobMatches()->toArray() as $match) {
                if ($match->hasNoOutput()) {
                    $line->getJobMatches()->removeElement($match);
                    $this->entityManager->remove($match);
                }
            }
        }
    }

    public function releaseAutomaticMatches(IndustryProject $project): void
    {
        $this->clearAutomaticMatches($this->recomputableJobLinesOf($project));
        $this->entityManager->flush();
    }

    /**
     * @return IndustryProjectBomLine[]
     */
    private function recomputableJobLinesOf(IndustryProject $project): array
    {
        return $project->getBomItems()->filter(
            static fn (IndustryProjectBomLine $line): bool => $line->isMatchableJob() && !$line->hasManualJobMatch(),
        )->toArray();
    }

    /**
     * @param IndustryProjectBomLine[] $lines
     */
    private function clearAutomaticMatches(array $lines): void
    {
        foreach ($lines as $line) {
            foreach ($line->getJobMatches()->toArray() as $match) {
                $line->getJobMatches()->removeElement($match);
                $this->entityManager->remove($match);
            }
        }
    }

    /**
     * @param IndustryProjectBomLine[] $lines
     *
     * @return int[]
     */
    private function jobIdsMatchedTo(array $lines): array
    {
        $jobIds = [];
        foreach ($lines as $line) {
            foreach ($line->getJobMatches() as $match) {
                $jobIds[] = $match->getEsiJobId();
            }
        }

        return $jobIds;
    }

    /**
     * @return list<User>
     */
    private function usersWhoseJobsAreMatched(IndustryProject $project): array
    {
        $users = [$project->getOwner()];
        foreach ($project->getMembers() as $member) {
            $user = $member->getUser();
            if (ParticipantStatus::Accepted === $member->getStatus() && null !== $user && !\in_array($user, $users, true)) {
                $users[] = $user;
            }
        }

        return $users;
    }

    /**
     * @param list<string>|null $matchSignals
     */
    private function createJobMatch(CachedIndustryJob $job, IndustryProjectBomLine $line, bool $manual, ?array $matchSignals, ?int $runs = null): IndustryProjectJobMatch
    {
        $match = new IndustryProjectJobMatch();
        if ($runs !== null) {
            $match->setRuns($runs);
        }
        $match
            ->setEsiJobId($job->getJobId())
            ->copyJobState($job)
            ->setCharacterName($job->getCharacter()->getName())
            ->setManual($manual)
            ->setMatchSignals($matchSignals);

        $stationId = $job->getStationId();
        if ($stationId !== null) {
            $match->setFacilityId($stationId);
            $match->setFacilityName($this->calculationService->resolveFacilityName($stationId));
        }

        return $match;
    }
}
