<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeExpiredSharedLists;
use App\Repository\SharedShoppingListRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurgeExpiredSharedListsHandler
{
    public function __construct(
        private SharedShoppingListRepository $sharedShoppingListRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeExpiredSharedLists $message): void
    {
        $deleted = $this->sharedShoppingListRepository->deleteExpired();

        $this->logger->info('Expired shared lists purge completed', [
            'deleted' => $deleted,
        ]);
    }
}
