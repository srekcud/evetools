<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Enum\AdminHealthScope;
use App\Service\Mercure\MercurePublisherService;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

final class EsiErrorBudgetBroadcaster
{
    public const int THROTTLE_SECONDS = 10;

    private const string LAST_PUBLICATION_KEY = 'esi_error_budget_broadcast_at';
    private const string LAST_LEVEL_KEY = 'esi_error_budget_broadcast_level';
    private const int PUBLICATION_TTL_SECONDS = 3600;
    private const int LEVEL_TTL_SECONDS = 24 * 3600;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly MercurePublisherService $publisher,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function limitedResponseRecorded(): void
    {
        try {
            $item = $this->cache->getItem(self::LAST_PUBLICATION_KEY);
            $now = $this->clock->now()->getTimestamp();
            if ($item->isHit() && $now - (int) $item->get() < self::THROTTLE_SECONDS) {
                return;
            }

            $this->save($item, $now, self::PUBLICATION_TTL_SECONDS);
        } catch (\Throwable $e) {
            $this->warnAboutCache($e);

            return;
        }

        $this->publisher->publishAdminHealth(AdminHealthScope::EsiErrorBudget);
    }

    public function levelObserved(string $level): void
    {
        try {
            $levelItem = $this->cache->getItem(self::LAST_LEVEL_KEY);
            if ($levelItem->isHit() && $levelItem->get() === $level) {
                return;
            }

            $this->save($levelItem, $level, self::LEVEL_TTL_SECONDS);
            $this->save($this->cache->getItem(self::LAST_PUBLICATION_KEY), $this->clock->now()->getTimestamp(), self::PUBLICATION_TTL_SECONDS);
        } catch (\Throwable $e) {
            $this->warnAboutCache($e);

            return;
        }

        $this->publisher->publishAdminHealth(AdminHealthScope::EsiErrorBudget);
    }

    private function save(CacheItemInterface $item, int|string $value, int $ttlSeconds): void
    {
        $item->set($value);
        $item->expiresAfter($ttlSeconds);
        $this->cache->save($item);
    }

    private function warnAboutCache(\Throwable $e): void
    {
        $this->logger->warning('ESI error budget broadcast state could not be used: {message}', ['message' => $e->getMessage()]);
    }
}
