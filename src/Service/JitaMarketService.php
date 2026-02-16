<?php

declare(strict_types=1);

namespace App\Service;

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
    private const JITA_STATION_ID = 60003760;
    private const THE_FORGE_REGION_ID = 10000002;
    private const CACHE_KEY = 'jita_market_prices';
    private const CACHE_KEY_BUY = 'jita_market_buy_prices';
    private const CACHE_META_KEY = 'jita_market_meta';
    private const CACHE_TTL = 7200; // 2 hours
    private const ESI_BASE_URL = 'https://esi.evetech.net/latest';
    private const MAX_ORDERS_PER_TYPE = 20;
    private const VOLUME_CACHE_PREFIX = 'jita_volume_';
    private const VOLUME_CACHE_TTL = 86400; // 24 hours
    private const VOLUME_HISTORY_DAYS = 30;

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
     * Fetch average daily volume for multiple types from ESI market history.
     * Caches per-type with 24h TTL to avoid repeated calls.
     * Averages the last 30 days of data.
     *
     * @param int[] $typeIds
     * @return array<int, float> typeId => avgDailyVolume
     */
    public function getAverageDailyVolumes(array $typeIds): array
    {
        $result = [];
        $uncachedTypeIds = [];

        // Check cache first for each type
        foreach ($typeIds as $typeId) {
            $cacheItem = $this->cache->getItem(self::VOLUME_CACHE_PREFIX . $typeId);

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
                    self::THE_FORGE_REGION_ID,
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
                        $cacheItem = $this->cache->getItem(self::VOLUME_CACHE_PREFIX . $typeId);
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
     * Get the order book from cache.
     *
     * @return array<int, list<array{price: float, volume: int}>>|null
     */
    private function getOrderBook(string $cacheKey): ?array
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
