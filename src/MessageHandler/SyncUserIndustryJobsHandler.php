<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncUserIndustryJobs;
use App\Repository\UserRepository;
use App\Service\Sync\IndustryJobSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SyncUserIndustryJobsHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private IndustryJobSyncService $industryJobSyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncUserIndustryJobs $message): void
    {
        $user = $this->userRepository->find(Uuid::fromString($message->userId));

        if ($user === null) {
            $this->logger->warning('User not found for industry jobs sync', ['userId' => $message->userId]);

            return;
        }

        $this->industryJobSyncService->syncUserJobs($user);
    }
}
