<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncUserPveData;
use App\Repository\UserRepository;
use App\Service\Sync\PveSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncUserPveDataHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private PveSyncService $pveSyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncUserPveData $message): void
    {
        $user = $this->userRepository->find($message->userId);

        if ($user === null) {
            $this->logger->warning('User not found for PVE sync', ['userId' => $message->userId]);
            return;
        }

        if (!$this->pveSyncService->canSync($user)) {
            $this->logger->info('User cannot sync PVE data (no valid tokens)', ['userId' => $message->userId]);
            return;
        }

        $this->logger->info('Starting PVE sync for user', ['userId' => $message->userId]);

        $results = $this->pveSyncService->syncAll($user);

        $this->logger->info('PVE sync completed', [
            'userId' => $message->userId,
            'bounties' => $results['bounties'],
            'lootSales' => $results['lootSales'],
            'expenses' => $results['expenses'],
            'errors' => $results['errors'],
        ]);
    }
}
