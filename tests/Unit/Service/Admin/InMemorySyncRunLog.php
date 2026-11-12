<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\SyncRunLog;

final class InMemorySyncRunLog implements SyncRunLog
{
    /** @var list<array{type: string, started_at: \DateTimeImmutable, completed_at: ?\DateTimeImmutable, status: string, message: ?string}> */
    private array $runs = [];

    public function seed(string $type, \DateTimeImmutable $startedAt, string $status, ?\DateTimeImmutable $completedAt = null, ?string $message = null): void
    {
        $this->runs[] = ['type' => $type, 'started_at' => $startedAt, 'completed_at' => $completedAt, 'status' => $status, 'message' => $message];
    }

    public function begin(string $type, \DateTimeImmutable $startedAt): void
    {
        $this->seed($type, $startedAt, self::STATUS_RUNNING);
    }

    public function finish(string $type, string $status, ?string $message, \DateTimeImmutable $completedAt): void
    {
        $message = $message === null ? null : mb_substr($message, 0, self::MESSAGE_MAX_LENGTH);
        $latestRunning = null;
        foreach ($this->runs as $index => $run) {
            if ($run['type'] !== $type || $run['status'] !== self::STATUS_RUNNING) {
                continue;
            }
            if ($latestRunning === null || $run['started_at'] >= $this->runs[$latestRunning]['started_at']) {
                $latestRunning = $index;
            }
        }

        if ($latestRunning === null) {
            $this->seed($type, $completedAt, $status, $completedAt, $message);

            return;
        }

        $this->runs[$latestRunning]['completed_at'] = $completedAt;
        $this->runs[$latestRunning]['status'] = $status;
        $this->runs[$latestRunning]['message'] = $message;
    }

    public function recentRunsByType(int $perType): array
    {
        $byType = [];
        foreach ($this->runs as $run) {
            $byType[$run['type']][] = $run;
        }

        $result = [];
        foreach ($byType as $type => $runs) {
            usort($runs, static fn (array $left, array $right): int => $right['started_at'] <=> $left['started_at']);
            $result[$type] = array_map(
                static fn (array $run): array => [
                    'started_at' => $run['started_at']->format('c'),
                    'completed_at' => $run['completed_at']?->format('c'),
                    'status' => $run['status'],
                    'message' => $run['message'],
                ],
                array_slice($runs, 0, $perType),
            );
        }

        return $result;
    }

    public function finishedRunCountsSince(\DateTimeImmutable $since): array
    {
        $counts = [];
        foreach ($this->runs as $run) {
            if ($run['status'] === self::STATUS_RUNNING || $run['started_at'] < $since) {
                continue;
            }
            $counts[$run['type']] ??= ['ok' => 0, 'error' => 0];
            ++$counts[$run['type']][$run['status']];
        }

        return $counts;
    }

    public function deleteStartedBefore(\DateTimeImmutable $limit): int
    {
        $before = count($this->runs);
        $this->runs = array_values(array_filter($this->runs, static fn (array $run): bool => $run['started_at'] >= $limit));

        return $before - count($this->runs);
    }

    /** @return list<array{type: string, started_at: \DateTimeImmutable, completed_at: ?\DateTimeImmutable, status: string, message: ?string}> */
    public function all(): array
    {
        return $this->runs;
    }
}
