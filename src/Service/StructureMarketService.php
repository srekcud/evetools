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
        private readonly StructureMarketSnapshotService $snapshotService,
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

        return $this->extractSellPrice($data[$typeId] ?? null);
    }

    /**
     * Get the highest buy price for a type from a cached structure market.
     * Returns null if not in cache or not available.
     */
    public function getHighestBuyPrice(int $structureId, int $typeId): ?float
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $structureId;
        $cacheItem = $this->cache->getItem($cacheKey);

        if (!$cacheItem->isHit()) {
            return null;
        }

        $data = $cacheItem->get();

        return $this->extractBuyPrice($data[$typeId] ?? null);
    }

    /**
     * Get lowest sell prices for multiple types at once (loads cache only once).
     *
     * @param int $structureId
     * @param int[] $typeIds
     * @return array<int, float|null> typeId => lowest sell price (null if not found)
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
            $result[$typeId] = $this->extractSellPrice($data[$typeId] ?? null);
        }

        unset($data);

        return $result;
    }

    /**
     * Get highest buy prices for multiple types at once (loads cache only once).
     *
     * @param int $structureId
     * @param int[] $typeIds
     * @return array<int, float|null> typeId => highest buy price (null if not found)
     */
    public function getHighestBuyPrices(int $structureId, array $typeIds): array
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
            $result[$typeId] = $this->extractBuyPrice($data[$typeId] ?? null);
        }

        unset($data);

        return $result;
    }

    /**
     * Extract sell price from a cache entry, supporting both old and new formats.
     *
     * Old format: float (sell min price only)
     * New format: array{sell: float, buy: float|null}
     */
    private function extractSellPrice(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        // New format: associative array with 'sell' key
        if (is_array($value) && array_key_exists('sell', $value)) {
            return $value['sell'] !== null ? (float) $value['sell'] : null;
        }

        // Old format: direct float value (min sell price)
        if (is_numeric($value)) {
            return (float) $value;
        }

        // Legacy array-of-prices format
        if (is_array($value)) {
            return empty($value) ? null : min($value);
        }

        return null;
    }

    /**
     * Extract buy price from a cache entry, supporting both old and new formats.
     * Old format has no buy data, so returns null.
     */
    private function extractBuyPrice(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        // New format: associative array with 'buy' key
        if (is_array($value) && array_key_exists('buy', $value)) {
            return $value['buy'] !== null ? (float) $value['buy'] : null;
        }

        // Old format: no buy data available
        return null;
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
     * Get sell orders for a type from a cached structure market.
     *
     * @return list<array{price: float, volume: int}>
     */
    public function getSellOrders(int $structureId, int $typeId): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $structureId . '_orderbooks';
        $cacheItem = $this->cache->getItem($cacheKey);

        if (!$cacheItem->isHit()) {
            return [];
        }

        $data = $cacheItem->get();

        return $data[$typeId]['sell'] ?? [];
    }

    /**
     * Get buy orders for a type from a cached structure market.
     *
     * @return list<array{price: float, volume: int}>
     */
    public function getBuyOrders(int $structureId, int $typeId): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $structureId . '_orderbooks';
        $cacheItem = $this->cache->getItem($cacheKey);

        if (!$cacheItem->isHit()) {
            return [];
        }

        $data = $cacheItem->get();

        return $data[$typeId]['buy'] ?? [];
    }

    /**
     * Clear cached data for a structure.
     */
    public function clearCache(int $structureId): void
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $structureId;
        $metaKey = self::CACHE_KEY_PREFIX . $structureId . '_meta';
        $orderBookKey = self::CACHE_KEY_PREFIX . $structureId . '_orderbooks';

        $this->cache->deleteItem($cacheKey);
        $this->cache->deleteItem($metaKey);
        $this->cache->deleteItem($orderBookKey);

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
     * Stores the minimum sell price and maximum buy price per type.
     *
     * @param int         $structureId   The structure ID to sync
     * @param string      $structureName The structure name for logging
     * @param EveToken    $token         The ESI token for API access
     * @param string|null $userId        Optional user ID for Mercure notifications
     */
    /** @return array<string, mixed> */
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

            // Track min sell price, max buy price, order counts and volumes per type
            $pricesByType = [];
            /** @var array<int, array{sellMin: float|null, buyMax: float|null, sellOrderCount: int, buyOrderCount: int, sellVolume: int, buyVolume: int}> $aggregates */
            $aggregates = [];
            /** @var array<int, array{sell: list<array{price: float, volume: int}>, buy: list<array{price: float, volume: int}>}> $orderBooks */
            $orderBooks = [];
            $totalOrders = 0;
            $sellOrders = 0;
            $buyOrders = 0;

            foreach ($orders as $order) {
                $totalOrders++;
                $typeId = $order['type_id'];
                $price = (float) $order['price'];
                $volume = (int) $order['volume_remain'];

                // Initialize aggregate entry if needed
                if (!isset($aggregates[$typeId])) {
                    $aggregates[$typeId] = [
                        'sellMin' => null,
                        'buyMax' => null,
                        'sellOrderCount' => 0,
                        'buyOrderCount' => 0,
                        'sellVolume' => 0,
                        'buyVolume' => 0,
                    ];
                }

                // Collect orders for order book
                if (!isset($orderBooks[$typeId])) {
                    $orderBooks[$typeId] = ['sell' => [], 'buy' => []];
                }
                $side = $order['is_buy_order'] ? 'buy' : 'sell';
                $orderBooks[$typeId][$side][] = ['price' => $price, 'volume' => $volume];

                if ($order['is_buy_order']) {
                    $buyOrders++;
                    $aggregates[$typeId]['buyOrderCount']++;
                    $aggregates[$typeId]['buyVolume'] += $volume;

                    // Track maximum buy price
                    if (!isset($pricesByType[$typeId])) {
                        $pricesByType[$typeId] = ['sell' => null, 'buy' => $price];
                    } elseif ($pricesByType[$typeId]['buy'] === null || $price > $pricesByType[$typeId]['buy']) {
                        $pricesByType[$typeId]['buy'] = $price;
                    }

                    if ($aggregates[$typeId]['buyMax'] === null || $price > $aggregates[$typeId]['buyMax']) {
                        $aggregates[$typeId]['buyMax'] = $price;
                    }
                } else {
                    $sellOrders++;
                    $aggregates[$typeId]['sellOrderCount']++;
                    $aggregates[$typeId]['sellVolume'] += $volume;

                    // Track minimum sell price
                    if (!isset($pricesByType[$typeId])) {
                        $pricesByType[$typeId] = ['sell' => $price, 'buy' => null];
                    } elseif ($pricesByType[$typeId]['sell'] === null || $price < $pricesByType[$typeId]['sell']) {
                        $pricesByType[$typeId]['sell'] = $price;
                    }

                    if ($aggregates[$typeId]['sellMin'] === null || $price < $aggregates[$typeId]['sellMin']) {
                        $aggregates[$typeId]['sellMin'] = $price;
                    }
                }
            }

            // Sort and truncate order books to top 20 per side
            foreach ($orderBooks as $typeId => &$book) {
                usort($book['sell'], fn(array $a, array $b) => $a['price'] <=> $b['price']);
                $book['sell'] = array_slice($book['sell'], 0, 20);
                usort($book['buy'], fn(array $a, array $b) => $b['price'] <=> $a['price']);
                $book['buy'] = array_slice($book['buy'], 0, 20);
            }
            unset($book);

            // Update progress
            if ($userId !== null) {
                $this->mercurePublisher->syncProgress($userId, 'market-structure', 80, sprintf('Caching %d prices...', count($pricesByType)));
            }

            // Cache the data: typeId => {sell: float|null, buy: float|null}
            $cacheKey = self::CACHE_KEY_PREFIX . $structureId;
            $cacheItem = $this->cache->getItem($cacheKey);
            $cacheItem->set($pricesByType);
            $cacheItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($cacheItem);

            // Cache the order books separately (top 20 orders per side per type)
            $orderBookKey = self::CACHE_KEY_PREFIX . $structureId . '_orderbooks';
            $orderBookItem = $this->cache->getItem($orderBookKey);
            $orderBookItem->set($orderBooks);
            $orderBookItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($orderBookItem);

            $typeCount = count($pricesByType);

            // Record daily snapshots for price history
            $this->snapshotService->recordSnapshots($structureId, $aggregates);

            // Store metadata
            $metaKey = self::CACHE_KEY_PREFIX . $structureId . '_meta';
            $metaItem = $this->cache->getItem($metaKey);
            $metaItem->set([
                'syncedAt' => new \DateTimeImmutable(),
                'totalOrders' => $totalOrders,
                'sellOrders' => $sellOrders,
                'buyOrders' => $buyOrders,
                'typeCount' => $typeCount,
            ]);
            $metaItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($metaItem);

            $duration = round(microtime(true) - $startTime, 2);

            $this->logger->info('Structure market sync completed', [
                'structureId' => $structureId,
                'structureName' => $structureName,
                'totalOrders' => $totalOrders,
                'sellOrders' => $sellOrders,
                'buyOrders' => $buyOrders,
                'typeCount' => $typeCount,
                'duration' => $duration,
            ]);

            // Notify sync completed
            if ($userId !== null) {
                $message = sprintf('%d sell + %d buy orders, %d unique types', $sellOrders, $buyOrders, $typeCount);
                $this->mercurePublisher->syncCompleted($userId, 'market-structure', $message, [
                    'structureId' => $structureId,
                    'structureName' => $structureName,
                    'totalOrders' => $totalOrders,
                    'sellOrders' => $sellOrders,
                    'buyOrders' => $buyOrders,
                    'typeCount' => $typeCount,
                ]);
            }

            return [
                'success' => true,
                'totalOrders' => $totalOrders,
                'sellOrders' => $sellOrders,
                'buyOrders' => $buyOrders,
                'typeCount' => $typeCount,
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
