<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Repository\CachedAssetRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class CorporationAssetsRefreshGate
{
    public const int COOLDOWN_SECONDS = 3600;
    public const int IN_FLIGHT_TTL_SECONDS = 900;
    private const string IN_FLIGHT_KEY_PREFIX = 'corporation_assets_refresh_in_flight_';

    public function __construct(
        private CachedAssetRepository $cachedAssetRepository,
        private CacheInterface $cache,
        private ClockInterface $clock,
    ) {
    }

    public function lastSyncedAt(int $corporationId): ?\DateTimeImmutable
    {
        return $this->cachedAssetRepository->lastCachedAtOfCorporationAssets($corporationId);
    }

    public function nextRefreshAt(int $corporationId): ?\DateTimeImmutable
    {
        $lastSyncedAt = $this->lastSyncedAt($corporationId);
        if ($lastSyncedAt === null) {
            return null;
        }

        $nextRefreshAt = $lastSyncedAt->modify(sprintf('+%d seconds', self::COOLDOWN_SECONDS));

        return $nextRefreshAt > $this->clock->now() ? $nextRefreshAt : null;
    }

    public function claim(int $corporationId): bool
    {
        $claimed = false;
        $this->cache->get(
            self::inFlightKey($corporationId),
            function (ItemInterface $item) use (&$claimed): int {
                $item->expiresAt($this->clock->now()->modify(sprintf('+%d seconds', self::IN_FLIGHT_TTL_SECONDS)));
                $claimed = true;

                return $this->clock->now()->getTimestamp();
            },
        );

        return $claimed;
    }

    public function release(int $corporationId): void
    {
        $this->cache->delete(self::inFlightKey($corporationId));
    }

    private static function inFlightKey(int $corporationId): string
    {
        return self::IN_FLIGHT_KEY_PREFIX . $corporationId;
    }
}
