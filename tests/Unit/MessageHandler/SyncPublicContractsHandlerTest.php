<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\SyncPublicContracts;
use App\MessageHandler\SyncPublicContractsHandler;
use App\Service\Admin\SyncTracker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(SyncPublicContractsHandler::class)]
#[AllowMockObjectsWithoutExpectations]
class SyncPublicContractsHandlerTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private CacheItemPoolInterface&MockObject $cache;
    private SyncTracker&MockObject $syncTracker;
    private SyncPublicContractsHandler $handler;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->syncTracker = $this->createMock(SyncTracker::class);

        $this->handler = new SyncPublicContractsHandler(
            $this->httpClient,
            $this->cache,
            new NullLogger(),
            $this->syncTracker,
        );
    }

    // ===========================================
    // Full sync — mono-item contract produces cache entry
    // ===========================================

    public function testMonoItemContractProducesUnitPrice(): void
    {
        $contractId = 10001;

        // Page 1 of contracts (single page)
        $contractsResponse = $this->createResponse(200, [
            $this->makeContract($contractId, 'item_exchange', 1_000_000.0),
        ], ['x-pages' => ['1']]);

        // Items response for the contract
        $itemsResponse = $this->createResponse(200, [
            ['type_id' => 34, 'quantity' => 100, 'is_included' => true],
        ]);

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($contractsResponse, $itemsResponse, $contractId): ResponseInterface {
                if (str_contains($url, '/contracts/public/10000002/')) {
                    return $contractsResponse;
                }
                if (str_contains($url, "/contracts/public/items/{$contractId}/")) {
                    return $itemsResponse;
                }
                return $this->createResponse(404, []);
            });

        // Expect cache saves: 1 for the type, 1 for metadata
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $savedValues = [];
        $cacheItem->method('set')->willReturnCallback(function ($value) use (&$savedValues, $cacheItem): CacheItemInterface {
            $savedValues[] = $value;
            return $cacheItem;
        });
        $cacheItem->method('expiresAfter')->willReturnSelf();

        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->expects($this->exactly(2))->method('save');

        $this->syncTracker->expects($this->once())->method('start');
        $this->syncTracker->expects($this->once())->method('complete');

        $this->handler->__invoke(new SyncPublicContracts());

        // Verify the price entry was cached
        $this->assertNotEmpty($savedValues);
        // First save is the type price index, second is metadata
        $typeEntries = $savedValues[0];
        $this->assertCount(1, $typeEntries);
        $this->assertSame(10_000.0, $typeEntries[0]['unitPrice']); // 1M / 100 = 10K
        $this->assertSame(100, $typeEntries[0]['quantity']);
    }

    public function testMultiItemContractFilteredOut(): void
    {
        $contractId = 10002;

        $contractsResponse = $this->createResponse(200, [
            $this->makeContract($contractId, 'item_exchange', 5_000_000.0),
        ], ['x-pages' => ['1']]);

        // Contract has 2 different item types
        $itemsResponse = $this->createResponse(200, [
            ['type_id' => 34, 'quantity' => 100, 'is_included' => true],
            ['type_id' => 35, 'quantity' => 200, 'is_included' => true],
        ]);

        $this->httpClient->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($contractsResponse, $itemsResponse, $contractId): ResponseInterface {
                if (str_contains($url, '/contracts/public/10000002/')) {
                    return $contractsResponse;
                }
                if (str_contains($url, "/contracts/public/items/{$contractId}/")) {
                    return $itemsResponse;
                }
                return $this->createResponse(404, []);
            });

        // Only metadata should be saved (no types)
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('set')->willReturnSelf();
        $cacheItem->method('expiresAfter')->willReturnSelf();

        $this->cache->method('getItem')->willReturn($cacheItem);
        // Only 1 save: the metadata entry (no type entries since multi-item is filtered)
        $this->cache->expects($this->once())->method('save');

        $this->handler->__invoke(new SyncPublicContracts());
    }

    public function testAuctionContractsFilteredOut(): void
    {
        $contractsResponse = $this->createResponse(200, [
            $this->makeContract(10003, 'auction', 1_000_000.0),
        ], ['x-pages' => ['1']]);

        $this->httpClient->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($contractsResponse): ResponseInterface {
                if (str_contains($url, '/contracts/public/10000002/')) {
                    return $contractsResponse;
                }
                return $this->createResponse(404, []);
            });

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('set')->willReturnSelf();
        $cacheItem->method('expiresAfter')->willReturnSelf();

        $this->cache->method('getItem')->willReturn($cacheItem);
        // Only metadata (no types indexed since auction was filtered)
        $this->cache->expects($this->once())->method('save');

        $this->handler->__invoke(new SyncPublicContracts());
    }

    public function testExpiredContractsFilteredOut(): void
    {
        $contractsResponse = $this->createResponse(200, [
            $this->makeContract(10004, 'item_exchange', 1_000_000.0, (new \DateTimeImmutable('-1 day'))->format('c')),
        ], ['x-pages' => ['1']]);

        $this->httpClient->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($contractsResponse): ResponseInterface {
                if (str_contains($url, '/contracts/public/10000002/')) {
                    return $contractsResponse;
                }
                return $this->createResponse(404, []);
            });

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('set')->willReturnSelf();
        $cacheItem->method('expiresAfter')->willReturnSelf();

        $this->cache->method('getItem')->willReturn($cacheItem);
        // Only metadata
        $this->cache->expects($this->once())->method('save');

        $this->handler->__invoke(new SyncPublicContracts());
    }

    public function testZeroPriceContractFilteredOut(): void
    {
        $contractId = 10005;

        $contractsResponse = $this->createResponse(200, [
            $this->makeContract($contractId, 'item_exchange', 0.0),
        ], ['x-pages' => ['1']]);

        $itemsResponse = $this->createResponse(200, [
            ['type_id' => 34, 'quantity' => 100, 'is_included' => true],
        ]);

        $this->httpClient->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($contractsResponse, $itemsResponse, $contractId): ResponseInterface {
                if (str_contains($url, '/contracts/public/10000002/')) {
                    return $contractsResponse;
                }
                if (str_contains($url, "/contracts/public/items/{$contractId}/")) {
                    return $itemsResponse;
                }
                return $this->createResponse(404, []);
            });

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('set')->willReturnSelf();
        $cacheItem->method('expiresAfter')->willReturnSelf();

        $this->cache->method('getItem')->willReturn($cacheItem);
        // Only metadata
        $this->cache->expects($this->once())->method('save');

        $this->handler->__invoke(new SyncPublicContracts());
    }

    public function testMonoItemContractWithMultipleStacksSameType(): void
    {
        $contractId = 10006;

        $contractsResponse = $this->createResponse(200, [
            $this->makeContract($contractId, 'item_exchange', 2_000_000.0),
        ], ['x-pages' => ['1']]);

        // Same type_id but in two stacks
        $itemsResponse = $this->createResponse(200, [
            ['type_id' => 34, 'quantity' => 100, 'is_included' => true],
            ['type_id' => 34, 'quantity' => 150, 'is_included' => true],
        ]);

        $this->httpClient->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($contractsResponse, $itemsResponse, $contractId): ResponseInterface {
                if (str_contains($url, '/contracts/public/10000002/')) {
                    return $contractsResponse;
                }
                if (str_contains($url, "/contracts/public/items/{$contractId}/")) {
                    return $itemsResponse;
                }
                return $this->createResponse(404, []);
            });

        $savedValues = [];
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('set')->willReturnCallback(function ($value) use (&$savedValues, $cacheItem): CacheItemInterface {
            $savedValues[] = $value;
            return $cacheItem;
        });
        $cacheItem->method('expiresAfter')->willReturnSelf();

        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->expects($this->exactly(2))->method('save');

        $this->handler->__invoke(new SyncPublicContracts());

        // Total quantity = 100 + 150 = 250, unit price = 2M / 250 = 8000
        $typeEntries = $savedValues[0];
        $this->assertSame(8_000.0, $typeEntries[0]['unitPrice']);
        $this->assertSame(250, $typeEntries[0]['quantity']);
    }

    public function testSyncTrackerNotifiedOnError(): void
    {
        // Simulate ESI error
        $this->httpClient->method('request')
            ->willThrowException(new \RuntimeException('ESI down'));

        $this->syncTracker->expects($this->once())->method('start');
        $this->syncTracker->expects($this->once())->method('fail');
        $this->syncTracker->expects($this->never())->method('complete');

        $this->handler->__invoke(new SyncPublicContracts());
    }

    // ===========================================
    // Pagination
    // ===========================================

    public function testPaginationFetchesAllPages(): void
    {
        $page1Response = $this->createResponse(200, [
            $this->makeContract(20001, 'item_exchange', 500_000.0),
        ], ['x-pages' => ['2']]);

        $page2Response = $this->createResponse(200, [
            $this->makeContract(20002, 'item_exchange', 1_000_000.0),
        ], ['x-pages' => ['2']]);

        $items1Response = $this->createResponse(200, [
            ['type_id' => 34, 'quantity' => 50, 'is_included' => true],
        ]);
        $items2Response = $this->createResponse(200, [
            ['type_id' => 34, 'quantity' => 100, 'is_included' => true],
        ]);

        $requestCount = 0;
        $this->httpClient->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (
                &$requestCount,
                $page1Response,
                $page2Response,
                $items1Response,
                $items2Response,
            ): ResponseInterface {
                $requestCount++;

                if (str_contains($url, '/contracts/public/10000002/')) {
                    $page = $options['query']['page'] ?? 1;
                    return $page === 1 ? $page1Response : $page2Response;
                }
                if (str_contains($url, '/contracts/public/items/20001/')) {
                    return $items1Response;
                }
                if (str_contains($url, '/contracts/public/items/20002/')) {
                    return $items2Response;
                }
                return $this->createResponse(404, []);
            });

        $savedValues = [];
        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('set')->willReturnCallback(function ($value) use (&$savedValues, $cacheItem): CacheItemInterface {
            $savedValues[] = $value;
            return $cacheItem;
        });
        $cacheItem->method('expiresAfter')->willReturnSelf();

        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save');

        $this->handler->__invoke(new SyncPublicContracts());

        // Should have fetched 2 pages + 2 item requests = 4 total
        $this->assertSame(4, $requestCount);

        // Both contracts index the same type 34, so there should be 2 entries
        $typeEntries = $savedValues[0];
        $this->assertCount(2, $typeEntries);

        // Sorted by unitPrice ASC
        $this->assertSame(10_000.0, $typeEntries[0]['unitPrice']); // 500K / 50
        $this->assertSame(10_000.0, $typeEntries[1]['unitPrice']); // 1M / 100
    }

    // ===========================================
    // Helpers
    // ===========================================

    /**
     * @param array<mixed> $body
     * @param array<string, list<string>> $headers
     */
    private function createResponse(int $statusCode, array $body, array $headers = []): ResponseInterface&MockObject
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('toArray')->willReturn($body);
        $response->method('getHeaders')->willReturn($headers);
        $response->method('getContent')->willReturn(json_encode($body));

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeContract(int $contractId, string $type, float $price, ?string $dateExpired = null): array
    {
        return [
            'contract_id' => $contractId,
            'type' => $type,
            'price' => $price,
            'date_expired' => $dateExpired ?? (new \DateTimeImmutable('+7 days'))->format('c'),
            'date_issued' => (new \DateTimeImmutable('-1 day'))->format('c'),
        ];
    }
}
