<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncUserPveData;
use App\Message\TriggerPveSync;
use App\Repository\UserRepository;
use App\Service\Admin\SyncTracker;
use App\Service\Sync\PveSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TriggerPveSyncHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private PveSyncService $pveSyncService,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(TriggerPveSync $message): void
    {
        $this->syncTracker->start('pve');
        $this->logger->info('Triggering scheduled PVE sync');

        try {
            $users = $this->userRepository->findActiveWithCharacters();
            $syncCount = 0;

            foreach ($users as $user) {
                $userId = $user->getId()?->toRfc4122();
                if ($userId !== null && $this->pveSyncService->canSync($user) && $this->pveSyncService->shouldSync($user)) {
                    $this->messageBus->dispatch(
                        new SyncUserPveData($userId)
                    );
                    $syncCount++;
                }
            }

            $this->logger->info('Scheduled PVE sync triggered', [
                'usersQueued' => $syncCount,
                'totalUsers' => count($users),
            ]);

            $this->syncTracker->complete('pve', "{$syncCount}/" . count($users) . ' users queued');
        } catch (\Throwable $e) {
            $this->syncTracker->fail('pve', $e->getMessage());
            throw $e;
        }
    }
}
