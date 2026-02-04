<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncUserMiningData;
use App\Repository\UserRepository;
use App\Service\Sync\MiningSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncUserMiningDataHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private MiningSyncService $miningSyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncUserMiningData $message): void
    {
        $user = $this->userRepository->find($message->userId);

        if ($user === null) {
            $this->logger->warning('User not found for mining sync', [
                'userId' => $message->userId,
            ]);
            return;
        }

        $this->logger->info('Starting mining sync for user', [
            'userId' => $message->userId,
        ]);

        try {
            $results = $this->miningSyncService->syncAll($user);

            $this->logger->info('Mining sync completed', [
                'userId' => $message->userId,
                'imported' => $results['imported'],
                'updated' => $results['updated'],
                'pricesUpdated' => $results['pricesUpdated'],
                'errors' => count($results['errors']),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Mining sync failed', [
                'userId' => $message->userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
