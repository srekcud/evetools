<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Exception\EsiApiException;
use App\Service\ESI\EsiClient;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service for caching ESI adjusted prices and system cost indices.
 * Both endpoints are public (no auth required).
 *
 * Used to estimate industry job install costs:
 *   install_cost = EIV * runs * system_cost_index * (1 + facility_tax_rate / 100)
 *
 * Where EIV (Estimated Item Value) = sum(adjustedPrice(inputMaterial) * quantity)
 * for all input materials at ME0 (raw SDE quantities).
 */
class EsiCostIndexService
{
    private const ADJUSTED_PRICE_PREFIX = 'esi_adjusted_price_';
    private const ADJUSTED_PRICE_TTL = 86400; // 24 hours
    private const ADJUSTED_PRICE_META_KEY = 'esi_adjusted_prices_meta';

    private const COST_INDEX_PREFIX = 'esi_cost_index_';
    private const COST_INDEX_TTL = 7200; // 2 hours
    private const COST_INDEX_META_KEY = 'esi_cost_indices_meta';

    public function __construct(
        private readonly EsiClient $esiClient,
        #[Autowire(service: 'esi_cost_index.cache')]
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch all adjusted prices from ESI and cache them individually.
     *
     * ESI endpoint: GET /markets/prices/
     * Returns ~14K entries with type_id and adjusted_price.
     *
     * @return int Number of prices cached
     */
    public function syncAdjustedPrices(): int
    {
        $this->logger->info('Fetching ESI adjusted prices');

        /** @var list<array{type_id: int, adjusted_price?: float, average_price?: float}> $prices */
        $prices = $this->fetchPublicEsi('/markets/prices/');

        $count = 0;
        foreach ($prices as $entry) {
            $typeId = $entry['type_id'];
            $adjustedPrice = $entry['adjusted_price'] ?? 0.0;

            $cacheItem = $this->cache->getItem(self::ADJUSTED_PRICE_PREFIX . $typeId);
            $cacheItem->set($adjustedPrice);
            $cacheItem->expiresAfter(self::ADJUSTED_PRICE_TTL);
            $this->cache->save($cacheItem);

            $count++;
        }

        // Store metadata
        $metaItem = $this->cache->getItem(self::ADJUSTED_PRICE_META_KEY);
        $metaItem->set([
            'syncedAt' => new \DateTimeImmutable(),
            'count' => $count,
        ]);
        $metaItem->expiresAfter(self::ADJUSTED_PRICE_TTL);
        $this->cache->save($metaItem);

        $this->logger->info('ESI adjusted prices cached', ['count' => $count]);

        return $count;
    }

    /**
     * Fetch all system cost indices from ESI and cache them.
     *
     * ESI endpoint: GET /industry/systems/
     * Returns cost index per solar system per activity.
     *
     * @return int Number of systems cached
     */
    public function syncCostIndices(): int
    {
        $this->logger->info('Fetching ESI system cost indices');

        /** @var list<array{solar_system_id: int, cost_indices: list<array{activity: string, cost_index: float}>}> $systems */
        $systems = $this->fetchPublicEsi('/industry/systems/');

        $count = 0;
        foreach ($systems as $system) {
            $solarSystemId = $system['solar_system_id'];

            foreach ($system['cost_indices'] as $index) {
                $activity = $index['activity'];
                $costIndex = $index['cost_index'];

                $cacheKey = self::COST_INDEX_PREFIX . $solarSystemId . '_' . $activity;
                $cacheItem = $this->cache->getItem($cacheKey);
                $cacheItem->set($costIndex);
                $cacheItem->expiresAfter(self::COST_INDEX_TTL);
                $this->cache->save($cacheItem);
            }

            $count++;
        }

        // Store metadata
        $metaItem = $this->cache->getItem(self::COST_INDEX_META_KEY);
        $metaItem->set([
            'syncedAt' => new \DateTimeImmutable(),
            'count' => $count,
        ]);
        $metaItem->expiresAfter(self::COST_INDEX_TTL);
        $this->cache->save($metaItem);

        $this->logger->info('ESI system cost indices cached', ['count' => $count]);

        return $count;
    }

    /**
     * EsiApiException extends \Exception: rethrown as RuntimeException to keep this service's error contract.
     *
     * @return array<mixed>
     */
    private function fetchPublicEsi(string $endpoint): array
    {
        try {
            return $this->esiClient->get($endpoint, null);
        } catch (EsiApiException $e) {
            throw new \RuntimeException(sprintf('ESI %s failed (HTTP %d): %s', $endpoint, $e->statusCode, $e->getMessage()), previous: $e);
        }
    }

    /**
     * Get adjusted prices for multiple type IDs from cache in a single batch.
     * Avoids N individual cache lookups when processing many products.
     *
     * @param int[] $typeIds
     * @return array<int, float> typeId => adjustedPrice
     */
    public function getAdjustedPrices(array $typeIds): array
    {
        $result = [];
        foreach ($typeIds as $typeId) {
            $cacheItem = $this->cache->getItem(self::ADJUSTED_PRICE_PREFIX . $typeId);
            if ($cacheItem->isHit()) {
                $result[$typeId] = (float) $cacheItem->get();
            }
        }

        return $result;
    }

    /**
     * Calculate EIV using pre-loaded adjusted prices (avoids individual cache lookups).
     *
     * @param list<array{materialTypeId: int, quantity: int}> $materials ME0 materials from SDE
     * @param array<int, float> $adjustedPrices Pre-loaded prices map from getAdjustedPrices()
     */
    public function calculateEivFromPrices(array $materials, array $adjustedPrices): float
    {
        $eiv = 0.0;
        foreach ($materials as $material) {
            $eiv += ($adjustedPrices[$material['materialTypeId']] ?? 0.0) * $material['quantity'];
        }

        return $eiv;
    }

    /**
     * Get the adjusted price for a type ID from cache.
     */
    public function getAdjustedPrice(int $typeId): ?float
    {
        $cacheItem = $this->cache->getItem(self::ADJUSTED_PRICE_PREFIX . $typeId);

        if (!$cacheItem->isHit()) {
            return null;
        }

        /** @var float $price */
        $price = $cacheItem->get();

        return $price;
    }

    /**
     * Get the cost index for a solar system and activity from cache.
     *
     * @param string $activity One of: manufacturing, researching_time_efficiency,
     *                         researching_material_efficiency, copying, invention, reaction
     */
    public function getCostIndex(int $solarSystemId, string $activity): ?float
    {
        $cacheKey = self::COST_INDEX_PREFIX . $solarSystemId . '_' . $activity;
        $cacheItem = $this->cache->getItem($cacheKey);

        if (!$cacheItem->isHit()) {
            return null;
        }

        /** @var float $costIndex */
        $costIndex = $cacheItem->get();

        return $costIndex;
    }

    /**
     * Calculate Estimated Item Value (EIV) from blueprint input materials at ME0.
     * EIV = sum(adjustedPrice(materialTypeId) * quantity).
     *
     * @param list<array{materialTypeId: int, quantity: int}> $materials ME0 materials from SDE
     */
    public function calculateEiv(array $materials): float
    {
        $eiv = 0.0;
        foreach ($materials as $material) {
            $adjustedPrice = $this->getAdjustedPrice($material['materialTypeId']);
            if ($adjustedPrice !== null) {
                $eiv += $adjustedPrice * $material['quantity'];
            }
        }

        return $eiv;
    }

    /**
     * Calculate the estimated job install cost.
     *
     * Formula: eiv * runs * system_cost_index * (1 + facility_tax_rate / 100)
     *
     * @param float $eiv Estimated Item Value (sum of adjustedPrice * quantity for ME0 materials)
     * @param int $runs Number of runs
     * @param int $solarSystemId Solar system where the job runs
     * @param string $activity Activity type (manufacturing, reaction, etc.)
     * @param float|null $facilityTaxRate Facility tax rate as percentage (e.g. 10 for 10%). Null = 0.
     */
    public function calculateJobInstallCost(
        float $eiv,
        int $runs,
        int $solarSystemId,
        string $activity,
        ?float $facilityTaxRate = null,
    ): float {
        if ($eiv <= 0.0) {
            return 0.0;
        }

        $costIndex = $this->getCostIndex($solarSystemId, $activity);
        if ($costIndex === null) {
            return 0.0;
        }

        $taxMultiplier = 1.0 + ($facilityTaxRate ?? 0.0) / 100.0;

        return $eiv * $runs * $costIndex * $taxMultiplier;
    }

    /**
     * Get last sync time for adjusted prices.
     */
    public function getAdjustedPricesLastSync(): ?\DateTimeImmutable
    {
        $cacheItem = $this->cache->getItem(self::ADJUSTED_PRICE_META_KEY);

        if (!$cacheItem->isHit()) {
            return null;
        }

        /** @var array{syncedAt: \DateTimeImmutable, count: int} $meta */
        $meta = $cacheItem->get();

        return $meta['syncedAt'];
    }

    /**
     * Get last sync time for cost indices.
     */
    public function getCostIndicesLastSync(): ?\DateTimeImmutable
    {
        $cacheItem = $this->cache->getItem(self::COST_INDEX_META_KEY);

        if (!$cacheItem->isHit()) {
            return null;
        }

        /** @var array{syncedAt: \DateTimeImmutable, count: int} $meta */
        $meta = $cacheItem->get();

        return $meta['syncedAt'];
    }
}
