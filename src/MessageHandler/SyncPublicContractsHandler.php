<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Constant\EveConstants;
use App\Message\SyncPublicContracts;
use App\Service\Admin\SyncTracker;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AsMessageHandler]
final readonly class SyncPublicContractsHandler
{
    private const ESI_BASE_URL = 'https://esi.evetech.net/latest';
    private const CACHE_PREFIX = 'public_contract_prices_';
    private const CACHE_TTL = 3600; // 1 hour
    private const META_KEY = 'public_contract_prices_meta';
    private const ITEMS_BATCH_SIZE = 50;

    public function __construct(
        private HttpClientInterface $httpClient,
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
        $contracts = $this->fetchAllPublicContracts();
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

        // 3. Fetch items for each contract in concurrent batches
        $contractItems = $this->fetchContractItemsBatched($itemExchangeContracts);

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
     * Fetch all pages of public contracts for The Forge region.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAllPublicContracts(): array
    {
        $allContracts = [];
        $page = 1;
        $totalPages = 1;

        do {
            $response = $this->httpClient->request('GET', self::ESI_BASE_URL . '/contracts/public/' . EveConstants::THE_FORGE_REGION_ID . '/', [
                'query' => ['page' => $page],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(sprintf(
                    'ESI /contracts/public/%d/ page %d returned HTTP %d',
                    EveConstants::THE_FORGE_REGION_ID,
                    $page,
                    $statusCode,
                ));
            }

            $headers = $response->getHeaders(false);
            $totalPages = (int) ($headers['x-pages'][0] ?? 1);

            /** @var list<array<string, mixed>> $contracts */
            $contracts = $response->toArray();
            $allContracts = array_merge($allContracts, $contracts);

            $this->logger->debug('Fetched public contracts page', [
                'page' => $page,
                'totalPages' => $totalPages,
                'count' => count($contracts),
            ]);

            $page++;

            // Small throttle between pages
            if ($page <= $totalPages) {
                usleep(50_000); // 50ms
            }
        } while ($page <= $totalPages);

        return $allContracts;
    }

    /**
     * Fetch contract items for multiple contracts using concurrent HTTP requests.
     *
     * @param list<array<string, mixed>> $contracts
     * @return array<int, list<array<string, mixed>>> Keyed by contract_id
     */
    private function fetchContractItemsBatched(array $contracts): array
    {
        $result = [];
        $batches = array_chunk($contracts, self::ITEMS_BATCH_SIZE);

        foreach ($batches as $batchIndex => $batch) {
            /** @var array<int, ResponseInterface> $responses */
            $responses = [];

            // Fire all requests in the batch concurrently
            foreach ($batch as $contract) {
                $contractId = (int) $contract['contract_id'];
                try {
                    $responses[$contractId] = $this->httpClient->request(
                        'GET',
                        self::ESI_BASE_URL . '/contracts/public/items/' . $contractId . '/',
                        [
                            'headers' => ['Accept' => 'application/json'],
                            'timeout' => 15,
                        ],
                    );
                } catch (\Throwable) {
                    // Skip contracts where request creation fails
                }
            }

            // Collect responses
            foreach ($responses as $contractId => $response) {
                try {
                    $statusCode = $response->getStatusCode();
                    if ($statusCode >= 200 && $statusCode < 300) {
                        /** @var list<array<string, mixed>> $items */
                        $items = $response->toArray();
                        $result[$contractId] = $items;
                    } else {
                        // Consume body to release connection
                        $response->getContent(false);
                    }
                } catch (\Throwable) {
                    // Individual contract item fetch failure is non-fatal
                }
            }

            if ($batchIndex < count($batches) - 1) {
                usleep(50_000); // 50ms between batches
            }
        }

        return $result;
    }
}
