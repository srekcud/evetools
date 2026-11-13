<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Service\Mercure\MercurePublisherService;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class SyncTracker
{
    private const CACHE_PREFIX = 'sync_tracker_';
    private const TRIGGERED_PREFIX = 'sync_triggered_';
    private const CACHE_TTL = 86400 * 7; // 7 days
    private const TRIGGERED_TTL = 3600; // 1h max

    /** Must match the recurring messages of App\Scheduler\SyncScheduler. */
    public const EXPECTED_INTERVALS = [
        'industry' => 1800,         // 30 min
        'pve' => 3600,              // 1h
        'wallet' => 300,            // 1h
        'mining' => 3600,           // 1h
        'ansiblex' => 43200,        // 12h
        'planetary' => 1800,        // 30 min
        'market-jita' => 3600,      // 1h
        'market-structure' => 3600, // 1h
        'alert-prices' => 1800,     // 30 min
        'adjusted-prices' => 86400, // 24h
        'cost-indices' => 7200,     // 2h
        'public-contracts' => 1800, // 30 min
        'corporation-mining-extractions' => 1800,
        'moon-tax' => 3600,
        'blueprints' => 3600,
        'market-volumes' => 86400,
        'courier-contracts' => 1800,
        'character-orders' => 300,
        'mining-operation-recurrences' => 1800,
        'moon-chunk-alerts' => 300,
        'mining-site-depletion-alerts' => 300,
        'notifications-purge' => 86400,
        'market-history-purge' => 86400,
        'shared-lists-purge' => 86400,
        'sde-build' => 86400,
    ];

    /** Not scheduled, only triggered manually: their last run is shown but no health is computed. */
    public const ON_DEMAND = [
        'assets',
        'corporation-assets',
        'market-alerts',
    ];

    private const ON_DEMAND_HEALTH = 'on-demand';
    private const STUCK_RUNNING_INTERVAL_RATIO = 2;
    private const ON_DEMAND_STUCK_RUNNING_SECONDS = 3600;
    private const SECONDS_PER_MINUTE = 60;
    private const RUN_HISTORY_SIZE = 20;
    private const SUCCESS_RATE_WINDOW = '-24 hours';
    private const PERCENT = 100;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly MercurePublisherService $mercurePublisher,
        private readonly SyncRunLog $runLog,
    ) {
    }

    public function start(string $type): void
    {
        $state = $this->getState($type);
        $startedAt = new \DateTimeImmutable();

        $this->saveState($type, [
            'started_at' => $startedAt->format('c'),
            'completed_at' => $state['completed_at'] ?? null,
            'last_success_at' => $state['last_success_at'] ?? null,
            'status' => 'running',
            'message' => null,
        ]);
        $this->runLog->begin($type, $startedAt);

        $this->notifyTriggeredBy($type, 'started', 'Sync in progress...');
    }

    public function complete(string $type, ?string $message = null): void
    {
        $nowAt = new \DateTimeImmutable();
        $now = $nowAt->format('c');
        $state = $this->getState($type);

        $this->saveState($type, [
            'started_at' => $state['started_at'] ?? $now,
            'completed_at' => $now,
            'last_success_at' => $now,
            'status' => 'ok',
            'message' => $message,
        ]);
        $this->runLog->finish($type, SyncRunLog::STATUS_OK, $message, $nowAt);

        $this->notifyTriggeredBy($type, 'completed', $message);
    }

    public function fail(string $type, string $message): void
    {
        $nowAt = new \DateTimeImmutable();
        $now = $nowAt->format('c');
        $state = $this->getState($type);

        $this->saveState($type, [
            'started_at' => $state['started_at'] ?? $now,
            'completed_at' => $now,
            'last_success_at' => $state['last_success_at'] ?? null,
            'status' => 'error',
            'message' => $message,
        ]);
        $this->runLog->finish($type, SyncRunLog::STATUS_ERROR, $message, $nowAt);

        $this->notifyTriggeredBy($type, 'error', $message);
    }

    /**
     * Store which admin user triggered a manual sync.
     */
    public function setTriggeredBy(string $type, string $userId): void
    {
        $key = self::TRIGGERED_PREFIX . str_replace('-', '_', $type);

        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($userId): string {
            $item->expiresAfter(self::TRIGGERED_TTL);
            return $userId;
        });
    }

    /** @return list<array<string, mixed>> */
    public function getAll(): array
    {
        $result = [];
        $runsByType = $this->runLog->recentRunsByType(self::RUN_HISTORY_SIZE);
        $finishedCounts = $this->runLog->finishedRunCountsSince(new \DateTimeImmutable(self::SUCCESS_RATE_WINDOW));

        foreach (self::EXPECTED_INTERVALS as $type => $interval) {
            $stuckAfter = $interval * self::STUCK_RUNNING_INTERVAL_RATIO;
            $state = $this->reportStuckRunning($this->getState($type), $stuckAfter);
            $result[] = $this->buildEntry(
                $type,
                $state,
                $this->computeHealth($state, $interval),
                $interval,
                $this->presentRuns($runsByType[$type] ?? [], $stuckAfter),
                $this->successRate($finishedCounts[$type] ?? null),
            );
        }

        foreach (self::ON_DEMAND as $type) {
            $state = $this->reportStuckRunning($this->getState($type), self::ON_DEMAND_STUCK_RUNNING_SECONDS);
            $result[] = $this->buildEntry(
                $type,
                $state,
                self::ON_DEMAND_HEALTH,
                null,
                $this->presentRuns($runsByType[$type] ?? [], self::ON_DEMAND_STUCK_RUNNING_SECONDS),
                $this->successRate($finishedCounts[$type] ?? null),
            );
        }

        return $result;
    }

    /** @return array{lastSuccessAt: ?string, lastRunFailed: bool} */
    public function getFreshness(string $type): array
    {
        $state = $this->getState($type);

        return [
            'lastSuccessAt' => $state['last_success_at'] ?? $this->completedAtOfLegacySuccess($state),
            'lastRunFailed' => $state['status'] === 'error',
        ];
    }

    /** @param array<string, mixed> $state */
    private function completedAtOfLegacySuccess(array $state): ?string
    {
        return $state['status'] === 'ok' ? $state['completed_at'] : null;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function reportStuckRunning(array $state, int $maxRunningSeconds): array
    {
        $startedAt = $state['started_at'] ?? null;
        if ($state['status'] !== 'running' || $startedAt === null) {
            return $state;
        }

        $runningSeconds = time() - (new \DateTimeImmutable($startedAt))->getTimestamp();
        if ($runningSeconds <= $maxRunningSeconds) {
            return $state;
        }

        $state['status'] = 'error';
        $state['stuck'] = true;
        $state['message'] = $this->stuckMessage($runningSeconds);

        return $state;
    }

    private function stuckMessage(int $runningSeconds): string
    {
        return sprintf(
            'Stuck: still running after %d min without completion',
            intdiv($runningSeconds, self::SECONDS_PER_MINUTE),
        );
    }

    /**
     * @param list<array{started_at: string, completed_at: ?string, status: string, message: ?string}> $runs
     *
     * @return list<array{started_at: string, completed_at: ?string, status: string, message: ?string, stuck: bool}>
     */
    private function presentRuns(array $runs, int $maxRunningSeconds): array
    {
        return array_map(function (array $run) use ($maxRunningSeconds): array {
            $runningSeconds = time() - (new \DateTimeImmutable($run['started_at']))->getTimestamp();
            $stuck = $run['status'] === SyncRunLog::STATUS_RUNNING && $runningSeconds > $maxRunningSeconds;

            return [
                'started_at' => $run['started_at'],
                'completed_at' => $run['completed_at'],
                'status' => $stuck ? SyncRunLog::STATUS_ERROR : $run['status'],
                'message' => $stuck ? $this->stuckMessage($runningSeconds) : $run['message'],
                'stuck' => $stuck,
            ];
        }, $runs);
    }

    /** @param array{ok: int, error: int}|null $finishedCounts */
    private function successRate(?array $finishedCounts): ?int
    {
        $finished = $finishedCounts === null ? 0 : $finishedCounts['ok'] + $finishedCounts['error'];
        if ($finishedCounts === null || $finished === 0) {
            return null;
        }

        return (int) round($finishedCounts['ok'] * self::PERCENT / $finished);
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array<string, mixed>> $runs
     *
     * @return array<string, mixed>
     */
    private function buildEntry(string $type, array $state, string $health, ?int $expectedInterval, array $runs, ?int $successRate24h): array
    {
        return [
            'type' => $type,
            'status' => $state['status'] ?? 'unknown',
            'health' => $health,
            'started_at' => $state['started_at'] ?? null,
            'completed_at' => $state['completed_at'] ?? null,
            'message' => $state['message'] ?? null,
            'expected_interval' => $expectedInterval,
            'on_demand' => $expectedInterval === null,
            'triggerable' => TriggerableSyncs::isTriggerable($type),
            'runs' => $runs,
            'success_rate_24h' => $successRate24h,
        ];
    }

    /** @param array<string, mixed> $state */
    private function computeHealth(array $state, int $expectedInterval): string
    {
        if (($state['stuck'] ?? false) === true) {
            return 'stale';
        }

        if ($state['status'] === 'running') {
            return 'running';
        }

        $completedAt = $state['completed_at'] ?? null;
        if ($completedAt === null) {
            return 'unknown';
        }

        $elapsed = time() - (new \DateTimeImmutable($completedAt))->getTimestamp();

        if ($elapsed <= $expectedInterval * 1.5) {
            return 'healthy';
        }

        if ($elapsed <= $expectedInterval * 3) {
            return 'late';
        }

        return 'stale';
    }

    /** @return array<string, mixed> */
    private function getState(string $type): array
    {
        $key = self::CACHE_PREFIX . str_replace('-', '_', $type);

        return $this->cache->get($key, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);
            return ['status' => 'unknown', 'started_at' => null, 'completed_at' => null, 'last_success_at' => null, 'message' => null];
        });
    }

    /** @param array<string, mixed> $state */
    private function saveState(string $type, array $state): void
    {
        $key = self::CACHE_PREFIX . str_replace('-', '_', $type);

        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($state): array {
            $item->expiresAfter(self::CACHE_TTL);
            return $state;
        });
    }

    private function notifyTriggeredBy(string $type, string $status, ?string $message): void
    {
        $key = self::TRIGGERED_PREFIX . str_replace('-', '_', $type);

        /** @var string|null $userId */
        $userId = $this->cache->get($key, function (ItemInterface $item): ?string {
            $item->expiresAfter(self::TRIGGERED_TTL);
            return null;
        });

        if ($userId === null) {
            return;
        }

        // Keep the trigger while the sync runs: the admin must also receive its completion or error.
        if ($status !== 'started') {
            $this->cache->delete($key);
        }

        $this->mercurePublisher->publishSyncProgress(
            $userId,
            'admin-sync',
            $status,
            $status === 'completed' ? 100 : null,
            $message,
            ['syncType' => $type],
        );
    }
}
