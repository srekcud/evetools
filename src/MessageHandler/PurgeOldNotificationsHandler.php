<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeOldNotifications;
use App\Repository\NotificationRepository;
use App\Service\Admin\SyncRunLog;
use App\Service\Admin\SyncTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurgeOldNotificationsHandler
{
    private const string SYNC_KEY = 'notifications-purge';

    public function __construct(
        private NotificationRepository $notificationRepository,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
        private SyncRunLog $syncRunLog,
    ) {
    }

    public function __invoke(PurgeOldNotifications $message): void
    {
        $this->syncTracker->start(self::SYNC_KEY);
        $this->logger->info('Purging notifications older than 7 days');

        try {
            $deleted = $this->notificationRepository->deleteOlderThan(new \DateTimeImmutable('-7 days'));

            $this->logger->info('Notification purge completed', [
                'deleted' => $deleted,
            ]);
            $this->syncTracker->complete(self::SYNC_KEY, sprintf('%d notification(s) deleted', $deleted));
        } catch (\Throwable $e) {
            $this->logger->error('Notification purge failed', [
                'error' => $e->getMessage(),
            ]);
            $this->syncTracker->fail(self::SYNC_KEY, $e->getMessage());
        }
    }
}
