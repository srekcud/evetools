<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class IndustryJobSyncService
{
    /** @var array<int, bool> Track which corporations we've already synced */
    private array $syncedCorporations = [];

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly CachedIndustryJobRepository $jobRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function syncCharacterJobs(Character $character): void
    {
        $token = $character->getEveToken();
        if ($token === null) {
            return;
        }

        $userId = $character->getUser()?->getId()?->toRfc4122();

        // Notify sync started
        if ($userId !== null) {
            $this->mercurePublisher->syncStarted($userId, 'industry-jobs', 'Récupération des jobs industrie...');
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

        // Update progress
        if ($userId !== null) {
            $this->mercurePublisher->syncProgress($userId, 'industry-jobs', 30, 'Jobs personnels récupérés...');
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

        // Update progress
        if ($userId !== null) {
            $this->mercurePublisher->syncProgress($userId, 'industry-jobs', 60, sprintf('Traitement de %d jobs...', count($allJobs)));
        }

        // Deduplicate by job_id (in case a personal job is also in corp jobs)
        $jobsByJobId = [];
        foreach ($allJobs as $jobData) {
            $jobsByJobId[$jobData['job_id']] = $jobData;
        }

        // Track newly completed jobs for notifications
        $newlyCompletedJobs = [];

        foreach ($jobsByJobId as $jobData) {
            $existing = $this->jobRepository->findByJobId($jobData['job_id']);

            if ($existing !== null) {
                // Check if job just completed (was active, now ready)
                $wasActive = $existing->getStatus() === 'active';
                $isNowReady = $jobData['status'] === 'ready';

                // Update existing job
                $existing->setStatus($jobData['status']);
                if (isset($jobData['completed_date'])) {
                    $existing->setCompletedDate(new \DateTimeImmutable($jobData['completed_date']));
                }
                $existing->setCachedAt(new \DateTimeImmutable());

                // Track newly ready jobs
                if ($wasActive && $isNowReady) {
                    $newlyCompletedJobs[] = $jobData;
                }
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

        // Count active jobs
        $activeJobs = array_filter($jobsByJobId, fn($j) => $j['status'] === 'active');
        $readyJobs = array_filter($jobsByJobId, fn($j) => $j['status'] === 'ready');

        // Notify sync completed
        if ($userId !== null) {
            $message = sprintf('%d jobs actifs, %d prêts à livrer', count($activeJobs), count($readyJobs));
            $this->mercurePublisher->syncCompleted($userId, 'industry-jobs', $message, [
                'total' => count($jobsByJobId),
                'active' => count($activeJobs),
                'ready' => count($readyJobs),
            ]);

            // Send individual notifications for newly completed jobs
            foreach ($newlyCompletedJobs as $jobData) {
                $productName = $this->getTypeName($jobData['product_type_id'] ?? $jobData['blueprint_type_id']);
                $activityName = $this->getActivityName($jobData['activity_id']);
                $this->mercurePublisher->publishSyncProgress(
                    $userId,
                    'industry-job-completed',
                    'notification',
                    null,
                    sprintf('%s - %s terminé !', $productName, $activityName),
                    [
                        'jobId' => $jobData['job_id'],
                        'productTypeId' => $jobData['product_type_id'] ?? $jobData['blueprint_type_id'],
                        'productName' => $productName,
                        'runs' => $jobData['runs'],
                        'activityId' => $jobData['activity_id'],
                    ]
                );
            }
        }
    }

    private function getTypeName(int $typeId): string
    {
        $type = $this->invTypeRepository->find($typeId);
        return $type?->getTypeName() ?? "Type #{$typeId}";
    }

    private function getActivityName(int $activityId): string
    {
        return match ($activityId) {
            1 => 'Manufacturing',
            3 => 'TE Research',
            4 => 'ME Research',
            5 => 'Copying',
            7 => 'Reverse Engineering',
            8 => 'Invention',
            9 => 'Reactions',
            11 => 'Reactions',
            default => "Activity #{$activityId}",
        };
    }

    /**
     * Reset the corporation sync tracking (call this at the start of a full sync)
     */
    public function resetCorporationTracking(): void
    {
        $this->syncedCorporations = [];
    }
}
