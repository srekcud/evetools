<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class DbalSyncRunLog implements SyncRunLog
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function begin(string $type, \DateTimeImmutable $startedAt): void
    {
    }

    public function finish(string $type, string $status, ?string $message, \DateTimeImmutable $completedAt): void
    {
    }

    public function recentRunsByType(int $perType): array
    {
        return [];
    }

    public function finishedRunCountsSince(\DateTimeImmutable $since): array
    {
        return [];
    }

    public function deleteStartedBefore(\DateTimeImmutable $limit): int
    {
        return 0;
    }
}
