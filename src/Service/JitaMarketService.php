<?php

declare(strict_types=1);

namespace App\Service;

use App\Constant\EveConstants;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for syncing and caching Jita (The Forge) market prices.
 * Runs as a background job to keep prices up-to-date.
 *
 * Stores an order book (top N orders) per type to support weighted price calculations.
 */
class JitaMarketService
{
    private const JITA_STATION_ID = EveConstants::JITA_STATION_ID;
    private const THE_FORGE_REGION_ID = EveConstants::THE_FORGE_REGION_ID;
    private const CACHE_KEY = 'jita_market_prices';
    private const CACHE_KEY_BUY = 'jita_market_buy_prices';
    private const CACHE_META_KEY = 'jita_market_meta';
    private const CACHE_TTL = 7200; // 2 hours
    private const ESI_BASE_URL = 'https://esi.evetech.net/latest';
    private const MAX_ORDERS_PER_TYPE = 20;
    private const VOLUME_CACHE_PREFIX = 'jita_volume_';
    private const REGIONAL_VOLUME_CACHE_FORMAT = 'volume_%d_%d';
    private const REGIONAL_VOLUME_CACHE_FORMAT_PREFIX = 'volume_%d_';
    private const VOLUME_CACHE_TTL = 86400; // 24 hours
    private const VOLUME_HISTORY_DAYS = 30;
    private const ON_DEMAND_CACHE_PREFIX = 'jita_ondemand_';
    private const ON_DEMAND_CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'structure_market.cache')]
        private readonly CacheItemPoolInterface $cache,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sync all Jita market prices for industry materials.
     * Uses batched requests to avoid OOM with large datasets.
     *
     * @return array{success: bool, typeCount: int, duration: float, error?: string}
     */
    public function syncJitaMarket(): array
    {
        $startTime = microtime(true);

        try {
            // Get all type IDs used in industry (materials from blueprints)
            $typeIds = $this->getIndustryMaterialTypeIds();

            $this->logger->info('Fetching Jita prices for industry materials', [
                'typeCount' => count($typeIds),
            ]);

            // Fetch prices in batches (per type ID) to avoid OOM
            $allPrices = $this->fetchPricesInBatches($typeIds);

            // Cache sell prices
            $cacheItem = $this->cache->getItem(self::CACHE_KEY);
            $cacheItem->set($allPrices['sell']);
            $cacheItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($cacheItem);

            // Cache buy prices
            $buyItem = $this->cache->getItem(self::CACHE_KEY_BUY);
            $buyItem->set($allPrices['buy']);
            $buyItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($buyItem);

            // Cache metadata
            $metaItem = $this->cache->getItem(self::CACHE_META_KEY);
            $metaItem->set([
                'syncedAt' => new \DateTimeImmutable(),
                'typeCount' => count($allPrices['sell']),
            ]);
            $metaItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($metaItem);

            $duration = round(microtime(true) - $startTime, 2);

            return [
                'success' => true,
                'typeCount' => count($allPrices['sell']),
                'duration' => $duration,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Jita market sync failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'typeCount' => 0,
                'duration' => round(microtime(true) - $startTime, 2),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Refresh prices for specific type IDs and merge into the main cache.
     * Used for frequent alert price checks without doing a full region sync.
     *
     * @param int[] $typeIds
     * @return array{success: bool, typeCount: int, duration: float, error?: string}
     */
    public function refreshPricesForTypes(array $typeIds): array
    {
        $startTime = microtime(true);

        if (empty($typeIds)) {
            return [
                'success' => true,
                'typeCount' => 0,
                'duration' => 0.0,
            ];
        }

        try {
            $this->logger->info('Refreshing Jita prices for alert types', [
                'typeCount' => count($typeIds),
            ]);

            $freshBooks = $this->fetchPricesInBatches($typeIds);

            // Merge fresh data into existing sell cache
            $existingSell = $this->getOrderBook(self::CACHE_KEY) ?? [];
            foreach ($freshBooks['sell'] as $typeId => $orders) {
                $existingSell[$typeId] = $orders;
            }
            $sellItem = $this->cache->getItem(self::CACHE_KEY);
            $sellItem->set($existingSell);
            $sellItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($sellItem);

            // Merge fresh data into existing buy cache
            $existingBuy = $this->getOrderBook(self::CACHE_KEY_BUY) ?? [];
            foreach ($freshBooks['buy'] as $typeId => $orders) {
                $existingBuy[$typeId] = $orders;
            }
            $buyItem = $this->cache->getItem(self::CACHE_KEY_BUY);
            $buyItem->set($existingBuy);
            $buyItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($buyItem);

            $duration = round(microtime(true) - $startTime, 2);

            return [
                'success' => true,
                'typeCount' => count($typeIds),
                'duration' => $duration,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Alert price refresh failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'typeCount' => 0,
                'duration' => round(microtime(true) - $startTime, 2),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get the lowest sell price for a type from cached Jita prices.
     * Returns the best (first) price from the order book.
     */
    public function getPrice(int $typeId): ?float
    {
        $orderBook = $this->getOrderBook(self::CACHE_KEY);

        if ($orderBook === null) {
            return null;
        }

        $orders = $orderBook[$typeId] ?? [];

        return $orders[0]['price'] ?? null;
    }

    /**
     * Get prices for multiple types at once.
     *
     * @param int[] $typeIds
     * @return array<int, float|null>
     */
    public function getPrices(array $typeIds): array
    {
        $result = [];
        foreach ($typeIds as $typeId) {
            $result[$typeId] = null;
        }

        $orderBook = $this->getOrderBook(self::CACHE_KEY);

        if ($orderBook === null) {
            return $result;
        }

        foreach ($typeIds as $typeId) {
            $orders = $orderBook[$typeId] ?? [];
            $result[$typeId] = $orders[0]['price'] ?? null;
        }

        return $result;
    }

    /**
     * Get the highest buy price for a type from cached Jita prices.
     * Returns the best (first) price from the order book.
     */
    public function getBuyPrice(int $typeId): ?float
    {
        $orderBook = $this->getOrderBook(self::CACHE_KEY_BUY);

        if ($orderBook === null) {
            return null;
        }

        $orders = $orderBook[$typeId] ?? [];

        return $orders[0]['price'] ?? null;
    }

    /**
     * Get buy prices for multiple types at once.
     *
     * @param int[] $typeIds
     * @return array<int, float|null>
     */
    public function getBuyPrices(array $typeIds): array
    {
        $result = [];
        foreach ($typeIds as $typeId) {
            $result[$typeId] = null;
        }

        $orderBook = $this->getOrderBook(self::CACHE_KEY_BUY);

        if ($orderBook === null) {
            return $result;
        }

        foreach ($typeIds as $typeId) {
            $orders = $orderBook[$typeId] ?? [];
            $result[$typeId] = $orders[0]['price'] ?? null;
        }

        return $result;
    }

    /**
     * Get sell prices for multiple types, with on-demand ESI fallback for types not in the cache.
     * Uses the background-synced order book first, then fetches missing types from ESI.
     *
     * @param int[] $typeIds
     * @return array<int, float|null>
     */
    public function getPricesWithFallback(array $typeIds): array
    {
        $result = $this->getPrices($typeIds);

        $missingTypeIds = [];
        foreach ($result as $typeId => $price) {
            if ($price === null) {
                $missingTypeIds[] = $typeId;
            }
        }

        if (empty($missingTypeIds)) {
            return $result;
        }

        $onDemandBooks = $this->fetchOnDemandOrderBooks($missingTypeIds);

        foreach ($missingTypeIds as $typeId) {
            $orders = $onDemandBooks['sell'][$typeId] ?? [];
            $result[$typeId] = $orders[0]['price'] ?? null;
        }

        return $result;
    }

    /**
     * Get buy prices for multiple types, with on-demand ESI fallback for types not in the cache.
     *
     * @param int[] $typeIds
     * @return array<int, float|null>
     */
    public function getBuyPricesWithFallback(array $typeIds): array
    {
        $result = $this->getBuyPrices($typeIds);

        $missingTypeIds = [];
        foreach ($result as $typeId => $price) {
            if ($price === null) {
                $missingTypeIds[] = $typeId;
            }
        }

        if (empty($missingTypeIds)) {
            return $result;
        }

        $onDemandBooks = $this->fetchOnDemandOrderBooks($missingTypeIds);

        foreach ($missingTypeIds as $typeId) {
            $orders = $onDemandBooks['buy'][$typeId] ?? [];
            $result[$typeId] = $orders[0]['price'] ?? null;
        }

        return $result;
    }

    /**
     * Get weighted sell prices with on-demand ESI fallback for types not in the cache.
     *
     * @param array<int, int> $typeQuantities typeId => quantity
     * @return array<int, array{weightedPrice: float, coverage: float, ordersUsed: int}|null>
     */
    public function getWeightedSellPricesWithFallback(array $typeQuantities): array
    {
        $result = $this->getWeightedSellPrices($typeQuantities);

        $missingTypeIds = [];
        foreach ($result as $typeId => $data) {
            if ($data === null) {
                $missingTypeIds[] = $typeId;
            }
        }

        if (empty($missingTypeIds)) {
            return $result;
        }

        $onDemandBooks = $this->fetchOnDemandOrderBooks($missingTypeIds);

        foreach ($missingTypeIds as $typeId) {
            $orders = $onDemandBooks['sell'][$typeId] ?? [];
            $result[$typeId] = $this->calculateWeightedPrice($orders, $typeQuantities[$typeId]);
        }

        return $result;
    }

    /**
     * Get weighted buy prices with on-demand ESI fallback for types not in the cache.
     *
     * @param array<int, int> $typeQuantities typeId => quantity
     * @return array<int, array{weightedPrice: float, coverage: float, ordersUsed: int}|null>
     */
    public function getWeightedBuyPricesWithFallback(array $typeQuantities): array
    {
        $result = $this->getWeightedBuyPrices($typeQuantities);

        $missingTypeIds = [];
        foreach ($result as $typeId => $data) {
            if ($data === null) {
                $missingTypeIds[] = $typeId;
            }
        }

        if (empty($missingTypeIds)) {
            return $result;
        }

        $onDemandBooks = $this->fetchOnDemandOrderBooks($missingTypeIds);

        foreach ($missingTypeIds as $typeId) {
            $orders = $onDemandBooks['buy'][$typeId] ?? [];
            $result[$typeId] = $this->calculateWeightedPrice($orders, $typeQuantities[$typeId]);
        }

        return $result;
    }

    /**
     * Fetch order books on-demand from ESI for types not in the background-synced cache.
     * Uses a per-type short-lived cache to avoid repeated ESI calls for the same type.
     *
     * @param int[] $typeIds
     * @return array{sell: array<int, list<array{price: float, volume: int}>>, buy: array<int, list<array{price: float, volume: int}>>}
     */
    private function fetchOnDemandOrderBooks(array $typeIds): array
    {
        $sellBooks = [];
        $buyBooks = [];
        $uncachedTypeIds = [];

        // Check per-type on-demand cache first
        foreach ($typeIds as $typeId) {
            $cacheItem = $this->cache->getItem(self::ON_DEMAND_CACHE_PREFIX . $typeId);

            if ($cacheItem->isHit()) {
                /** @var array{sell: list<array{price: float, volume: int}>, buy: list<array{price: float, volume: int}>} $cached */
                $cached = $cacheItem->get();
                if (!empty($cached['sell'])) {
                    $sellBooks[$typeId] = $cached['sell'];
                }
                if (!empty($cached['buy'])) {
                    $buyBooks[$typeId] = $cached['buy'];
                }
            } else {
                $uncachedTypeIds[] = $typeId;
            }
        }

        if (empty($uncachedTypeIds)) {
            return ['sell' => $sellBooks, 'buy' => $buyBooks];
        }

        $this->logger->info('Fetching on-demand Jita prices for uncached types', [
            'typeCount' => count($uncachedTypeIds),
        ]);

        // Fetch from ESI in batches
        $batchSize = 10;
        $batches = array_chunk($uncachedTypeIds, $batchSize);

        foreach ($batches as $batch) {
            $responses = [];

            foreach ($batch as $typeId) {
                $url = sprintf(
                    '%s/markets/%d/orders/?order_type=all&type_id=%d',
                    self::ESI_BASE_URL,
                    self::THE_FORGE_REGION_ID,
                    $typeId
                );

                try {
                    $responses[$typeId] = $this->httpClient->request('GET', $url, [
                        'timeout' => 15,
                        'headers' => ['Accept' => 'application/json'],
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->debug('Failed to start on-demand market request', [
                        'typeId' => $typeId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            foreach ($responses as $typeId => $response) {
                try {
                    if ($response->getStatusCode() === 200) {
                        /** @var list<array<string, mixed>> $orders */
                        $orders = $response->toArray();
                        $orderBooks = $this->collectOrderBooks($orders);

                        if (!empty($orderBooks['sell'])) {
                            $sellBooks[$typeId] = $orderBooks['sell'];
                        }
                        if (!empty($orderBooks['buy'])) {
                            $buyBooks[$typeId] = $orderBooks['buy'];
                        }

                        // Cache per-type with short TTL
                        $cacheItem = $this->cache->getItem(self::ON_DEMAND_CACHE_PREFIX . $typeId);
                        $cacheItem->set($orderBooks);
                        $cacheItem->expiresAfter(self::ON_DEMAND_CACHE_TTL);
                        $this->cache->save($cacheItem);
                    }
                } catch (\Throwable $e) {
                    $this->logger->debug('Failed to fetch on-demand price for type', [
                        'typeId' => $typeId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            unset($responses);

            // Small delay between batches to avoid rate limiting
            usleep(50000); // 50ms
        }

        return ['sell' => $sellBooks, 'buy' => $buyBooks];
    }

    /**
     * Get cached daily volumes only (no ESI fetch for uncached types).
     * Returns 0.0 for types without cached volume data.
     * Uses Jita (The Forge) cache keys.
     *
     * @param int[] $typeIds
     * @return array<int, float> typeId => avgDailyVolume
     */
    public function getCachedDailyVolumes(array $typeIds): array
    {
        $result = [];
        foreach ($typeIds as $typeId) {
            $cacheItem = $this->cache->getItem(self::VOLUME_CACHE_PREFIX . $typeId);
            $result[$typeId] = $cacheItem->isHit() ? (float) $cacheItem->get() : 0.0;
        }

        return $result;
    }

    /**
     * Get cached daily volumes for a specific region (no ESI fetch for uncached types).
     * Returns 0.0 for types without cached volume data.
     *
     * @param int[] $typeIds
     * @return array<int, float> typeId => avgDailyVolume
     */
    public function getCachedDailyVolumesForRegion(int $regionId, array $typeIds): array
    {
        $result = [];
        foreach ($typeIds as $typeId) {
            $cacheKey = sprintf(self::REGIONAL_VOLUME_CACHE_FORMAT, $regionId, $typeId);
            $cacheItem = $this->cache->getItem($cacheKey);
            $result[$typeId] = $cacheItem->isHit() ? (float) $cacheItem->get() : 0.0;
        }

        return $result;
    }

    /**
     * Fetch average daily volume for multiple types from ESI market history (The Forge).
     * Caches per-type with 24h TTL to avoid repeated calls.
     * Averages the last 30 days of data.
     *
     * @param int[] $typeIds
     * @return array<int, float> typeId => avgDailyVolume
     */
    public function getAverageDailyVolumes(array $typeIds): array
    {
        return $this->fetchDailyVolumesForRegion(
            self::THE_FORGE_REGION_ID,
            $typeIds,
            self::VOLUME_CACHE_PREFIX,
        );
    }

    /**
     * Fetch average daily volume for multiple types from ESI market history for a specific region.
     * Caches per-type per-region with 24h TTL.
     *
     * @param int[] $typeIds
     * @return array<int, float> typeId => avgDailyVolume
     */
    public function getAverageDailyVolumesForRegion(int $regionId, array $typeIds): array
    {
        $cachePrefix = sprintf(self::REGIONAL_VOLUME_CACHE_FORMAT_PREFIX, $regionId);

        return $this->fetchDailyVolumesForRegion($regionId, $typeIds, $cachePrefix);
    }

    /**
     * Fetch average daily volumes from ESI market history for a given region.
     * Shared implementation used by both Jita and regional volume methods.
     *
     * @param int[] $typeIds
     * @return array<int, float> typeId => avgDailyVolume
     */
    private function fetchDailyVolumesForRegion(int $regionId, array $typeIds, string $cachePrefix): array
    {
        $result = [];
        $uncachedTypeIds = [];

        // Check cache first for each type
        foreach ($typeIds as $typeId) {
            $cacheItem = $this->cache->getItem($cachePrefix . $typeId);

            if ($cacheItem->isHit()) {
                /** @var float $volume */
                $volume = $cacheItem->get();
                $result[$typeId] = $volume;
            } else {
                $uncachedTypeIds[] = $typeId;
            }
        }

        if (empty($uncachedTypeIds)) {
            return $result;
        }

        $this->logger->info('Fetching market history for volume averages', [
            'regionId' => $regionId,
            'typeCount' => count($uncachedTypeIds),
        ]);

        // Fetch uncached types from ESI in batches
        $batchSize = 10;
        $batches = array_chunk($uncachedTypeIds, $batchSize);

        foreach ($batches as $batch) {
            $responses = [];

            foreach ($batch as $typeId) {
                $url = sprintf(
                    '%s/markets/%d/history/?type_id=%d',
                    self::ESI_BASE_URL,
                    $regionId,
                    $typeId
                );

                try {
                    $responses[$typeId] = $this->httpClient->request('GET', $url, [
                        'timeout' => 15,
                        'headers' => ['Accept' => 'application/json'],
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->debug('Failed to start market history request', [
                        'typeId' => $typeId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            foreach ($responses as $typeId => $response) {
                try {
                    if ($response->getStatusCode() === 200) {
                        /** @var list<array{date: string, order_count: int, volume: int, lowest: float, highest: float, average: float}> $history */
                        $history = $response->toArray();
                        $avgVolume = $this->computeAverageDailyVolume($history);

                        $result[$typeId] = $avgVolume;

                        // Cache the result
                        $cacheItem = $this->cache->getItem($cachePrefix . $typeId);
                        $cacheItem->set($avgVolume);
                        $cacheItem->expiresAfter(self::VOLUME_CACHE_TTL);
                        $this->cache->save($cacheItem);
                    }
                } catch (\Throwable $e) {
                    $this->logger->debug('Failed to fetch market history for type', [
                        'typeId' => $typeId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            unset($responses);

            // Small delay between batches to avoid rate limiting
            usleep(50000); // 50ms
        }

        return $result;
    }

    /**
     * Compute the average daily volume from the last 30 days of market history.
     *
     * @param list<array{date: string, order_count: int, volume: int, lowest: float, highest: float, average: float}> $history
     */
    private function computeAverageDailyVolume(array $history): float
    {
        if (empty($history)) {
            return 0.0;
        }

        // Take the last N entries (already sorted by date ascending from ESI)
        $recentHistory = \array_slice($history, -self::VOLUME_HISTORY_DAYS);
        $count = count($recentHistory);

        if ($count === 0) {
            return 0.0;
        }

        $totalVolume = 0;
        foreach ($recentHistory as $day) {
            $totalVolume += $day['volume'];
        }

        return round((float) $totalVolume / $count, 2);
    }

    /**
     * Calculate weighted average sell price for a given quantity.
     * Stacks orders from best (cheapest) to worst until the quantity is covered.
     *
     * @return array{weightedPrice: float, coverage: float, ordersUsed: int}|null
     */
    public function getWeightedSellPrice(int $typeId, int $quantity): ?array
    {
        $orderBook = $this->getOrderBook(self::CACHE_KEY);

        if ($orderBook === null) {
            return null;
        }

        $orders = $orderBook[$typeId] ?? [];

        return $this->calculateWeightedPrice($orders, $quantity);
    }

    /**
     * Calculate weighted average buy price for a given quantity.
     * Stacks orders from best (highest) to worst until the quantity is covered.
     *
     * @return array{weightedPrice: float, coverage: float, ordersUsed: int}|null
     */
    public function getWeightedBuyPrice(int $typeId, int $quantity): ?array
    {
        $orderBook = $this->getOrderBook(self::CACHE_KEY_BUY);

        if ($orderBook === null) {
            return null;
        }

        $orders = $orderBook[$typeId] ?? [];

        return $this->calculateWeightedPrice($orders, $quantity);
    }

    /**
     * Calculate weighted sell prices for multiple type/quantity pairs.
     *
     * @param array<int, int> $typeQuantities typeId => quantity
     * @return array<int, array{weightedPrice: float, coverage: float, ordersUsed: int}|null>
     */
    public function getWeightedSellPrices(array $typeQuantities): array
    {
        $orderBook = $this->getOrderBook(self::CACHE_KEY);
        $result = [];

        foreach ($typeQuantities as $typeId => $quantity) {
            if ($orderBook === null) {
                $result[$typeId] = null;
                continue;
            }

            $orders = $orderBook[$typeId] ?? [];
            $result[$typeId] = $this->calculateWeightedPrice($orders, $quantity);
        }

        return $result;
    }

    /**
     * Calculate weighted buy prices for multiple type/quantity pairs.
     *
     * @param array<int, int> $typeQuantities typeId => quantity
     * @return array<int, array{weightedPrice: float, coverage: float, ordersUsed: int}|null>
     */
    public function getWeightedBuyPrices(array $typeQuantities): array
    {
        $orderBook = $this->getOrderBook(self::CACHE_KEY_BUY);
        $result = [];

        foreach ($typeQuantities as $typeId => $quantity) {
            if ($orderBook === null) {
                $result[$typeId] = null;
                continue;
            }

            $orders = $orderBook[$typeId] ?? [];
            $result[$typeId] = $this->calculateWeightedPrice($orders, $quantity);
        }

        return $result;
    }

    /**
     * Stack orders from best to worst until the requested quantity is covered.
     *
     * @param list<array{price: float, volume: int}> $orders sorted by price (best first)
     * @return array{weightedPrice: float, coverage: float, ordersUsed: int}|null
     */
    private function calculateWeightedPrice(array $orders, int $quantity): ?array
    {
        if (empty($orders) || $quantity <= 0) {
            return null;
        }

        $totalCost = 0.0;
        $coveredQuantity = 0;
        $ordersUsed = 0;

        foreach ($orders as $order) {
            $remaining = $quantity - $coveredQuantity;

            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, $order['volume']);
            $totalCost += $take * $order['price'];
            $coveredQuantity += $take;
            $ordersUsed++;
        }

        if ($coveredQuantity === 0) {
            return null;
        }

        return [
            'weightedPrice' => $totalCost / $coveredQuantity,
            'coverage' => min(1.0, (float) $coveredQuantity / (float) $quantity),
            'ordersUsed' => $ordersUsed,
        ];
    }

    /**
     * Get Ravworks-style pricing: weighted average of the cheapest percentile of sell orders by volume.
     *
     * For each typeId, takes sell orders sorted cheapest first, calculates total volume,
     * then accumulates orders until the target percentile of total volume is reached.
     * The last order is partially filled to match the target exactly.
     *
     * @param int[] $typeIds
     * @return array<int, float|null> typeId => percentile price (null if no orders)
     */
    public function getCheapestPercentilePrices(array $typeIds, float $percentile = 0.05): array
    {
        $orderBook = $this->getOrderBook(self::CACHE_KEY);

        $result = [];

        foreach ($typeIds as $typeId) {
            $orders = ($orderBook !== null) ? ($orderBook[$typeId] ?? []) : [];
            $result[$typeId] = $this->calculatePercentilePrice($orders, $percentile);
        }

        return $result;
    }

    /**
     * Calculate the weighted average price of the cheapest percentile of sell orders by volume.
     *
     * @param list<array{price: float, volume: int}> $orders sorted by price ascending (cheapest first)
     */
    private function calculatePercentilePrice(array $orders, float $percentile): ?float
    {
        if (empty($orders) || $percentile <= 0.0) {
            return null;
        }

        $totalVolume = 0;
        foreach ($orders as $order) {
            $totalVolume += $order['volume'];
        }

        if ($totalVolume === 0) {
            return null;
        }

        $targetVolume = $totalVolume * min($percentile, 1.0);

        // If target volume is less than 1 unit, use the cheapest order's price
        if ($targetVolume < 1.0) {
            return $orders[0]['price'];
        }

        $accumulatedVolume = 0.0;
        $weightedCost = 0.0;

        foreach ($orders as $order) {
            $remaining = $targetVolume - $accumulatedVolume;

            if ($remaining <= 0.0) {
                break;
            }

            $take = min($remaining, (float) $order['volume']);
            $weightedCost += $take * $order['price'];
            $accumulatedVolume += $take;
        }

        if ($accumulatedVolume <= 0.0) {
            return null;
        }

        return $weightedCost / $accumulatedVolume;
    }

    /**
     * Get sell orders for a specific type from the cached order book.
     *
     * @return list<array{price: float, volume: int}>
     */
    public function getSellOrders(int $typeId): array
    {
        $orderBook = $this->getOrderBook(self::CACHE_KEY);

        if ($orderBook === null) {
            return [];
        }

        return $orderBook[$typeId] ?? [];
    }

    /**
     * Get buy orders for a specific type from the cached order book.
     *
     * @return list<array{price: float, volume: int}>
     */
    public function getBuyOrders(int $typeId): array
    {
        $orderBook = $this->getOrderBook(self::CACHE_KEY_BUY);

        if ($orderBook === null) {
            return [];
        }

        return $orderBook[$typeId] ?? [];
    }

    /**
     * Get order books (sell + buy) for a single type, with on-demand ESI fallback.
     * Checks the background-synced cache first, then fetches from ESI if missing.
     *
     * @return array{sell: list<array{price: float, volume: int}>, buy: list<array{price: float, volume: int}>}
     */
    public function getOrderBooksWithFallback(int $typeId): array
    {
        $sellOrders = $this->getSellOrders($typeId);
        $buyOrders = $this->getBuyOrders($typeId);

        if (!empty($sellOrders) || !empty($buyOrders)) {
            return ['sell' => $sellOrders, 'buy' => $buyOrders];
        }

        $onDemandBooks = $this->fetchOnDemandOrderBooks([$typeId]);

        return [
            'sell' => $onDemandBooks['sell'][$typeId] ?? [],
            'buy' => $onDemandBooks['buy'][$typeId] ?? [],
        ];
    }

    /**
     * Get the order book from cache.
     *
     * @return array<int, list<array{price: float, volume: int}>>|null
     */
    public function getOrderBook(string $cacheKey): ?array
    {
        $cacheItem = $this->cache->getItem($cacheKey);

        if (!$cacheItem->isHit()) {
            return null;
        }

        /** @var array<int, list<array{price: float, volume: int}>> $data */
        $data = $cacheItem->get();

        return $data;
    }

    /**
     * Check if we have cached Jita prices.
     */
    public function hasCachedData(): bool
    {
        return $this->cache->getItem(self::CACHE_KEY)->isHit();
    }

    /**
     * Get the last sync time.
     */
    public function getLastSyncTime(): ?\DateTimeImmutable
    {
        $cacheItem = $this->cache->getItem(self::CACHE_META_KEY);

        if (!$cacheItem->isHit()) {
            return null;
        }

        /** @var array{syncedAt: \DateTimeImmutable, typeCount: int} $meta */
        $meta = $cacheItem->get();

        return $meta['syncedAt'];
    }

    /**
     * Get all type IDs used as materials in industry (manufacturing + reactions).
     *
     * @return int[]
     */
    private function getIndustryMaterialTypeIds(): array
    {
        $sql = <<<SQL
            SELECT DISTINCT material_type_id
            FROM sde_industry_activity_materials
            WHERE activity_id IN (1, 9, 11)
            ORDER BY material_type_id
        SQL;

        $result = $this->connection->fetchFirstColumn($sql);
        $typeIds = array_map('intval', $result);

        // Also add ores and their compressed variants
        $oreTypeIds = $this->getOreTypeIds();
        $typeIds = array_unique(array_merge($typeIds, $oreTypeIds));

        // Also add all reprocess outputs (materials from type_materials table)
        $reprocessOutputIds = $this->getReprocessOutputTypeIds();
        $typeIds = array_unique(array_merge($typeIds, $reprocessOutputIds));

        // Also add PI commodities (P1-P4) for planetary production valuation
        $piTypeIds = $this->getPiCommodityTypeIds();
        $typeIds = array_unique(array_merge($typeIds, $piTypeIds));

        return $typeIds;
    }

    /**
     * Get all type IDs that are outputs of reprocessing (minerals, moon materials, etc.)
     *
     * @return int[]
     */
    private function getReprocessOutputTypeIds(): array
    {
        $sql = <<<SQL
            SELECT DISTINCT material_type_id
            FROM sde_inv_type_materials
        SQL;

        $result = $this->connection->fetchFirstColumn($sql);

        return array_map('intval', $result);
    }

    /**
     * Get all ore type IDs (raw and compressed) for mining ledger valuation.
     *
     * @return int[]
     */
    private function getOreTypeIds(): array
    {
        // Get all ore categories: Asteroid (25), Ice (465), Gas (711), Moon Ores (1855)
        // And their compressed variants
        $sql = <<<SQL
            SELECT t.type_id
            FROM sde_inv_types t
            JOIN sde_inv_groups g ON t.group_id = g.group_id
            JOIN sde_inv_categories c ON g.category_id = c.category_id
            WHERE c.category_id = 25
            AND t.published = true
            AND (
                -- Regular ores and variants
                t.type_name NOT LIKE '%Blueprint%'
            )
            UNION
            SELECT t.type_id
            FROM sde_inv_types t
            WHERE t.type_name LIKE 'Compressed %'
            AND t.type_name NOT LIKE '%Blueprint%'
            AND t.published = true
        SQL;

        $result = $this->connection->fetchFirstColumn($sql);

        return array_map('intval', $result);
    }

    /**
     * Get all PI commodity type IDs (P1-P4) for planetary production valuation.
     *
     * @return int[]
     */
    private function getPiCommodityTypeIds(): array
    {
        // PI market groups: P1=1334, P2=1335, P3=1336, P4=1337
        $sql = <<<SQL
            SELECT DISTINCT t.type_id
            FROM sde_inv_types t
            JOIN sde_inv_market_groups mg ON t.market_group_id = mg.market_group_id
            WHERE (
                mg.market_group_id IN (1334, 1335, 1336, 1337)
                OR mg.parent_group_id IN (1334, 1335, 1336, 1337)
            )
            AND t.published = true
        SQL;

        $result = $this->connection->fetchFirstColumn($sql);

        return array_map('intval', $result);
    }

    /**
     * Fetch prices in batches using parallel requests per type.
     * Collects top N orders per type for weighted price calculations.
     *
     * @param int[] $typeIds
     * @return array{sell: array<int, list<array{price: float, volume: int}>>, buy: array<int, list<array{price: float, volume: int}>>}
     */
    private function fetchPricesInBatches(array $typeIds): array
    {
        $sellOrderBooks = [];
        $buyOrderBooks = [];
        $batchSize = 20; // Concurrent requests
        $batches = array_chunk($typeIds, $batchSize);
        $totalBatches = count($batches);

        foreach ($batches as $batchIndex => $batch) {
            $responses = [];

            // Start parallel requests for this batch
            foreach ($batch as $typeId) {
                $url = sprintf(
                    '%s/markets/%d/orders/?order_type=all&type_id=%d',
                    self::ESI_BASE_URL,
                    self::THE_FORGE_REGION_ID,
                    $typeId
                );
                $responses[$typeId] = $this->httpClient->request('GET', $url, [
                    'timeout' => 15,
                    'headers' => ['Accept' => 'application/json'],
                ]);
            }

            // Process responses
            foreach ($responses as $typeId => $response) {
                try {
                    $statusCode = $response->getStatusCode();
                    if ($statusCode === 200) {
                        /** @var list<array<string, mixed>> $orders */
                        $orders = $response->toArray();
                        $orderBooks = $this->collectOrderBooks($orders);
                        if (!empty($orderBooks['sell'])) {
                            $sellOrderBooks[$typeId] = $orderBooks['sell'];
                        }
                        if (!empty($orderBooks['buy'])) {
                            $buyOrderBooks[$typeId] = $orderBooks['buy'];
                        }
                    }
                } catch (\Throwable $e) {
                    // Skip failed requests
                    $this->logger->debug('Failed to fetch price for type', [
                        'typeId' => $typeId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Free memory
            unset($responses);

            // Log progress every 10 batches
            if (($batchIndex + 1) % 10 === 0) {
                $this->logger->info('Jita sync progress', [
                    'batch' => $batchIndex + 1,
                    'totalBatches' => $totalBatches,
                    'sellTypesFound' => count($sellOrderBooks),
                    'buyTypesFound' => count($buyOrderBooks),
                ]);
            }

            // Small delay between batches to avoid rate limiting
            usleep(100000); // 100ms
        }

        $this->logger->info('Jita price fetch completed', [
            'totalTypes' => count($typeIds),
            'sellTypesFound' => count($sellOrderBooks),
            'buyTypesFound' => count($buyOrderBooks),
        ]);

        return ['sell' => $sellOrderBooks, 'buy' => $buyOrderBooks];
    }

    /**
     * Collect order books from ESI orders (prefer Jita station, fallback to region).
     * Sell orders sorted ascending by price, buy orders sorted descending by price.
     * Keeps top MAX_ORDERS_PER_TYPE orders per side.
     *
     * @param list<array<string, mixed>> $orders
     * @return array{sell: list<array{price: float, volume: int}>, buy: list<array{price: float, volume: int}>}
     */
    private function collectOrderBooks(array $orders): array
    {
        $jitaSellOrders = [];
        $regionSellOrders = [];
        $jitaBuyOrders = [];
        $regionBuyOrders = [];

        foreach ($orders as $order) {
            $price = (float) $order['price'];
            $volume = (int) $order['volume_remain'];
            $locationId = $order['location_id'];
            $isBuyOrder = (bool) $order['is_buy_order'];
            $entry = ['price' => $price, 'volume' => $volume];

            if ($isBuyOrder) {
                if ($locationId === self::JITA_STATION_ID) {
                    $jitaBuyOrders[] = $entry;
                }
                $regionBuyOrders[] = $entry;
            } else {
                if ($locationId === self::JITA_STATION_ID) {
                    $jitaSellOrders[] = $entry;
                }
                $regionSellOrders[] = $entry;
            }
        }

        // Pick Jita orders if available, fallback to region
        $sellOrders = !empty($jitaSellOrders) ? $jitaSellOrders : $regionSellOrders;
        $buyOrders = !empty($jitaBuyOrders) ? $jitaBuyOrders : $regionBuyOrders;

        // Sort sell orders ascending by price (cheapest first)
        usort($sellOrders, static fn (array $a, array $b) => $a['price'] <=> $b['price']);

        // Sort buy orders descending by price (highest first)
        usort($buyOrders, static fn (array $a, array $b) => $b['price'] <=> $a['price']);

        // Keep only top N
        $sellOrders = \array_slice($sellOrders, 0, self::MAX_ORDERS_PER_TYPE);
        $buyOrders = \array_slice($buyOrders, 0, self::MAX_ORDERS_PER_TYPE);

        return ['sell' => $sellOrders, 'buy' => $buyOrders];
    }
}
