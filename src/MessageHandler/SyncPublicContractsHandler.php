<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Constant\EveConstants;
use App\Message\SyncPublicContracts;
use App\Service\Admin\SyncTracker;
use App\Service\ESI\EsiClient;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncPublicContractsHandler
{
    private const CACHE_PREFIX = 'public_contract_prices_';
    private const CACHE_TTL = 3600; // 1 hour
    private const META_KEY = 'public_contract_prices_meta';
    private const ITEMS_BATCH_SIZE = 50;

    public function __construct(
        private EsiClient $esiClient,
        #[Autowire(service: 'public_contracts.cache')]
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
    ) {
    }

    public function __invoke(SyncPublicContracts $message): void
    {
        $this->syncTracker->start('public-contracts');
        $this->logger->info('Starting public contracts sync for The Forge');

        try {
            $count = $this->sync();

            $this->logger->info('Public contracts sync completed', ['types' => $count]);
            $this->syncTracker->complete('public-contracts', $count . ' types indexed');
        } catch (\Throwable $e) {
            $this->logger->error('Public contracts sync failed', [
                'error' => $e->getMessage(),
            ]);
            $this->syncTracker->fail('public-contracts', $e->getMessage());
        }
    }

    private function sync(): int
    {
        // 1. Fetch all pages of public contracts for The Forge
        /** @var list<array<string, mixed>> $contracts */
        $contracts = $this->esiClient->getPaginated('/contracts/public/' . EveConstants::THE_FORGE_REGION_ID . '/');
        $this->logger->info('Fetched public contracts', ['count' => count($contracts)]);

        // 2. Filter: item_exchange only, not expired
        $now = new \DateTimeImmutable();
        $itemExchangeContracts = [];
        foreach ($contracts as $contract) {
            if (($contract['type'] ?? '') !== 'item_exchange') {
                continue;
            }

            $dateExpired = $contract['date_expired'] ?? null;
            if ($dateExpired !== null) {
                try {
                    $expiresAt = new \DateTimeImmutable($dateExpired);
                    if ($expiresAt <= $now) {
                        continue;
                    }
                } catch (\Exception) {
                    continue;
                }
            }

            $itemExchangeContracts[] = $contract;
        }

        $this->logger->info('Filtered item_exchange contracts', ['count' => count($itemExchangeContracts)]);

        // 3. Fetch items for each contract
        $contractItems = $this->fetchContractItems($itemExchangeContracts);

        // 4. Filter for mono-item contracts and compute unit prices
        // Index: typeId => list<{unitPrice, quantity, contractId}>
        /** @var array<int, list<array{unitPrice: float, quantity: int, contractId: int}>> $priceIndex */
        $priceIndex = [];

        foreach ($itemExchangeContracts as $contract) {
            $contractId = $contract['contract_id'];
            $items = $contractItems[$contractId] ?? null;

            if ($items === null || empty($items)) {
                continue;
            }

            $price = (float) ($contract['price'] ?? 0.0);
            if ($price <= 0.0) {
                continue;
            }

            // Get all included items
            $includedItems = array_filter($items, fn (array $item): bool => ($item['is_included'] ?? true) === true);
            if (empty($includedItems)) {
                continue;
            }

            // Check if all included items share the same type_id (mono-item contract)
            $typeIds = array_unique(array_column($includedItems, 'type_id'));
            if (count($typeIds) !== 1) {
                continue;
            }

            $typeId = (int) $typeIds[0];
            $totalQuantity = 0;
            foreach ($includedItems as $item) {
                $totalQuantity += (int) ($item['quantity'] ?? 1);
            }

            if ($totalQuantity <= 0) {
                continue;
            }

            $unitPrice = $price / $totalQuantity;

            $priceIndex[$typeId][] = [
                'unitPrice' => $unitPrice,
                'quantity' => $totalQuantity,
                'contractId' => $contractId,
            ];
        }

        // 5. Sort each type's contracts by unitPrice ASC and store in cache
        foreach ($priceIndex as $typeId => $entries) {
            usort($entries, fn (array $a, array $b): int => $a['unitPrice'] <=> $b['unitPrice']);

            $cacheItem = $this->cache->getItem(self::CACHE_PREFIX . $typeId);
            $cacheItem->set($entries);
            $cacheItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($cacheItem);
        }

        // 6. Store metadata
        $metaItem = $this->cache->getItem(self::META_KEY);
        $metaItem->set([
            'syncedAt' => new \DateTimeImmutable(),
            'typesCount' => count($priceIndex),
            'contractsProcessed' => count($itemExchangeContracts),
        ]);
        $metaItem->expiresAfter(self::CACHE_TTL);
        $this->cache->save($metaItem);

        return count($priceIndex);
    }

    /**
     * Fetch items for each contract in concurrent ESI batches. A contract whose items
     * cannot be fetched (expired or accepted since the listing, ESI error) is skipped.
     *
     * @param list<array<string, mixed>> $contracts
     * @return array<int, list<array<string, mixed>>> Keyed by contract_id
     */
    private function fetchContractItems(array $contracts): array
    {
        $result = [];

        foreach (array_chunk($contracts, self::ITEMS_BATCH_SIZE) as $batch) {
            $endpoints = [];
            foreach ($batch as $contract) {
                $contractId = (int) $contract['contract_id'];
                $endpoints[$contractId] = '/contracts/public/items/' . $contractId . '/';
            }

            foreach ($this->esiClient->getBatch($endpoints) as $contractId => $items) {
                if ($items === null) {
                    $this->logger->debug('Skipping public contract whose items could not be fetched', [
                        'contractId' => $contractId,
                    ]);
                    continue;
                }

                /** @var list<array<string, mixed>> $items */
                $result[$contractId] = $items;
            }
        }

        return $result;
    }
}
