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
 */
class JitaMarketService
{
    private const JITA_STATION_ID = 60003760;
    private const THE_FORGE_REGION_ID = 10000002;
    private const CACHE_KEY = 'jita_market_prices';
    private const CACHE_META_KEY = 'jita_market_meta';
    private const CACHE_TTL = 7200; // 2 hours
    private const ESI_BASE_URL = 'https://esi.evetech.net/latest';

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
            $prices = $this->fetchPricesInBatches($typeIds);

            // Cache the prices
            $cacheItem = $this->cache->getItem(self::CACHE_KEY);
            $cacheItem->set($prices);
            $cacheItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($cacheItem);

            // Cache metadata
            $metaItem = $this->cache->getItem(self::CACHE_META_KEY);
            $metaItem->set([
                'syncedAt' => new \DateTimeImmutable(),
                'typeCount' => count($prices),
            ]);
            $metaItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($metaItem);

            $duration = round(microtime(true) - $startTime, 2);

            return [
                'success' => true,
                'typeCount' => count($prices),
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
     */
    public function getPrice(int $typeId): ?float
    {
        $cacheItem = $this->cache->getItem(self::CACHE_KEY);

        if (!$cacheItem->isHit()) {
            return null;
        }

        $prices = $cacheItem->get();

        return $prices[$typeId] ?? null;
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

        $cacheItem = $this->cache->getItem(self::CACHE_KEY);

        if (!$cacheItem->isHit()) {
            return $result;
        }

        $prices = $cacheItem->get();

        foreach ($typeIds as $typeId) {
            $result[$typeId] = $prices[$typeId] ?? null;
        }

        return $result;
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

        $meta = $cacheItem->get();

        return $meta['syncedAt'] ?? null;
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
     * Fetch prices in batches using parallel requests per type.
     * Much more memory efficient than loading all Forge orders.
     *
     * @param int[] $typeIds
     * @return array<int, float>
     */
    private function fetchPricesInBatches(array $typeIds): array
    {
        $prices = [];
        $batchSize = 20; // Concurrent requests
        $batches = array_chunk($typeIds, $batchSize);
        $totalBatches = count($batches);

        foreach ($batches as $batchIndex => $batch) {
            $responses = [];

            // Start parallel requests for this batch
            foreach ($batch as $typeId) {
                $url = sprintf(
                    '%s/markets/%d/orders/?order_type=sell&type_id=%d',
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
                        $orders = $response->toArray();
                        $price = $this->findBestPrice($orders);
                        if ($price !== null) {
                            $prices[$typeId] = $price;
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
                    'pricesFound' => count($prices),
                ]);
            }

            // Small delay between batches to avoid rate limiting
            usleep(100000); // 100ms
        }

        $this->logger->info('Jita price fetch completed', [
            'totalTypes' => count($typeIds),
            'pricesFound' => count($prices),
        ]);

        return $prices;
    }

    /**
     * Find the best price from orders (prefer Jita station, fallback to region).
     *
     * @param array $orders
     * @return float|null
     */
    private function findBestPrice(array $orders): ?float
    {
        $jitaPrice = null;
        $regionPrice = null;

        foreach ($orders as $order) {
            if ($order['is_buy_order']) {
                continue;
            }

            $price = (float) $order['price'];
            $locationId = $order['location_id'];

            if ($locationId === self::JITA_STATION_ID) {
                if ($jitaPrice === null || $price < $jitaPrice) {
                    $jitaPrice = $price;
                }
            }

            if ($regionPrice === null || $price < $regionPrice) {
                $regionPrice = $price;
            }
        }

        return $jitaPrice ?? $regionPrice;
    }
}
