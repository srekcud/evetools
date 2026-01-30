<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncUserPveData;
use App\Message\TriggerPveSync;
use App\Repository\UserRepository;
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
    ) {
    }

    public function __invoke(TriggerPveSync $message): void
    {
        $this->logger->info('Triggering scheduled PVE sync');

        $users = $this->userRepository->findAll();
        $syncCount = 0;

        foreach ($users as $user) {
            if ($this->pveSyncService->canSync($user) && $this->pveSyncService->shouldSync($user)) {
                $this->messageBus->dispatch(
                    new SyncUserPveData($user->getId()->toRfc4122())
                );
                $syncCount++;
            }
        }

        $this->logger->info('Scheduled PVE sync triggered', [
            'usersQueued' => $syncCount,
            'totalUsers' => count($users),
        ]);
    }
}
