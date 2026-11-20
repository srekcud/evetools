<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Exception\ProducerMissingFromSdeException;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class IndustryJobMatcher
{
    private const int ESI_REACTION_ACTIVITY_ID = 9;
    private const array JOB_ACTIVITY_IDS_BY_LINE_ACTIVITY_TYPE = [
        'manufacturing' => [IndustryActivityType::Manufacturing->value],
        'reaction' => [self::ESI_REACTION_ACTIVITY_ID, IndustryActivityType::Reaction->value],
    ];

    public function __construct(
        private readonly IndustryCalculationService $calculationService,
        private readonly CachedIndustryJobRepository $jobRepository,
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly IndustryProjectJobMatchRepository $jobMatchRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int, true> $contestedJobIds
     *
     * @return list<LineMatchFailure>
     */
    public function matchEsiJobs(IndustryProject $project, array $contestedJobIds = []): array
    {
        $characterIds = $this->characterIdsOf($project);
        if ($characterIds === []) {
            return [];
        }

        $this->clearAutomaticMatches($this->recomputableJobLinesOf($project));
        $this->entityManager->flush();

        $candidates = $this->candidatesOf($project, $characterIds);
        foreach ($candidates['failures'] as $failure) {
            $this->reported($project, $failure);
        }

        $assignedJobIds = $this->unavailableJobIdsOf($project) + $contestedJobIds;
        foreach ($this->jobsFittingLines($candidates['lines'], $assignedJobIds) as [$line, $job]) {
            $line->addJobMatch($this->createJobMatch($job, $line, false));
        }

        $this->entityManager->flush();

        return $candidates['failures'];
    }

    /**
     * @param list<IndustryProject> $projects
     *
     * @return array<int, true>
     */
    public function contestedJobIds(array $projects): array
    {
        $plans = [];
        foreach ($projects as $project) {
            if ($project->getStatus() === ProjectStatus::Draft) {
                continue;
            }

            $characterIds = $this->characterIdsOf($project);
            if ($characterIds === []) {
                continue;
            }

            $plans[] = [$this->candidatesOf($project, $characterIds)['lines'], $this->unavailableJobIdsOf($project)];
        }

        $contestedJobIds = [];
        do {
            $proposals = [];
            foreach ($plans as $planIndex => [$lines, $unavailableJobIds]) {
                $assignedJobIds = $unavailableJobIds + $contestedJobIds;
                foreach ($this->jobsFittingLines($lines, $assignedJobIds) as [, $job]) {
                    $proposals[$job->getJobId()][] = $planIndex;
                }
            }

            $newlyContestedJobIds = array_keys(array_filter($proposals, static fn (array $planIndexes): bool => \count($planIndexes) > 1));
            $contestedJobIds += array_fill_keys($newlyContestedJobIds, true);
        } while ($newlyContestedJobIds !== []);

        return $contestedJobIds;
    }

    public function isJobOfBomLineActivity(CachedIndustryJob $job, IndustryProjectBomLine $line): bool
    {
        $lineActivityType = $line->getActivityType();

        return $line->isManufacturingOrReactionJob()
            && $lineActivityType !== null
            && \array_key_exists($lineActivityType, self::JOB_ACTIVITY_IDS_BY_LINE_ACTIVITY_TYPE)
            && \in_array($job->getActivityId(), self::JOB_ACTIVITY_IDS_BY_LINE_ACTIVITY_TYPE[$lineActivityType], true);
    }

    public function linkJobToBomLine(CachedIndustryJob $job, IndustryProjectBomLine $line): IndustryProjectJobMatch
    {
        $match = $this->createJobMatch($job, $line, true);
        $line->addJobMatch($match);
        $this->entityManager->flush();

        return $match;
    }

    private function reported(IndustryProject $project, LineMatchFailure $failure): LineMatchFailure
    {
        $this->logger->warning('Failed to match jobs of line', $failure->logContext($project->getId()->toRfc4122()));

        return $failure;
    }

    /**
     * @param list<Uuid|int|null> $characterIds
     *
     * @return array{lines: list<array{0: IndustryProjectBomLine, 1: int, 2: list<CachedIndustryJob>}>, failures: list<LineMatchFailure>}
     */
    private function candidatesOf(IndustryProject $project, array $characterIds): array
    {
        $lines = [];
        $failures = [];
        foreach ($this->recomputableJobLinesOf($project) as $line) {
            $runs = $line->getRuns();
            if ($runs === null) {
                $failures[] = LineMatchFailure::noRuns($line);
                continue;
            }

            try {
                $producer = $this->productRepository->producerOf($line->getTypeId());
            } catch (ProducerMissingFromSdeException) {
                $failures[] = LineMatchFailure::producerMissingFromSde($line);
                continue;
            }

            $lines[] = [
                $line,
                $runs,
                array_values(array_filter(
                    $this->jobRepository->findManufacturingJobsByBlueprint(
                        $producer->getTypeId(),
                        $characterIds,
                        null,
                        $project->getCreatedAt(),
                    ),
                    static fn (CachedIndustryJob $job): bool => !$job->hasNoOutput(),
                )),
            ];
        }

        return ['lines' => $lines, 'failures' => $failures];
    }

    /**
     * @param list<array{0: IndustryProjectBomLine, 1: int, 2: list<CachedIndustryJob>}> $lines
     * @param array<int, true>                                                            $assignedJobIds
     *
     * @return list<array{0: IndustryProjectBomLine, 1: CachedIndustryJob}>
     */
    private function jobsFittingLines(array $lines, array &$assignedJobIds): array
    {
        $fits = [];
        foreach ($lines as [$line, $remainingRuns, $jobs]) {
            foreach ($jobs as $job) {
                if ($remainingRuns <= 0) {
                    break;
                }

                if (isset($assignedJobIds[$job->getJobId()]) || $job->getRuns() > $remainingRuns) {
                    continue;
                }

                $assignedJobIds[$job->getJobId()] = true;
                $remainingRuns -= $job->getRuns();
                $fits[] = [$line, $job];
            }
        }

        return $fits;
    }

    /**
     * @return array<int, true>
     */
    private function unavailableJobIdsOf(IndustryProject $project): array
    {
        $jobLines = $project->getBomItems()->filter(
            static fn (IndustryProjectBomLine $line): bool => $line->isManufacturingOrReactionJob(),
        );

        return array_fill_keys([
            ...$this->jobMatchRepository->findEsiJobIdsMatchedToOtherProjects($project),
            ...$this->jobIdsKeptByManualLines($jobLines->toArray()),
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
            static fn (IndustryProjectBomLine $line): bool => $line->isManufacturingOrReactionJob() && !$line->hasManualJobMatch(),
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
    private function jobIdsKeptByManualLines(array $lines): array
    {
        $jobIds = [];
        foreach ($lines as $line) {
            if (!$line->hasManualJobMatch()) {
                continue;
            }
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

    private function createJobMatch(CachedIndustryJob $job, IndustryProjectBomLine $line, bool $manual): IndustryProjectJobMatch
    {
        $match = (new IndustryProjectJobMatch())
            ->setEsiJobId($job->getJobId())
            ->copyJobState($job)
            ->setCharacterName($job->getCharacter()->getName())
            ->setManual($manual);

        $stationId = $job->getStationId();
        if ($stationId !== null) {
            $match->setFacilityId($stationId);
            $match->setFacilityName($this->calculationService->resolveFacilityName($stationId));
        }

        return $match;
    }
}
