<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncIndustryJobs;
use App\Repository\CharacterRepository;
use App\Service\Sync\IndustryJobSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncIndustryJobsHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private IndustryJobSyncService $industryJobSyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncIndustryJobs $message): void
    {
        $characters = $this->characterRepository->findAll();

        foreach ($characters as $character) {
            $token = $character->getEveToken();
            if ($token === null || !$token->hasScope('esi-industry.read_character_jobs.v1')) {
                continue;
            }

            $user = $character->getUser();
            if ($user === null || !$user->isAuthValid()) {
                continue;
            }

            try {
                $this->industryJobSyncService->syncCharacterJobs($character);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to sync industry jobs', [
                    'characterName' => $character->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
