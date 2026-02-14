<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EveToken;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class StructureMarketService
{
    private const CACHE_KEY_PREFIX = 'structure_market_';
    private const CACHE_TTL = 7200; // 2 hours

    public function __construct(
        private readonly EsiClient $esiClient,
        #[Autowire(service: 'structure_market.cache')]
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    /**
     * Get the lowest sell price for a type from a cached structure market.
     * Returns null if not in cache or not available.
     */
    public function getLowestSellPrice(int $structureId, int $typeId): ?float
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $structureId;
        $cacheItem = $this->cache->getItem($cacheKey);

        if (!$cacheItem->isHit()) {
            return null;
        }

        $data = $cacheItem->get();

        // Support both old format (array of prices) and new format (single min price)
        $value = $data[$typeId] ?? null;
        if ($value === null) {
            return null;
        }

        // Old format: array of prices
        if (is_array($value)) {
            return empty($value) ? null : min($value);
        }

        // New format: single min price
        return (float) $value;
    }

    /**
     * Get lowest sell prices for multiple types at once (loads cache only once).
     * This is more memory efficient than calling getLowestSellPrice in a loop.
     *
     * @param int $structureId
     * @param int[] $typeIds
     * @return array<int, float|null> typeId => lowest price (null if not found)
     */
    public function getLowestSellPrices(int $structureId, array $typeIds): array
    {
        $result = [];
        foreach ($typeIds as $typeId) {
            $result[$typeId] = null;
        }

        if (empty($typeIds)) {
            return $result;
        }

        $cacheKey = self::CACHE_KEY_PREFIX . $structureId;
        $cacheItem = $this->cache->getItem($cacheKey);

        if (!$cacheItem->isHit()) {
            return $result;
        }

        $data = $cacheItem->get();

        foreach ($typeIds as $typeId) {
            $value = $data[$typeId] ?? null;
            if ($value === null) {
                continue;
            }

            // Old format: array of prices
            if (is_array($value)) {
                $result[$typeId] = empty($value) ? null : min($value);
            } else {
                // New format: single min price
                $result[$typeId] = (float) $value;
            }
        }

        // Free memory
        unset($data);

        return $result;
    }

    /**
     * Check if we have cached data for a structure.
     */
    public function hasCachedData(int $structureId): bool
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $structureId;
        return $this->cache->getItem($cacheKey)->isHit();
    }

    /**
     * Clear cached data for a structure.
     */
    public function clearCache(int $structureId): void
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $structureId;
        $metaKey = self::CACHE_KEY_PREFIX . $structureId . '_meta';

        $this->cache->deleteItem($cacheKey);
        $this->cache->deleteItem($metaKey);

        $this->logger->info('Structure market cache cleared', [
            'structureId' => $structureId,
        ]);
    }

    /**
     * Get the last sync time for a structure (if available).
     */
    public function getLastSyncTime(int $structureId): ?\DateTimeImmutable
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $structureId . '_meta';
        $cacheItem = $this->cache->getItem($cacheKey);

        if (!$cacheItem->isHit()) {
            return null;
        }

        $meta = $cacheItem->get();
        return $meta['syncedAt'] ?? null;
    }

    /**
     * Fetch and cache structure market data.
     * This is meant to be called from a background job.
     *
     * Only stores the minimum sell price per type to reduce memory usage.
     *
     * @param int         $structureId   The structure ID to sync
     * @param string      $structureName The structure name for logging
     * @param EveToken    $token         The ESI token for API access
     * @param string|null $userId        Optional user ID for Mercure notifications
     */
    public function syncStructureMarket(int $structureId, string $structureName, EveToken $token, ?string $userId = null): array
    {
        $this->logger->info('Starting structure market sync', [
            'structureId' => $structureId,
            'structureName' => $structureName,
        ]);

        // Notify sync started
        if ($userId !== null) {
            $this->mercurePublisher->syncStarted($userId, 'market-structure', sprintf('Syncing %s market...', $structureName));
        }

        $startTime = microtime(true);

        try {
            // Fetch all market orders from the structure
            $orders = $this->esiClient->getPaginated(
                "/markets/structures/{$structureId}/",
                $token
            );

            // Update progress
            if ($userId !== null) {
                $this->mercurePublisher->syncProgress($userId, 'market-structure', 50, 'Processing market orders...');
            }

            // Track minimum sell price per type (memory efficient)
            $minPriceByType = [];
            $totalOrders = 0;
            $sellOrders = 0;

            foreach ($orders as $order) {
                $totalOrders++;
                if ($order['is_buy_order']) {
                    continue;
                }
                $sellOrders++;
                $typeId = $order['type_id'];
                $price = (float) $order['price'];

                // Only keep the minimum price
                if (!isset($minPriceByType[$typeId]) || $price < $minPriceByType[$typeId]) {
                    $minPriceByType[$typeId] = $price;
                }
            }

            // Update progress
            if ($userId !== null) {
                $this->mercurePublisher->syncProgress($userId, 'market-structure', 80, sprintf('Caching %d prices...', count($minPriceByType)));
            }

            // Cache the data (now much smaller - only min prices)
            $cacheKey = self::CACHE_KEY_PREFIX . $structureId;
            $cacheItem = $this->cache->getItem($cacheKey);
            $cacheItem->set($minPriceByType);
            $cacheItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($cacheItem);

            // Store metadata
            $metaKey = self::CACHE_KEY_PREFIX . $structureId . '_meta';
            $metaItem = $this->cache->getItem($metaKey);
            $metaItem->set([
                'syncedAt' => new \DateTimeImmutable(),
                'totalOrders' => $totalOrders,
                'sellOrders' => $sellOrders,
                'typeCount' => count($minPriceByType),
            ]);
            $metaItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($metaItem);

            $duration = round(microtime(true) - $startTime, 2);

            $this->logger->info('Structure market sync completed', [
                'structureId' => $structureId,
                'structureName' => $structureName,
                'totalOrders' => $totalOrders,
                'sellOrders' => $sellOrders,
                'typeCount' => count($minPriceByType),
                'duration' => $duration,
            ]);

            // Notify sync completed
            if ($userId !== null) {
                $message = sprintf('%d orders, %d unique types', $sellOrders, count($minPriceByType));
                $this->mercurePublisher->syncCompleted($userId, 'market-structure', $message, [
                    'structureId' => $structureId,
                    'structureName' => $structureName,
                    'totalOrders' => $totalOrders,
                    'sellOrders' => $sellOrders,
                    'typeCount' => count($minPriceByType),
                ]);
            }

            return [
                'success' => true,
                'totalOrders' => $totalOrders,
                'sellOrders' => $sellOrders,
                'typeCount' => count($minPriceByType),
                'duration' => $duration,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Structure market sync failed', [
                'structureId' => $structureId,
                'structureName' => $structureName,
                'error' => $e->getMessage(),
            ]);

            // Notify sync error
            if ($userId !== null) {
                $this->mercurePublisher->syncError($userId, 'market-structure', $e->getMessage());
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
