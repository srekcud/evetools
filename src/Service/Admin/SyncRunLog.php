<?php

declare(strict_types=1);

namespace App\Service\Admin;

interface SyncRunLog
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';
    public const MESSAGE_MAX_LENGTH = 500;

    public function begin(string $type, \DateTimeImmutable $startedAt): void;

    public function finish(string $type, string $status, ?string $message, \DateTimeImmutable $completedAt): void;

    /** @return array<string, list<array{started_at: string, completed_at: ?string, status: string, message: ?string}>> newest first, at most $perType per type */
    public function recentRunsByType(int $perType): array;

    /** @return array<string, array{ok: int, error: int}> finished runs started at or after $since, by type */
    public function finishedRunCountsSince(\DateTimeImmutable $since): array;

    public function deleteStartedBefore(\DateTimeImmutable $limit): int;
}
