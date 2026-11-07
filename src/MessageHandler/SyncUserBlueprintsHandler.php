<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncUserBlueprints;
use App\Repository\UserRepository;
use App\Service\Sync\BlueprintSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

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
    }
}
