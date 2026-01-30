<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Repository\CachedIndustryJobRepository;
use App\Service\ESI\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class IndustryJobSyncService
{
    /** @var array<int, bool> Track which corporations we've already synced */
    private array $syncedCorporations = [];

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly CachedIndustryJobRepository $jobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function syncCharacterJobs(Character $character): void
    {
        $token = $character->getEveToken();
        if ($token === null) {
            return;
        }

        $allJobs = [];

        // Sync personal jobs
        if ($token->hasScope('esi-industry.read_character_jobs.v1')) {
            $characterId = $character->getEveCharacterId();
            $personalJobs = $this->esiClient->get(
                "/characters/{$characterId}/industry/jobs/?include_completed=true",
                $token,
            );
            $allJobs = array_merge($allJobs, $personalJobs);

        }

        // Sync corporation jobs (only once per corporation per sync run)
        $corporationId = $character->getCorporationId();
        if (
            $corporationId !== null
            && $token->hasScope('esi-industry.read_corporation_jobs.v1')
            && !isset($this->syncedCorporations[$corporationId])
        ) {
            try {
                $corpJobs = $this->esiClient->getPaginated(
                    "/corporations/{$corporationId}/industry/jobs/?include_completed=true",
                    $token,
                );
                $allJobs = array_merge($allJobs, $corpJobs);
                $this->syncedCorporations[$corporationId] = true;
            } catch (\Throwable $e) {
                // May fail if character doesn't have the required corp roles
                $this->logger->debug('Could not fetch corporation jobs', [
                    'characterName' => $character->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Deduplicate by job_id (in case a personal job is also in corp jobs)
        $jobsByJobId = [];
        foreach ($allJobs as $jobData) {
            $jobsByJobId[$jobData['job_id']] = $jobData;
        }

        foreach ($jobsByJobId as $jobData) {
            $existing = $this->jobRepository->findByJobId($jobData['job_id']);

            if ($existing !== null) {
                // Update existing job
                $existing->setStatus($jobData['status']);
                if (isset($jobData['completed_date'])) {
                    $existing->setCompletedDate(new \DateTimeImmutable($jobData['completed_date']));
                }
                $existing->setCachedAt(new \DateTimeImmutable());
                continue;
            }

            $job = new CachedIndustryJob();
            $job->setCharacter($character);
            $job->setJobId($jobData['job_id']);
            $job->setActivityId($jobData['activity_id']);
            $job->setBlueprintTypeId($jobData['blueprint_type_id']);
            $job->setProductTypeId($jobData['product_type_id'] ?? $jobData['blueprint_type_id']);
            $job->setRuns($jobData['runs']);
            $job->setCost((float) ($jobData['cost'] ?? 0));
            $job->setStatus($jobData['status']);
            $job->setStartDate(new \DateTimeImmutable($jobData['start_date']));
            $job->setEndDate(new \DateTimeImmutable($jobData['end_date']));
            if (isset($jobData['completed_date'])) {
                $job->setCompletedDate(new \DateTimeImmutable($jobData['completed_date']));
            }

            $this->entityManager->persist($job);
        }

        $this->entityManager->flush();

        $this->logger->info('Industry jobs synced', [
            'characterName' => $character->getName(),
            'jobCount' => count($jobsByJobId),
        ]);
    }

    /**
     * Reset the corporation sync tracking (call this at the start of a full sync)
     */
    public function resetCorporationTracking(): void
    {
        $this->syncedCorporations = [];
    }
}
