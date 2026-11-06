<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Enum\IndustryActivityType;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use Doctrine\ORM\EntityManagerInterface;

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
    ) {
    }

    public function matchEsiJobs(IndustryProject $project): void
    {
        $characterIds = [];
        foreach ($project->getOwner()->getCharacters() as $character) {
            $characterIds[] = $character->getId();
        }

        if ($characterIds === []) {
            return;
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

        foreach ($recomputableLines as $line) {
            $jobs = array_filter(
                $this->jobRepository->findManufacturingJobsByBlueprint(
                    $this->productRepository->producerOf($line->getTypeId())->getTypeId(),
                    $characterIds,
                    null,
                    $project->getCreatedAt(),
                ),
                static fn (CachedIndustryJob $job): bool => !$job->hasNoOutput(),
            );

            $this->matchJobsToLine($line, $jobs, $assignedJobIds);
        }

        $this->entityManager->flush();
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

    /**
     * @param CachedIndustryJob[] $jobs
     * @param array<int, true>    $assignedJobIds
     */
    private function matchJobsToLine(IndustryProjectBomLine $line, array $jobs, array &$assignedJobIds): void
    {
        $remainingRuns = $line->getRuns() ?? throw new \LogicException(\sprintf('BOM line of type %d has no runs', $line->getTypeId()));

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
