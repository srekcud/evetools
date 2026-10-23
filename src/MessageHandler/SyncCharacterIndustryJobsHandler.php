<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncCharacterIndustryJobs;
use App\Repository\CharacterRepository;
use App\Service\Sync\IndustryJobSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SyncCharacterIndustryJobsHandler
{
    public function __construct(
        private CharacterRepository $characterRepository,
        private IndustryJobSyncService $industryJobSyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncCharacterIndustryJobs $message): void
    {
        $character = $this->characterRepository->find(Uuid::fromString($message->characterId));

        if ($character === null) {
            $this->logger->warning('Character not found for industry jobs sync', ['characterId' => $message->characterId]);

            return;
        }

        $this->industryJobSyncService->syncCharacterJobs($character);
    }
}
