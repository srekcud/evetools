<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeOldNotifications;
use App\Repository\NotificationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurgeOldNotificationsHandler
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeOldNotifications $message): void
    {
        $this->logger->info('Purging notifications older than 7 days');

        try {
            $deleted = $this->notificationRepository->deleteOlderThan(new \DateTimeImmutable('-7 days'));

            $this->logger->info('Notification purge completed', [
                'deleted' => $deleted,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Notification purge failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
