<?php

declare(strict_types=1);

namespace App\Service\Industry;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read-only service for public contract prices.
 * Data is populated by SyncPublicContractsHandler.
 *
 * Cache structure per type_id:
 *   key: public_contract_prices_{typeId}
 *   value: list<array{unitPrice: float, quantity: int, contractId: int}>  (sorted by unitPrice ASC)
 */
class PublicContractPriceService
{
    public const CACHE_PREFIX = 'public_contract_prices_';
    public const META_KEY = 'public_contract_prices_meta';

    public function __construct(
        #[Autowire(service: 'public_contracts.cache')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Get the lowest per-unit price from cached mono-item contracts for a type.
     */
    public function getLowestUnitPrice(int $typeId): ?float
    {
        $contracts = $this->indexedContracts($typeId);

        if (empty($contracts)) {
            return null;
        }

        // Already sorted by unitPrice ASC
        return $contracts[0]['unitPrice'];
    }

    /**
     * Get the number of active mono-item contracts for a type.
     */
    public function getContractCount(int $typeId): ?int
    {
        $contracts = $this->indexedContracts($typeId);

        return null === $contracts ? null : count($contracts);
    }

    /**
     * @return list<array{unitPrice: float, quantity: int, contractId: int}>|null
     */
    private function indexedContracts(int $typeId): ?array
    {
        if (!$this->cache->getItem(self::META_KEY)->isHit()) {
            return null;
        }

        $cacheItem = $this->cache->getItem(self::CACHE_PREFIX . $typeId);

        if (!$cacheItem->isHit()) {
            return [];
        }

        /** @var list<array{unitPrice: float, quantity: int, contractId: int}> */
        return $cacheItem->get();
    }
}
