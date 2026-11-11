<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeExpiredSharedLists;
use App\Repository\SharedShoppingListRepository;
use App\Service\Admin\SyncTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurgeExpiredSharedListsHandler
{
    private const string SYNC_KEY = 'shared-lists-purge';

    public function __construct(
        private SharedShoppingListRepository $sharedShoppingListRepository,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(PurgeExpiredSharedLists $message): void
    {
        $this->syncTracker->start(self::SYNC_KEY);

        try {
            $deleted = $this->sharedShoppingListRepository->deleteExpired();
        } catch (\Throwable $failure) {
            $this->syncTracker->fail(self::SYNC_KEY, $failure->getMessage());

            throw $failure;
        }

        $this->logger->info('Expired shared lists purge completed', [
            'deleted' => $deleted,
        ]);
        $this->syncTracker->complete(self::SYNC_KEY, sprintf('%d list(s) deleted', $deleted));
    }
}
