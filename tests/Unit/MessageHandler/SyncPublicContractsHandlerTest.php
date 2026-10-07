<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\SyncPublicContracts;
use App\MessageHandler\SyncPublicContractsHandler;
use App\Service\Admin\SyncTracker;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Issue #25: the handler must read ESI through EsiClient (ESI_BASE_URL, error-limit
 * tracking, 420/429 retry) instead of its own HttpClientInterface.
 *
 * Issue #26: contract items are fetched through concurrent EsiClient batches, not one
 * sequential ESI call per contract, so a sync stays well within its 30-minute interval.
 *
 * ESI is simulated at the HTTP boundary (MockHttpClient) behind a real EsiClient.
 */
#[CoversClass(SyncPublicContractsHandler::class)]
final class SyncPublicContractsHandlerTest extends TestCase
{
    private const PRODUCTION_ESI_BASE_URL = 'https://esi.evetech.net/latest';
    private const CONFIGURED_ESI_BASE_URL = 'https://esi.test/latest';
    private const THE_FORGE_CONTRACTS_PATH = '/contracts/public/10000002/';
    private const SYNC_TYPE = 'public-contracts';

    private const TRITANIUM = 34;
    private const PYERITE = 35;
    private const MEXALLON = 36;
    private const ISOGEN = 37;

    private ArrayAdapter $publicContractsCache;

    /** @var list<string> */
    private array $requestedUrls = [];

    /** @var list<string> "request <path>" when an ESI request is launched, "read <path>" when contract items are consumed */
    private array $esiEvents = [];

    /** @var list<array{string, string, ?string}> */
    private array $syncTrackerCalls = [];

    protected function setUp(): void
    {
        $this->publicContractsCache = new ArrayAdapter();
        $this->requestedUrls = [];
        $this->esiEvents = [];
        $this->syncTrackerCalls = [];
    }

    // ---------------------------------------------------------------
    // GREEN guards: current behavior, must survive the switch to EsiClient
    // ---------------------------------------------------------------

    public function testContractsFromTwoPagesAreIndexedByTypeWithExactUnitPrices(): void
    {
        $handler = $this->createHandler($this->twoPagesOfTheForgeContracts(), self::PRODUCTION_ESI_BASE_URL);

        $handler(new SyncPublicContracts());

        $this->assertSame([
            ['unitPrice' => 5_000.0, 'quantity' => 60, 'contractId' => 20003],  // 300K / 60, page 2
            ['unitPrice' => 10_000.0, 'quantity' => 50, 'contractId' => 20001], // 500K / 50, page 1
        ], $this->storedContractPrices(self::TRITANIUM));
        $this->assertSame([
            ['unitPrice' => 8_000.0, 'quantity' => 250, 'contractId' => 20007], // 2M / (100 + 150)
        ], $this->storedContractPrices(self::PYERITE));
        $this->assertSame([
            ['unitPrice' => 1_000.0, 'quantity' => 40, 'contractId' => 20008],  // 40K / 40, excluded item ignored
        ], $this->storedContractPrices(self::MEXALLON));
        $this->assertNull($this->storedContractPrices(self::ISOGEN));

        $meta = $this->publicContractsCache->getItem('public_contract_prices_meta')->get();
        $this->assertSame(3, $meta['typesCount']);
        $this->assertSame(6, $meta['contractsProcessed']); // 20001, 20003, 20005, 20006, 20007, 20008

        $this->assertSame([
            ['start', self::SYNC_TYPE, null],
            ['complete', self::SYNC_TYPE, '3 types indexed'],
        ], $this->syncTrackerCalls);
    }

    public function testItemsAreFetchedOnlyForUnexpiredItemExchangeContracts(): void
    {
        $handler = $this->createHandler($this->twoPagesOfTheForgeContracts(), self::PRODUCTION_ESI_BASE_URL);

        $handler(new SyncPublicContracts());

        // 20002 (auction) and 20004 (expired) are never looked at.
        $this->assertSame([20001, 20003, 20005, 20006, 20007, 20008], $this->contractIdsWhoseItemsWereRequested());
    }

    public function testContractWhoseItemsCannotBeFetchedIsSkippedWithoutFailingTheSync(): void
    {
        $handler = $this->createHandler([
            self::THE_FORGE_CONTRACTS_PATH => [[
                $this->makeContract(30001, 'item_exchange', 1_000_000.0),
                $this->makeContract(30002, 'item_exchange', 900_000.0),
            ]],
            '/contracts/public/items/30001/' => new MockResponse('{"error":"Contract not found"}', ['http_code' => 404]),
            '/contracts/public/items/30002/' => [['type_id' => self::TRITANIUM, 'quantity' => 100, 'is_included' => true]],
        ], self::PRODUCTION_ESI_BASE_URL);

        $handler(new SyncPublicContracts());

        $this->assertSame([
            ['unitPrice' => 9_000.0, 'quantity' => 100, 'contractId' => 30002],
        ], $this->storedContractPrices(self::TRITANIUM));
        $this->assertSame([
            ['start', self::SYNC_TYPE, null],
            ['complete', self::SYNC_TYPE, '1 types indexed'],
        ], $this->syncTrackerCalls);
    }

    public function testContractsPageErrorFailsTheSyncAndStoresNothing(): void
    {
        $handler = $this->createHandler([
            self::THE_FORGE_CONTRACTS_PATH => [
                $this->jsonResponse([$this->makeContract(40001, 'item_exchange', 1_000_000.0)], ['X-Pages' => '2']),
                new MockResponse('{"error":"Internal server error"}', ['http_code' => 500]),
            ],
            '/contracts/public/items/40001/' => [['type_id' => self::TRITANIUM, 'quantity' => 100, 'is_included' => true]],
        ], self::PRODUCTION_ESI_BASE_URL);

        $handler(new SyncPublicContracts());

        $this->assertCount(2, $this->syncTrackerCalls);
        $this->assertSame('fail', $this->syncTrackerCalls[1][0]);
        $this->assertSame(self::SYNC_TYPE, $this->syncTrackerCalls[1][1]);
        $this->assertNull($this->storedContractPrices(self::TRITANIUM));
        $this->assertFalse($this->publicContractsCache->getItem('public_contract_prices_meta')->isHit());
    }

    // ---------------------------------------------------------------
    // RED: issue #25, ESI must be read through EsiClient
    // ---------------------------------------------------------------

    public function testHandlerConstructorTakesEsiClientInsteadOfHttpClient(): void
    {
        $parameterTypes = $this->constructorParameterTypes();

        $this->assertNotContains(HttpClientInterface::class, $parameterTypes);
        $this->assertContains(EsiClient::class, $parameterTypes);
    }

    public function testContractsAndItemsAreReadFromTheConfiguredEsiBaseUrl(): void
    {
        $handler = $this->createHandler([
            self::THE_FORGE_CONTRACTS_PATH => [[$this->makeContract(50001, 'item_exchange', 1_000_000.0)]],
            '/contracts/public/items/50001/' => [['type_id' => self::TRITANIUM, 'quantity' => 100, 'is_included' => true]],
        ], self::CONFIGURED_ESI_BASE_URL);

        $handler(new SyncPublicContracts());

        $this->assertSame([
            ['unitPrice' => 10_000.0, 'quantity' => 100, 'contractId' => 50001],
        ], $this->storedContractPrices(self::TRITANIUM));
        foreach ($this->requestedUrls as $requestedUrl) {
            $this->assertStringStartsWith(self::CONFIGURED_ESI_BASE_URL . '/', $requestedUrl);
        }
    }

    public function testRateLimitedContractsPageIsRetriedOnce(): void
    {
        $handler = $this->createHandler([
            self::THE_FORGE_CONTRACTS_PATH => [
                new MockResponse('{"error":"Too many requests"}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '0']]),
                $this->jsonResponse([$this->makeContract(60001, 'item_exchange', 1_000_000.0)]),
            ],
            '/contracts/public/items/60001/' => [['type_id' => self::TRITANIUM, 'quantity' => 100, 'is_included' => true]],
        ], self::PRODUCTION_ESI_BASE_URL);

        $handler(new SyncPublicContracts());

        $this->assertSame([
            ['unitPrice' => 10_000.0, 'quantity' => 100, 'contractId' => 60001],
        ], $this->storedContractPrices(self::TRITANIUM));
        $this->assertSame([
            ['start', self::SYNC_TYPE, null],
            ['complete', self::SYNC_TYPE, '1 types indexed'],
        ], $this->syncTrackerCalls);
    }

    public function testPublicContractsAreReadWithEsiClientPagination(): void
    {
        $tritaniumItems = [['type_id' => self::TRITANIUM, 'quantity' => 100, 'is_included' => true]];
        $paginatedEndpoints = [];

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')
            ->willReturnCallback(function (string $endpoint) use (&$paginatedEndpoints): array {
                $paginatedEndpoints[] = $endpoint;

                return match ($endpoint) {
                    self::THE_FORGE_CONTRACTS_PATH => [
                        $this->makeContract(70001, 'item_exchange', 1_000_000.0),
                        $this->makeContract(70002, 'auction', 1_000_000.0),
                    ],
                    default => throw new \LogicException('Unexpected ESI endpoint: ' . $endpoint),
                };
            });
        // The items endpoints are read in one concurrent batch, keyed by contractId.
        $esiClient->method('getBatch')
            ->willReturnCallback(fn (array $endpoints): array => match ($endpoints) {
                [70001 => '/contracts/public/items/70001/'] => [70001 => $tritaniumItems],
                default => throw new \LogicException('Unexpected ESI batch: ' . json_encode($endpoints)),
            });

        $handler = new SyncPublicContractsHandler(
            esiClient: $esiClient,
            cache: $this->publicContractsCache,
            logger: new NullLogger(),
            syncTracker: $this->createRecordingSyncTracker(),
        );

        $handler(new SyncPublicContracts());

        $this->assertSame(
            [self::THE_FORGE_CONTRACTS_PATH],
            array_values(array_filter($paginatedEndpoints, static fn (string $endpoint): bool => $endpoint === self::THE_FORGE_CONTRACTS_PATH)),
        );
        $this->assertSame([
            ['unitPrice' => 10_000.0, 'quantity' => 100, 'contractId' => 70001],
        ], $this->storedContractPrices(self::TRITANIUM));
    }

    // ---------------------------------------------------------------
    // RED: issue #26, contract items are fetched in concurrent batches
    // ---------------------------------------------------------------

    public function testContractItemsAreAllRequestedBeforeTheFirstItemsResponseIsRead(): void
    {
        $handler = $this->createHandler([
            self::THE_FORGE_CONTRACTS_PATH => [[
                $this->makeContract(80001, 'item_exchange', 1_000_000.0),
                $this->makeContract(80002, 'item_exchange', 900_000.0),
                $this->makeContract(80003, 'item_exchange', 400_000.0),
            ]],
            '/contracts/public/items/80001/' => [['type_id' => self::TRITANIUM, 'quantity' => 100, 'is_included' => true]],
            '/contracts/public/items/80002/' => [['type_id' => self::PYERITE, 'quantity' => 300, 'is_included' => true]],
            '/contracts/public/items/80003/' => [['type_id' => self::MEXALLON, 'quantity' => 50, 'is_included' => true]],
        ], self::CONFIGURED_ESI_BASE_URL);

        $handler(new SyncPublicContracts());

        // Concurrent batch: the three items requests are in flight before any items body is read.
        $this->assertSame([
            'request /contracts/public/items/80001/',
            'request /contracts/public/items/80002/',
            'request /contracts/public/items/80003/',
        ], array_slice($this->contractItemsEvents(), 0, 3));
        $this->assertCount(6, $this->contractItemsEvents());
        foreach ($this->requestedUrls as $requestedUrl) {
            $this->assertStringStartsWith(self::CONFIGURED_ESI_BASE_URL . '/', $requestedUrl);
        }
        $this->assertSame([
            ['unitPrice' => 10_000.0, 'quantity' => 100, 'contractId' => 80001],
        ], $this->storedContractPrices(self::TRITANIUM));
        $this->assertSame([
            ['unitPrice' => 3_000.0, 'quantity' => 300, 'contractId' => 80002],
        ], $this->storedContractPrices(self::PYERITE));
        $this->assertSame([
            ['unitPrice' => 8_000.0, 'quantity' => 50, 'contractId' => 80003],
        ], $this->storedContractPrices(self::MEXALLON));
    }

    public function testContractWhoseItemsFailInsideABatchIsSkippedWithoutBreakingTheOthers(): void
    {
        $handler = $this->createHandler([
            self::THE_FORGE_CONTRACTS_PATH => [[
                $this->makeContract(90001, 'item_exchange', 1_000_000.0),
                $this->makeContract(90002, 'item_exchange', 900_000.0),
                $this->makeContract(90003, 'item_exchange', 400_000.0),
            ]],
            '/contracts/public/items/90001/' => [['type_id' => self::TRITANIUM, 'quantity' => 100, 'is_included' => true]],
            '/contracts/public/items/90002/' => new MockResponse('{"error":"Internal server error"}', ['http_code' => 500]),
            '/contracts/public/items/90003/' => [['type_id' => self::TRITANIUM, 'quantity' => 50, 'is_included' => true]],
        ], self::CONFIGURED_ESI_BASE_URL);

        $handler(new SyncPublicContracts());

        // The failing contract does not stop the batch: 90003 is requested before 90001 is read.
        $this->assertSame([
            'request /contracts/public/items/90001/',
            'request /contracts/public/items/90002/',
            'request /contracts/public/items/90003/',
        ], array_slice($this->contractItemsEvents(), 0, 3));
        $this->assertSame([
            ['unitPrice' => 8_000.0, 'quantity' => 50, 'contractId' => 90003],
            ['unitPrice' => 10_000.0, 'quantity' => 100, 'contractId' => 90001],
        ], $this->storedContractPrices(self::TRITANIUM));
        $meta = $this->publicContractsCache->getItem('public_contract_prices_meta')->get();
        $this->assertSame(1, $meta['typesCount']);
        $this->assertSame([
            ['start', self::SYNC_TYPE, null],
            ['complete', self::SYNC_TYPE, '1 types indexed'],
        ], $this->syncTrackerCalls);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Page 1: 20001 mono-item, 20002 auction. Page 2: 20003 mono-item, 20004 expired,
     * 20005 multi-type, 20006 zero price, 20007 two stacks of one type, 20008 one type + excluded item.
     *
     * @return array<string, list<mixed>>
     */
    private function twoPagesOfTheForgeContracts(): array
    {
        return [
            self::THE_FORGE_CONTRACTS_PATH => [
                $this->jsonResponse([
                    $this->makeContract(20001, 'item_exchange', 500_000.0),
                    $this->makeContract(20002, 'auction', 1_000_000.0),
                ], ['X-Pages' => '2']),
                $this->jsonResponse([
                    $this->makeContract(20003, 'item_exchange', 300_000.0),
                    $this->makeContract(20004, 'item_exchange', 100_000.0, (new \DateTimeImmutable('-1 day'))->format('c')),
                    $this->makeContract(20005, 'item_exchange', 5_000_000.0),
                    $this->makeContract(20006, 'item_exchange', 0.0),
                    $this->makeContract(20007, 'item_exchange', 2_000_000.0),
                    $this->makeContract(20008, 'item_exchange', 40_000.0),
                ], ['X-Pages' => '2']),
            ],
            '/contracts/public/items/20001/' => [['type_id' => self::TRITANIUM, 'quantity' => 50, 'is_included' => true]],
            '/contracts/public/items/20003/' => [['type_id' => self::TRITANIUM, 'quantity' => 60, 'is_included' => true]],
            '/contracts/public/items/20005/' => [
                ['type_id' => self::TRITANIUM, 'quantity' => 100, 'is_included' => true],
                ['type_id' => self::PYERITE, 'quantity' => 200, 'is_included' => true],
            ],
            '/contracts/public/items/20006/' => [['type_id' => self::ISOGEN, 'quantity' => 100, 'is_included' => true]],
            '/contracts/public/items/20007/' => [
                ['type_id' => self::PYERITE, 'quantity' => 100, 'is_included' => true],
                ['type_id' => self::PYERITE, 'quantity' => 150, 'is_included' => true],
            ],
            '/contracts/public/items/20008/' => [
                ['type_id' => self::MEXALLON, 'quantity' => 40, 'is_included' => true],
                ['type_id' => self::ISOGEN, 'quantity' => 5, 'is_included' => false],
            ],
        ];
    }

    /**
     * Builds the handler on a real EsiClient pointed at $esiBaseUrl and the simulated ESI.
     *
     * @param array<string, list<mixed>|MockResponse> $esiResponsesByPath contracts list: one entry
     *        per page or call; contract items: the JSON body; or a single MockResponse
     */
    private function createHandler(array $esiResponsesByPath, string $esiBaseUrl): SyncPublicContractsHandler
    {
        $esiHttpClient = $this->createSimulatedEsi($esiResponsesByPath, $esiBaseUrl);
        $syncTracker = $this->createRecordingSyncTracker();

        $esiClient = new EsiClient(
            $esiHttpClient,
            $this->createStub(CacheItemPoolInterface::class),
            $this->createStub(TokenManager::class),
            $esiBaseUrl,
            new NullLogger(),
        );

        return new SyncPublicContractsHandler(
            esiClient: $esiClient,
            cache: $this->publicContractsCache,
            logger: new NullLogger(),
            syncTracker: $syncTracker,
        );
    }

    /**
     * @param array<string, list<mixed>|MockResponse> $esiResponsesByPath
     */
    private function createSimulatedEsi(array $esiResponsesByPath, string $esiBaseUrl): MockHttpClient
    {
        $callsByPath = [];

        return new MockHttpClient(function (string $method, string $url) use ($esiResponsesByPath, $esiBaseUrl, &$callsByPath): MockResponse {
            $this->requestedUrls[] = $url;

            $urlWithoutQuery = (string) strtok($url, '?');
            if (!str_starts_with($urlWithoutQuery, $esiBaseUrl . '/')) {
                return new MockResponse('{"error":"Unknown host"}', ['http_code' => 404]);
            }
            $path = substr($urlWithoutQuery, strlen($esiBaseUrl));
            $this->esiEvents[] = 'request ' . $path;
            $configured = $esiResponsesByPath[$path] ?? null;

            if ($configured === null) {
                return new MockResponse('{"error":"Not found"}', ['http_code' => 404]);
            }
            if ($configured instanceof MockResponse) {
                return $configured;
            }

            if (str_starts_with($path, '/contracts/public/items/')) {
                // Contract items: one JSON body, always a single page.
                return $this->readTrackedJsonResponse($path, $configured);
            }

            // Contracts list: one entry per page or per call, consumed in order.
            $callIndex = $callsByPath[$path] ?? 0;
            $callsByPath[$path] = $callIndex + 1;
            $response = $configured[$callIndex] ?? null;
            if ($response === null) {
                $this->fail(sprintf('Unexpected extra ESI request: %s %s', $method, $url));
            }

            return $response instanceof MockResponse ? $response : $this->jsonResponse($response);
        });
    }

    private function createRecordingSyncTracker(): SyncTracker
    {
        $syncTracker = $this->createStub(SyncTracker::class);
        $syncTracker->method('start')->willReturnCallback(function (string $syncType): void {
            $this->syncTrackerCalls[] = ['start', $syncType, null];
        });
        $syncTracker->method('complete')->willReturnCallback(function (string $syncType, ?string $message = null): void {
            $this->syncTrackerCalls[] = ['complete', $syncType, $message];
        });
        $syncTracker->method('fail')->willReturnCallback(function (string $syncType, string $message): void {
            $this->syncTrackerCalls[] = ['fail', $syncType, $message];
        });

        return $syncTracker;
    }

    /**
     * @return list<string>
     */
    private function constructorParameterTypes(): array
    {
        $constructor = (new \ReflectionClass(SyncPublicContractsHandler::class))->getConstructor();
        $this->assertNotNull($constructor);

        return array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters(),
        );
    }

    /**
     * @return list<array{unitPrice: float, quantity: int, contractId: int}>|null
     */
    private function storedContractPrices(int $typeId): ?array
    {
        $cacheItem = $this->publicContractsCache->getItem('public_contract_prices_' . $typeId);

        return $cacheItem->isHit() ? $cacheItem->get() : null;
    }

    /**
     * @return list<int>
     */
    private function contractIdsWhoseItemsWereRequested(): array
    {
        $contractIds = [];
        foreach ($this->requestedUrls as $requestedUrl) {
            if (preg_match('#/contracts/public/items/(\d+)/#', $requestedUrl, $matches) === 1) {
                $contractIds[] = (int) $matches[1];
            }
        }

        return $contractIds;
    }

    /**
     * A 200 JSON response that logs "read <path>" in $esiEvents when its body is consumed.
     *
     * @param array<mixed> $body
     */
    private function readTrackedJsonResponse(string $path, array $body): MockResponse
    {
        $bodyChunks = (function () use ($path, $body): \Generator {
            $this->esiEvents[] = 'read ' . $path;
            yield (string) json_encode($body);
        })();

        return new MockResponse($bodyChunks, [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    /**
     * @return list<string> ESI events about contract items only, in order
     */
    private function contractItemsEvents(): array
    {
        return array_values(array_filter(
            $this->esiEvents,
            static fn (string $esiEvent): bool => str_contains($esiEvent, '/contracts/public/items/'),
        ));
    }

    /**
     * @param array<mixed> $body
     * @param array<string, string> $headers
     */
    private function jsonResponse(array $body, array $headers = []): MockResponse
    {
        return new MockResponse((string) json_encode($body), [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json', ...$headers],
        ]);
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
