<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncUserBlueprints;
use App\Repository\UserRepository;
use App\Service\Sync\BlueprintSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SyncUserBlueprintsHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private BlueprintSyncService $blueprintSyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncUserBlueprints $message): void
    {
        $user = $this->userRepository->find(Uuid::fromString($message->userId));

        if ($user === null) {
            $this->logger->warning('User not found for blueprints sync', ['userId' => $message->userId]);

            return;
        }

        $this->blueprintSyncService->syncUserBlueprints($user);
    }
}
