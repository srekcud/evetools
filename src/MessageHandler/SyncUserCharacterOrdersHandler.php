<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncUserCharacterOrders;
use App\Repository\UserRepository;
use App\Service\Sync\CharacterOrderSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SyncUserCharacterOrdersHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private CharacterOrderSyncService $orderSyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncUserCharacterOrders $message): void
    {
        $user = $this->userRepository->find(Uuid::fromString($message->userId));

        if ($user === null) {
            $this->logger->warning('User not found for character orders sync', ['userId' => $message->userId]);

            return;
        }

        $this->orderSyncService->syncUserOrders($user);
    }
}
