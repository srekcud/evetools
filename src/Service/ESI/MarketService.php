<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Constant\EveConstants;
use App\Entity\EveToken;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MarketService
{
    private const JITA_STATION_ID = EveConstants::JITA_STATION_ID;
    private const THE_FORGE_REGION_ID = EveConstants::THE_FORGE_REGION_ID;

    // Max concurrent requests to avoid timeout
    private const MAX_CONCURRENT_REQUESTS = 10;

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $marketCache,
        private readonly LoggerInterface $logger,
        private readonly StructureMarketService $structureMarketService,
        private readonly JitaMarketService $jitaMarketService,
        private readonly int $defaultMarketStructureId,
        private readonly string $defaultMarketStructureName,
        private readonly string $esiBaseUrl = 'https://esi.evetech.net/latest',
    ) {
    }

    /**
     * Get sell prices for multiple type IDs from Jita (The Forge region).
     * First tries the JitaMarketService cache (populated by background sync),
     * then falls back to on-demand ESI fetch.
     *
     * @param int[] $typeIds
     * @return array<int, float|null> typeId => price (null if not found)
     */
    public function getJitaPrices(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        // Use JitaMarketService with on-demand fallback for missing types
        if ($this->jitaMarketService->hasCachedData()) {
            $this->logger->debug('Using JitaMarketService cache for Jita prices (with fallback)', [
                'typeCount' => count($typeIds),
            ]);

            return $this->jitaMarketService->getPricesWithFallback($typeIds);
        }

        // Fall back to on-demand fetch
        $this->logger->debug('Jita cache not available, fetching on-demand', [
            'typeCount' => count($typeIds),
        ]);

        $prices = [];
        foreach ($typeIds as $typeId) {
            $prices[$typeId] = null;
        }

        // Check per-item cache first
        $uncachedTypeIds = [];
        foreach ($typeIds as $typeId) {
            $cacheKey = "market_jita_{$typeId}";
            $cacheItem = $this->marketCache->getItem($cacheKey);
            if ($cacheItem->isHit()) {
                $prices[$typeId] = $cacheItem->get();
            } else {
                $uncachedTypeIds[] = $typeId;
            }
        }

        if (empty($uncachedTypeIds)) {
            return $prices;
        }

        // Fetch prices in parallel batches
        $batches = array_chunk($uncachedTypeIds, self::MAX_CONCURRENT_REQUESTS);

        foreach ($batches as $batch) {
            $responses = [];

            // Start all requests in parallel
            foreach ($batch as $typeId) {
                $url = sprintf(
                    '%s/markets/%d/orders/?order_type=sell&type_id=%d',
                    $this->esiBaseUrl,
                    self::THE_FORGE_REGION_ID,
                    $typeId
                );
                $responses[$typeId] = $this->httpClient->request('GET', $url, [
                    'timeout' => 10,
                    'headers' => ['Accept' => 'application/json'],
                ]);
            }

            // Process responses
            foreach ($responses as $typeId => $response) {
                try {
                    $statusCode = $response->getStatusCode();
                    if ($statusCode >= 200 && $statusCode < 300) {
                        $orders = $response->toArray();

                        // Find minimum sell price in Jita station
                        $minPrice = null;
                        foreach ($orders as $order) {
                            if (
                                $order['location_id'] === self::JITA_STATION_ID
                                && $order['is_buy_order'] === false
                            ) {
                                if ($minPrice === null || $order['price'] < $minPrice) {
                                    $minPrice = (float) $order['price'];
                                }
                            }
                        }

                        // If no Jita orders, use region minimum
                        if ($minPrice === null) {
                            foreach ($orders as $order) {
                                if ($order['is_buy_order'] === false) {
                                    if ($minPrice === null || $order['price'] < $minPrice) {
                                        $minPrice = (float) $order['price'];
                                    }
                                }
                            }
                        }

                        $prices[$typeId] = $minPrice;

                        // Cache for 5 minutes
                        $cacheKey = "market_jita_{$typeId}";
                        $cacheItem = $this->marketCache->getItem($cacheKey);
                        $cacheItem->set($minPrice);
                        $cacheItem->expiresAfter(300);
                        $this->marketCache->save($cacheItem);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to fetch Jita price', [
                        'typeId' => $typeId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $prices;
    }

    /**
     * Get sell prices from a player structure.
     * Uses the StructureMarketService cache (populated by background sync or manual trigger).
     * No longer falls back to direct ESI fetch to avoid memory issues with large markets.
     *
     * @param int[] $typeIds
     * @param int $structureId
     * @param EveToken $token
     * @return array{prices: array<int, float|null>, accessible: bool, fromCache: bool}
     */
    public function getStructurePrices(array $typeIds, int $structureId, EveToken $token): array
    {
        $prices = [];
        foreach ($typeIds as $typeId) {
            $prices[$typeId] = null;
        }

        if (empty($typeIds)) {
            return ['prices' => $prices, 'accessible' => true, 'fromCache' => false];
        }

        // Use StructureMarketService cache (populated by background sync or manual trigger)
        if ($this->structureMarketService->hasCachedData($structureId)) {
            $this->logger->debug('Using StructureMarketService cache for structure prices', [
                'structureId' => $structureId,
                'typeCount' => count($typeIds),
            ]);

            $prices = $this->structureMarketService->getLowestSellPrices($structureId, $typeIds);

            return ['prices' => $prices, 'accessible' => true, 'fromCache' => true];
        }

        // No cache available - user needs to trigger a sync
        $this->logger->debug('No structure market cache available', [
            'structureId' => $structureId,
        ]);

        return ['prices' => $prices, 'accessible' => false, 'fromCache' => false];
    }

    /**
     * Get prices from both Jita and a structure, with comparison.
     *
     * @param int[] $typeIds
     * @param int|null $structureId
     * @param EveToken|null $token
     * @return array{jita: array<int, float|null>, jitaFromCache: bool, jitaLastSync: ?\DateTimeImmutable, structure: array<int, float|null>, structureId: int, structureName: string, structureAccessible: bool, structureFromCache: bool, structureLastSync: ?\DateTimeImmutable}
     */
    public function comparePrices(array $typeIds, ?int $structureId, ?EveToken $token): array
    {
        $structureId = $structureId ?? $this->defaultMarketStructureId;
        $structureName = $this->getStructureName($structureId, $token) ?? $this->defaultMarketStructureName;

        // Get Jita prices (from cache if available)
        $jitaFromCache = $this->jitaMarketService->hasCachedData();
        $jitaPrices = $this->getJitaPrices($typeIds);
        $jitaLastSync = $jitaFromCache ? $this->jitaMarketService->getLastSyncTime() : null;

        $structurePrices = [];
        $structureAccessible = false;
        $structureFromCache = false;
        $structureLastSync = null;

        if ($token !== null) {
            $result = $this->getStructurePrices($typeIds, $structureId, $token);
            $structurePrices = $result['prices'];
            $structureAccessible = $result['accessible'];
            $structureFromCache = $result['fromCache'];

            if ($structureFromCache) {
                $structureLastSync = $this->structureMarketService->getLastSyncTime($structureId);
            }
        } else {
            foreach ($typeIds as $typeId) {
                $structurePrices[$typeId] = null;
            }
        }

        return [
            'jita' => $jitaPrices,
            'jitaFromCache' => $jitaFromCache,
            'jitaLastSync' => $jitaLastSync,
            'structure' => $structurePrices,
            'structureId' => $structureId,
            'structureName' => $structureName,
            'structureAccessible' => $structureAccessible,
            'structureFromCache' => $structureFromCache,
            'structureLastSync' => $structureLastSync,
        ];
    }

    /**
     * Get structure name from ESI.
     */
    public function getStructureName(int $structureId, ?EveToken $token): ?string
    {
        if ($token === null) {
            return null;
        }

        $cacheKey = "structure_name_{$structureId}";
        $cacheItem = $this->marketCache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        try {
            $structure = $this->esiClient->get(
                sprintf('/universe/structures/%d/', $structureId),
                $token
            );
            $name = $structure['name'] ?? null;

            if ($name !== null) {
                $cacheItem->set($name);
                $cacheItem->expiresAfter(86400); // Cache for 24 hours
                $this->marketCache->save($cacheItem);
            }

            return $name;
        } catch (EsiApiException $e) {
            $this->logger->warning('Failed to fetch structure name', [
                'structureId' => $structureId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function getDefaultStructureId(): int
    {
        return $this->defaultMarketStructureId;
    }

    public function getDefaultStructureName(): string
    {
        return $this->defaultMarketStructureName;
    }
}
