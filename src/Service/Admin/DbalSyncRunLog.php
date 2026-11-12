<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DbalSyncRunLog implements SyncRunLog
{
    private const string DATABASE_DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function begin(string $type, \DateTimeImmutable $startedAt): void
    {
        try {
            $this->insert($type, $startedAt, self::STATUS_RUNNING, null, null);
        } catch (\Throwable $exception) {
            $this->logger->error('Sync run start could not be recorded', ['type' => $type, 'error' => $exception->getMessage()]);
        }
    }

    public function finish(string $type, string $status, ?string $message, \DateTimeImmutable $completedAt): void
    {
        $message = $message === null ? null : mb_substr($message, 0, self::MESSAGE_MAX_LENGTH);

        try {
            $updated = $this->connection->executeStatement(
                'UPDATE sync_runs SET completed_at = :completedAt, status = :status, message = :message
                 WHERE id = (SELECT id FROM sync_runs WHERE type = :type AND status = :running ORDER BY started_at DESC, id DESC LIMIT 1)',
                [
                    'completedAt' => $completedAt->format(self::DATABASE_DATE_FORMAT),
                    'status' => $status,
                    'message' => $message,
                    'type' => $type,
                    'running' => self::STATUS_RUNNING,
                ],
            );

            if ($updated === 0) {
                $this->insert($type, $completedAt, $status, $completedAt, $message);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Sync run end could not be recorded', ['type' => $type, 'status' => $status, 'error' => $exception->getMessage()]);
        }
    }

    public function recentRunsByType(int $perType): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, type, started_at, completed_at, status, message FROM (
                 SELECT id, type, started_at, completed_at, status, message,
                        ROW_NUMBER() OVER (PARTITION BY type ORDER BY started_at DESC, id DESC) AS position
                 FROM sync_runs
             ) ranked
             WHERE position <= :perType
             ORDER BY type, started_at DESC, id DESC',
            ['perType' => $perType],
        );

        $runsByType = [];
        foreach ($rows as $row) {
            $runsByType[$row['type']][] = [
                'started_at' => (new \DateTimeImmutable($row['started_at']))->format('c'),
                'completed_at' => $row['completed_at'] === null ? null : (new \DateTimeImmutable($row['completed_at']))->format('c'),
                'status' => $row['status'],
                'message' => $row['message'],
            ];
        }

        return $runsByType;
    }

    public function finishedRunCountsSince(\DateTimeImmutable $since): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT type, status, COUNT(*) AS runs FROM sync_runs
             WHERE started_at >= :since AND status IN (:ok, :error)
             GROUP BY type, status',
            ['since' => $since->format(self::DATABASE_DATE_FORMAT), 'ok' => self::STATUS_OK, 'error' => self::STATUS_ERROR],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['type']] ??= ['ok' => 0, 'error' => 0];
            $key = $row['status'] === self::STATUS_OK ? 'ok' : 'error';
            $counts[$row['type']][$key] = (int) $row['runs'];
        }

        return $counts;
    }

    public function deleteStartedBefore(\DateTimeImmutable $limit): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM sync_runs WHERE started_at < :limit',
            ['limit' => $limit->format(self::DATABASE_DATE_FORMAT)],
        );
    }

    private function insert(string $type, \DateTimeImmutable $startedAt, string $status, ?\DateTimeImmutable $completedAt, ?string $message): void
    {
        $this->connection->executeStatement(
            'INSERT INTO sync_runs (id, type, started_at, completed_at, status, message)
             VALUES (:id, :type, :startedAt, :completedAt, :status, :message)',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'type' => $type,
                'startedAt' => $startedAt->format(self::DATABASE_DATE_FORMAT),
                'completedAt' => $completedAt?->format(self::DATABASE_DATE_FORMAT),
                'status' => $status,
                'message' => $message,
            ],
        );
    }
}
