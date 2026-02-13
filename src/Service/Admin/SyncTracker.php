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

    public const EXPECTED_INTERVALS = [
        'assets' => 1800,           // 30 min
        'industry' => 1800,         // 30 min
        'pve' => 3600,              // 1h
        'wallet' => 3600,           // 1h
        'mining' => 3600,           // 1h
        'ansiblex' => 43200,        // 12h
        'planetary' => 1800,        // 30 min
        'market-jita' => 7200,      // 2h
        'market-structure' => 7200, // 2h
    ];

    private const LABELS = [
        'assets' => 'Assets',
        'industry' => 'Jobs industrie',
        'pve' => 'PVE',
        'wallet' => 'Wallet',
        'mining' => 'Mining',
        'ansiblex' => 'Ansiblex',
        'planetary' => 'Planetary',
        'market-jita' => 'Market Jita',
        'market-structure' => 'Market Structure',
    ];

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function start(string $type): void
    {
        $this->saveState($type, [
            'started_at' => (new \DateTimeImmutable())->format('c'),
            'completed_at' => $this->getState($type)['completed_at'] ?? null,
            'status' => 'running',
            'message' => null,
        ]);

        $this->notifyTriggeredBy($type, 'started', 'Sync en cours...');
    }

    public function complete(string $type, ?string $message = null): void
    {
        $now = (new \DateTimeImmutable())->format('c');
        $state = $this->getState($type);

        $this->saveState($type, [
            'started_at' => $state['started_at'] ?? $now,
            'completed_at' => $now,
            'status' => 'ok',
            'message' => $message,
        ]);

        $this->notifyTriggeredBy($type, 'completed', $message);
    }

    public function fail(string $type, string $message): void
    {
        $now = (new \DateTimeImmutable())->format('c');
        $state = $this->getState($type);

        $this->saveState($type, [
            'started_at' => $state['started_at'] ?? $now,
            'completed_at' => $now,
            'status' => 'error',
            'message' => $message,
        ]);

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

    public function getAll(): array
    {
        $result = [];

        foreach (self::EXPECTED_INTERVALS as $type => $interval) {
            $state = $this->getState($type);
            $health = $this->computeHealth($state, $interval);

            $result[] = [
                'type' => $type,
                'label' => self::LABELS[$type],
                'status' => $state['status'] ?? 'unknown',
                'health' => $health,
                'started_at' => $state['started_at'] ?? null,
                'completed_at' => $state['completed_at'] ?? null,
                'message' => $state['message'] ?? null,
                'expected_interval' => $interval,
            ];
        }

        return $result;
    }

    private function computeHealth(array $state, int $expectedInterval): string
    {
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

    private function getState(string $type): array
    {
        $key = self::CACHE_PREFIX . str_replace('-', '_', $type);

        return $this->cache->get($key, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);
            return ['status' => 'unknown', 'started_at' => null, 'completed_at' => null, 'message' => null];
        });
    }

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

        // Clean up the trigger
        $this->cache->delete($key);

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
