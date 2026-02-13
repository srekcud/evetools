<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncUserMiningData;
use App\Message\TriggerMiningSync;
use App\Repository\UserRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Sync\MiningSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TriggerMiningSyncHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private MiningSyncService $miningSyncService,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(TriggerMiningSync $message): void
    {
        $this->syncTracker->start('mining');
        $this->logger->info('Triggering scheduled mining sync');

        try {
            $users = $this->userRepository->findActiveWithCharacters();
            $syncCount = 0;

            foreach ($users as $user) {
                if ($this->miningSyncService->canSync($user) && $this->miningSyncService->shouldSync($user)) {
                    $this->messageBus->dispatch(
                        new SyncUserMiningData($user->getId()->toRfc4122())
                    );
                    $syncCount++;
                }
            }

            $this->logger->info('Scheduled mining sync triggered', [
                'usersQueued' => $syncCount,
                'totalUsers' => count($users),
            ]);

            $this->syncTracker->complete('mining', "{$syncCount}/" . count($users) . ' users queued');
        } catch (\Throwable $e) {
            $this->syncTracker->fail('mining', $e->getMessage());
            throw $e;
        }
    }
}
