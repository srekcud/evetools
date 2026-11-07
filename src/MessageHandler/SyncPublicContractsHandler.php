<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Constant\EveConstants;
use App\Message\SyncPublicContracts;
use App\Service\Admin\SyncTracker;
use App\Service\ESI\EsiClient;
use App\Service\Industry\ContractSales\ObservedContract;
use App\Service\Industry\ContractSales\PublicContractHistoryRecorder;
use App\Service\Industry\ContractSales\PublicContractRegions;
use App\Service\Industry\PublicContractPriceService;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncPublicContractsHandler
{
    private const CACHE_TTL = 3600; // 1 hour
    private const ITEMS_CACHE_PREFIX = 'public_contract_items_';
    private const ITEMS_BATCH_SIZE = 50;

    public function __construct(
        private EsiClient $esiClient,
        #[Autowire(service: 'public_contracts.cache')]
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private SyncTracker $syncTracker,
        private PublicContractHistoryRecorder $historyRecorder,
        private PublicContractRegions $regions,
    ) {
    }

    public function __invoke(SyncPublicContracts $message): void
    {
        $this->syncTracker->start('public-contracts');
        $this->logger->info('Starting public contracts sync');

        try {
            $regions = $this->regions->resolve();
            $result = $this->sync($regions['regionIds']);

            $this->logger->info('Public contracts sync completed', [
                'types' => $result['indexedTypes'],
                'unreadContracts' => $result['unreadContracts'],
                'failedRegionIds' => $result['failedRegionIds'],
            ]);
            $summary = $this->summarize($result, $regions['unknownStructureIds']);
            if ($result['failedRegionIds'] !== []) {
                $this->syncTracker->fail('public-contracts', $summary);

                return;
            }
            $this->syncTracker->complete('public-contracts', $summary);
        } catch (\Throwable $e) {
            $this->logger->error('Public contracts sync failed', [
                'error' => $e->getMessage(),
            ]);
            $this->syncTracker->fail('public-contracts', $e->getMessage());
        }
    }

    /**
     * @param array{indexedTypes: int, unreadContracts: int, failedRegionIds: list<int>} $result
     * @param list<int> $unknownStructureIds
     */
    private function summarize(array $result, array $unknownStructureIds): string
    {
        $summary = $result['indexedTypes'] . ' types indexed';
        if ($result['unreadContracts'] > 0) {
            $summary .= ', ' . $result['unreadContracts'] . ' contracts unread';
        }
        if ($unknownStructureIds !== []) {
            $summary .= '; skipped (region unknown): ' . implode(', ', $unknownStructureIds);
        }
        if ($result['failedRegionIds'] !== []) {
            $summary .= '; failed regions: ' . implode(', ', $result['failedRegionIds']);
        }

        return $summary;
    }

    /**
     * @param list<int> $regionIds
     * @return array{indexedTypes: int, unreadContracts: int, failedRegionIds: list<int>}
     */
    private function sync(array $regionIds): array
    {
        $now = new \DateTimeImmutable();
        $indexedTypes = 0;
        $unreadContracts = 0;
        $failedRegionIds = [];

        foreach ($regionIds as $regionId) {
            try {
                [$contracts, $contractItems] = $this->pollRegion($regionId, $now);
            } catch (\Throwable $e) {
                if ($regionId === EveConstants::THE_FORGE_REGION_ID) {
                    throw $e;
                }
                $this->logger->error('Public contracts region sync failed', ['regionId' => $regionId, 'error' => $e->getMessage()]);
                $failedRegionIds[] = $regionId;
                continue;
            }

            if ($regionId === EveConstants::THE_FORGE_REGION_ID) {
                $indexedTypes = $this->indexPrices($contracts, $contractItems);
            }
            $this->recordHistory($regionId, $contracts, $contractItems, $now);
            $unreadContracts += count($contracts) - count($contractItems);
        }

        $this->historyRecorder->purgeExpiredHistory($now);

        return ['indexedTypes' => $indexedTypes, 'unreadContracts' => $unreadContracts, 'failedRegionIds' => $failedRegionIds];
    }

    /**
     * @return array{list<array<string, mixed>>, array<int, list<array<string, mixed>>>}
     */
    private function pollRegion(int $regionId, \DateTimeImmutable $now): array
    {
        /** @var list<array<string, mixed>> $contracts */
        $contracts = $this->esiClient->getPaginated('/contracts/public/' . $regionId . '/');
        $this->logger->info('Fetched public contracts', ['regionId' => $regionId, 'count' => count($contracts)]);

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

        $this->logger->info('Filtered item_exchange contracts', ['regionId' => $regionId, 'count' => count($itemExchangeContracts)]);

        return [$itemExchangeContracts, $this->fetchContractItems($itemExchangeContracts)];
    }

    /**
     * @param list<array<string, mixed>> $itemExchangeContracts
     * @param array<int, list<array<string, mixed>>> $contractItems
     */
    private function indexPrices(array $itemExchangeContracts, array $contractItems): int
    {
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

            $cacheItem = $this->cache->getItem(PublicContractPriceService::CACHE_PREFIX . $typeId);
            $cacheItem->set($entries);
            $cacheItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($cacheItem);
        }

        // 6. Store metadata
        $metaItem = $this->cache->getItem(PublicContractPriceService::META_KEY);
        $this->removeTypesWithoutContract($metaItem, array_keys($priceIndex));
        $metaItem->set([
            'syncedAt' => new \DateTimeImmutable(),
            'typesCount' => count($priceIndex),
            'typeIds' => array_keys($priceIndex),
            'contractsProcessed' => count($itemExchangeContracts),
        ]);
        $metaItem->expiresAfter(self::CACHE_TTL);
        $this->cache->save($metaItem);

        return count($priceIndex);
    }

    /**
     * @param list<array<string, mixed>> $itemExchangeContracts
     * @param array<int, list<array<string, mixed>>> $contractItems
     */
    private function recordHistory(int $regionId, array $itemExchangeContracts, array $contractItems, \DateTimeImmutable $now): void
    {
        $readableContracts = [];
        $listedContractIds = [];
        foreach ($itemExchangeContracts as $contract) {
            $contractId = (int) $contract['contract_id'];
            $listedContractIds[] = $contractId;

            $issuerId = $contract['issuer_id'] ?? null;
            $dateExpired = $contract['date_expired'] ?? null;
            $price = $contract['price'] ?? null;
            if ($issuerId === null || $dateExpired === null || $price === null || !isset($contractItems[$contractId])) {
                continue;
            }

            $includedItems = [];
            foreach ($contractItems[$contractId] as $item) {
                if (($item['is_included'] ?? true) === true) {
                    $includedItems[] = ['typeId' => (int) $item['type_id'], 'quantity' => (int) ($item['quantity'] ?? 1)];
                }
            }

            $readableContracts[] = new ObservedContract($contractId, (int) $issuerId, (float) $price, $includedItems, new \DateTimeImmutable($dateExpired));
        }

        $this->historyRecorder->record($regionId, $readableContracts, $listedContractIds, $now);
    }

    /**
     * @param list<int> $indexedTypeIds
     */
    private function removeTypesWithoutContract(CacheItemInterface $previousMetaItem, array $indexedTypeIds): void
    {
        if (!$previousMetaItem->isHit()) {
            return;
        }

        /** @var array{typeIds?: list<int>} $previousMeta */
        $previousMeta = $previousMetaItem->get();
        $typeIdsWithoutContract = array_diff($previousMeta['typeIds'] ?? [], $indexedTypeIds);

        $this->cache->deleteItems(array_map(
            static fn (int $typeId): string => PublicContractPriceService::CACHE_PREFIX . $typeId,
            $typeIdsWithoutContract,
        ));
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
        $contractsToFetch = [];

        foreach ($contracts as $contract) {
            $contractId = (int) $contract['contract_id'];
            $itemsCacheItem = $this->cache->getItem(self::ITEMS_CACHE_PREFIX . $contractId);
            if ($itemsCacheItem->isHit()) {
                /** @var list<array<string, mixed>> $cachedItems */
                $cachedItems = $itemsCacheItem->get();
                $result[$contractId] = $cachedItems;
                continue;
            }

            $contractsToFetch[$contractId] = $contract;
        }

        foreach (array_chunk($contractsToFetch, self::ITEMS_BATCH_SIZE, true) as $batch) {
            $endpoints = [];
            foreach (array_keys($batch) as $contractId) {
                $endpoints[$contractId] = '/contracts/public/items/' . $contractId . '/';
            }

            foreach ($this->esiClient->getBatch($endpoints) as $contractId => $items) {
                if ($items === null) {
                    $this->logger->warning('Skipping public contract whose items could not be fetched', [
                        'contractId' => $contractId,
                    ]);
                    continue;
                }

                /** @var list<array<string, mixed>> $items */
                $result[$contractId] = $items;
                $this->cacheItemsUntilContractExpires($batch[$contractId], $items);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $contract
     * @param list<array<string, mixed>> $items
     */
    private function cacheItemsUntilContractExpires(array $contract, array $items): void
    {
        $dateExpired = $contract['date_expired'] ?? null;
        if ($dateExpired === null) {
            return;
        }

        $itemsCacheItem = $this->cache->getItem(self::ITEMS_CACHE_PREFIX . $contract['contract_id']);
        $itemsCacheItem->set($items);
        $itemsCacheItem->expiresAt(new \DateTimeImmutable($dateExpired));
        $this->cache->save($itemsCacheItem);
    }
}
