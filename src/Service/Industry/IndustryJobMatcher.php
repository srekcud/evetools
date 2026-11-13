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
use App\Exception\ProducerMissingFromSdeException;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

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
     * @return list<LineMatchFailure>
     */
    public function matchEsiJobs(IndustryProject $project): array
    {
        $characterIds = [];
        foreach ($this->usersWhoseJobsAreMatched($project) as $user) {
            foreach ($user->getCharacters() as $character) {
                $characterIds[] = $character->getId();
            }
        }

        if ($characterIds === []) {
            return [];
        }

        $jobLines = $project->getBomItems()->filter(
            static fn (IndustryProjectBomLine $line): bool => $line->isManufacturingOrReactionJob(),
        );
        $recomputableLines = $jobLines->filter(
            static fn (IndustryProjectBomLine $line): bool => !$line->hasManualJobMatch(),
        );

        $this->clearAutomaticMatches($recomputableLines->toArray());
        $this->entityManager->flush();

        $assignedJobIds = array_fill_keys([
            ...$this->jobMatchRepository->findEsiJobIdsMatchedToOtherProjects($project),
            ...$this->jobIdsKeptByManualLines($jobLines->toArray()),
        ], true);

        $failures = [];
        foreach ($recomputableLines as $line) {
            $runs = $line->getRuns();
            if ($runs === null) {
                $failures[] = $this->reported($project, LineMatchFailure::noRuns($line));
                continue;
            }

            try {
                $producer = $this->productRepository->producerOf($line->getTypeId());
            } catch (ProducerMissingFromSdeException) {
                $failures[] = $this->reported($project, LineMatchFailure::producerMissingFromSde($line));
                continue;
            }

            $jobs = array_filter(
                $this->jobRepository->findManufacturingJobsByBlueprint(
                    $producer->getTypeId(),
                    $characterIds,
                    null,
                    $project->getCreatedAt(),
                ),
                static fn (CachedIndustryJob $job): bool => !$job->hasNoOutput(),
            );

            $this->matchJobsToLine($line, $runs, $jobs, $assignedJobIds);
        }

        $this->entityManager->flush();

        return $failures;
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
     * @param CachedIndustryJob[] $jobs
     * @param array<int, true>    $assignedJobIds
     */
    private function matchJobsToLine(IndustryProjectBomLine $line, int $remainingRuns, array $jobs, array &$assignedJobIds): void
    {
        foreach ($jobs as $job) {
            if ($remainingRuns <= 0) {
                break;
            }

            if (isset($assignedJobIds[$job->getJobId()]) || $job->getRuns() > $remainingRuns) {
                continue;
            }

            $assignedJobIds[$job->getJobId()] = true;
            $remainingRuns -= $job->getRuns();
            $line->addJobMatch($this->createJobMatch($job, $line, false));
        }

        if ($remainingRuns <= 0) {
            return;
        }

        foreach ($jobs as $job) {
            if (isset($assignedJobIds[$job->getJobId()]) || $job->getRuns() <= $remainingRuns) {
                continue;
            }

            $assignedJobIds[$job->getJobId()] = true;
            $line->addJobMatch($this->createJobMatch($job, $line, false));

            return;
        }
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
