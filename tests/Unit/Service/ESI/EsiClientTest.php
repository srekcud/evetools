<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Note on sleeping: EsiClient calls sleep() directly. A 420 retry sleeps
 * max(X-Esi-Error-Limit-Reset, 1) = 1 s, so each 420 scenario costs 1 s.
 * 429 scenarios use Retry-After: 0. Error-limit-remain is kept at 100 so
 * throttleIfNeeded() never sleeps, except in the getBatch() error-limit test
 * which sets it to 19 on purpose (one 100 ms pause).
 */
#[CoversClass(EsiClient::class)]
final class EsiClientTest extends TestCase
{
    private const BASE_URL = 'https://esi.test/latest';
    private const ACCESS_TOKEN = 'access-token-abc';
    private const EXPIRES_AFTER_SECONDS = 300;

    /** @var list<array{method: string, url: string, body: string, headers: array<string, list<string>>}> */
    private array $recordedRequests = [];

    /** @var list<string> "request <path>" when a request is launched, "read <path>" when its body is consumed */
    private array $esiEvents = [];

    // ---------------------------------------------------------------
    // GREEN guards: current correct behavior
    // ---------------------------------------------------------------

    public function testGetReturnsDecodedJsonOnSuccess(): void
    {
        $esiClient = $this->createEsiClient([
            $this->jsonResponse(['solar_system_id' => 30000142, 'name' => 'Jita']),
        ]);

        $result = $esiClient->get('/universe/systems/30000142/');

        $this->assertSame(['solar_system_id' => 30000142, 'name' => 'Jita'], $result);
        $this->assertCount(1, $this->recordedRequests);
        $this->assertSame('GET', $this->recordedRequests[0]['method']);
        $this->assertSame(self::BASE_URL . '/universe/systems/30000142/', $this->recordedRequests[0]['url']);
    }

    public function testGetSendsBearerAuthorizationHeaderWhenTokenGiven(): void
    {
        $esiClient = $this->createEsiClient([$this->jsonResponse(['ok' => true])]);

        $esiClient->get('/characters/2112000001/assets/', $this->createEveToken());

        $this->assertSame(
            ['Authorization: Bearer ' . self::ACCESS_TOKEN],
            $this->recordedRequests[0]['headers']['authorization'],
        );
    }

    public function testGetPaginatedReadsXPagesAndMergesAllPages(): void
    {
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]], 200, ['X-Pages' => '3']),
            $this->jsonResponse([['type_id' => 35, 'quantity' => 500]], 200, ['X-Pages' => '3']),
            $this->jsonResponse([['type_id' => 36, 'quantity' => 250]], 200, ['X-Pages' => '3']),
        ]);

        $result = $esiClient->getPaginated('/characters/2112000001/assets/');

        $this->assertSame([
            ['type_id' => 34, 'quantity' => 1000],
            ['type_id' => 35, 'quantity' => 500],
            ['type_id' => 36, 'quantity' => 250],
        ], $result);
        $this->assertSame([
            self::BASE_URL . '/characters/2112000001/assets/?page=1',
            self::BASE_URL . '/characters/2112000001/assets/?page=2',
            self::BASE_URL . '/characters/2112000001/assets/?page=3',
        ], array_column($this->recordedRequests, 'url'));
    }

    public function testGetRetriesOnceAsGetAfterErrorLimited420(): void
    {
        $esiClient = $this->createEsiClient([
            $this->errorLimitedResponse(),
            $this->jsonResponse(['name' => 'Jita']),
        ]);

        $result = $esiClient->get('/universe/systems/30000142/');

        $this->assertSame(['name' => 'Jita'], $result);
        $this->assertSame(['GET', 'GET'], array_column($this->recordedRequests, 'method'));
        $this->assertSame(
            [self::BASE_URL . '/universe/systems/30000142/', self::BASE_URL . '/universe/systems/30000142/'],
            array_column($this->recordedRequests, 'url'),
        );
    }

    public function testGetThrowsAfterSecondConsecutive420(): void
    {
        $esiClient = $this->createEsiClient([
            $this->errorLimitedResponse(),
            $this->errorLimitedResponse(),
        ]);

        try {
            $esiClient->get('/universe/systems/30000142/');
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(420, $exception->statusCode);
        }
        $this->assertCount(2, $this->recordedRequests);
    }

    // ---------------------------------------------------------------
    // RED: issue #24
    // ---------------------------------------------------------------

    public function testPostRetriesOnceAsPostWithSameBodyAfterErrorLimited420(): void
    {
        $esiClient = $this->createEsiClient([
            $this->errorLimitedResponse(),
            $this->jsonResponse([['id' => 34, 'name' => 'Tritanium', 'category' => 'inventory_type']]),
        ]);

        $result = $esiClient->post('/universe/names/', [34, 35]);

        $this->assertSame([['id' => 34, 'name' => 'Tritanium', 'category' => 'inventory_type']], $result);
        $this->assertSame(['POST', 'POST'], array_column($this->recordedRequests, 'method'));
        $this->assertSame(['[34,35]', '[34,35]'], array_column($this->recordedRequests, 'body'));
        $this->assertSame(
            ['Content-Type: application/json'],
            $this->recordedRequests[1]['headers']['content-type'] ?? null,
        );
    }

    public function testPostRetryAfter420KeepsAuthorizationHeader(): void
    {
        $esiClient = $this->createEsiClient([
            $this->errorLimitedResponse(),
            $this->jsonResponse([['item_id' => 1000000001, 'name' => 'Hangar']]),
        ]);

        $esiClient->post('/characters/2112000001/assets/names/', [1000000001], $this->createEveToken());

        $this->assertSame(['POST', 'POST'], array_column($this->recordedRequests, 'method'));
        $this->assertSame(
            ['Authorization: Bearer ' . self::ACCESS_TOKEN],
            $this->recordedRequests[1]['headers']['authorization'] ?? null,
        );
    }

    public function testGetRetryAfter420KeepsExtraHeaders(): void
    {
        $esiClient = $this->createEsiClient([
            $this->errorLimitedResponse(),
            $this->jsonResponse(['contract_id' => 1]),
        ]);

        $esiClient->get('/contracts/public/items/1/', null, ['X-Compatibility-Date' => '2025-12-16']);

        $this->assertCount(2, $this->recordedRequests);
        $this->assertSame(
            ['X-Compatibility-Date: 2025-12-16'],
            $this->recordedRequests[0]['headers']['x-compatibility-date'] ?? null,
        );
        $this->assertSame(
            ['X-Compatibility-Date: 2025-12-16'],
            $this->recordedRequests[1]['headers']['x-compatibility-date'] ?? null,
        );
    }

    public function testGetPaginatedRetriesErrorLimitedPageOnceAndReturnsCompleteResult(): void
    {
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]], 200, ['X-Pages' => '2']),
            $this->errorLimitedResponse(['X-Pages' => '2']),
            $this->jsonResponse([['type_id' => 35, 'quantity' => 500]], 200, ['X-Pages' => '2']),
        ]);

        $result = $esiClient->getPaginated('/characters/2112000001/assets/');

        $this->assertSame([
            ['type_id' => 34, 'quantity' => 1000],
            ['type_id' => 35, 'quantity' => 500],
        ], $result);
        $this->assertSame([
            self::BASE_URL . '/characters/2112000001/assets/?page=1',
            self::BASE_URL . '/characters/2112000001/assets/?page=2',
            self::BASE_URL . '/characters/2112000001/assets/?page=2',
        ], array_column($this->recordedRequests, 'url'));
    }

    public function testGetRetriesOnceAfterRateLimited429WithRetryAfter(): void
    {
        $esiClient = $this->createEsiClient([
            $this->rateLimitedResponse(retryAfterSeconds: 0),
            $this->jsonResponse(['name' => 'Jita']),
        ]);

        $result = $esiClient->get('/universe/systems/30000142/');

        $this->assertSame(['name' => 'Jita'], $result);
        $this->assertSame(['GET', 'GET'], array_column($this->recordedRequests, 'method'));
    }

    public function testPostRetriesOnceAsPostAfterRateLimited429(): void
    {
        $esiClient = $this->createEsiClient([
            $this->rateLimitedResponse(retryAfterSeconds: 0),
            $this->jsonResponse([['id' => 34, 'name' => 'Tritanium', 'category' => 'inventory_type']]),
        ]);

        $result = $esiClient->post('/universe/names/', [34]);

        $this->assertSame([['id' => 34, 'name' => 'Tritanium', 'category' => 'inventory_type']], $result);
        $this->assertSame(['POST', 'POST'], array_column($this->recordedRequests, 'method'));
        $this->assertSame(['[34]', '[34]'], array_column($this->recordedRequests, 'body'));
    }

    public function testGetThrows429AfterSecondConsecutiveRateLimit(): void
    {
        $esiClient = $this->createEsiClient([
            $this->rateLimitedResponse(retryAfterSeconds: 0),
            $this->rateLimitedResponse(retryAfterSeconds: 0),
        ]);

        try {
            $esiClient->get('/universe/systems/30000142/');
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(429, $exception->statusCode);
        }
        // Exactly one retry, no loop
        $this->assertCount(2, $this->recordedRequests);
    }

    // ---------------------------------------------------------------
    // getWithCache(): conditional GET with ETag
    // ---------------------------------------------------------------

    public function testGetWithCacheStoresFreshResponseAndRevalidatesWithItsEtag(): void
    {
        $clock = new MockClock();
        $issuedAt = $clock->now()->getTimestamp();
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]], 200, [
                'ETag' => '"etag-v1"',
                'Expires' => $this->httpDate($issuedAt + self::EXPIRES_AFTER_SECONDS),
            ]),
            $this->notModifiedResponse(),
        ], new ArrayAdapter(clock: $clock));

        $firstResult = $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());
        $clock->sleep(self::EXPIRES_AFTER_SECONDS + 1);
        $secondResult = $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());

        $this->assertSame([['type_id' => 34, 'quantity' => 1000]], $firstResult);
        $this->assertSame([['type_id' => 34, 'quantity' => 1000]], $secondResult);
        $this->assertCount(2, $this->recordedRequests);
        $this->assertArrayNotHasKey('if-none-match', $this->recordedRequests[0]['headers']);
        $this->assertSame(['If-None-Match: "etag-v1"'], $this->recordedRequests[1]['headers']['if-none-match'] ?? null);
    }

    public function testGetWithCacheReturnsCachedDataOnFirstTry304WithSingleRequest(): void
    {
        $clock = new MockClock();
        $issuedAt = $clock->now()->getTimestamp();
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]], 200, [
                'ETag' => '"etag-v1"',
                'Expires' => $this->httpDate($issuedAt + self::EXPIRES_AFTER_SECONDS),
            ]),
            $this->notModifiedResponse(),
        ], new ArrayAdapter(clock: $clock));
        $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());
        $clock->sleep(self::EXPIRES_AFTER_SECONDS + 1);
        $conditionalRequestsStart = count($this->recordedRequests);

        $result = $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());

        $this->assertSame([['type_id' => 34, 'quantity' => 1000]], $result);
        $conditionalRequests = array_slice($this->recordedRequests, $conditionalRequestsStart);
        $this->assertCount(1, $conditionalRequests);
        $this->assertSame('GET', $conditionalRequests[0]['method']);
        $this->assertSame(['If-None-Match: "etag-v1"'], $conditionalRequests[0]['headers']['if-none-match'] ?? null);
        $this->assertSame(
            ['Authorization: Bearer ' . self::ACCESS_TOKEN],
            $conditionalRequests[0]['headers']['authorization'] ?? null,
        );
    }

    public function testGetWithCacheRetriesConditionalRequestOnceAfter420KeepingEtagAndToken(): void
    {
        $clock = new MockClock();
        $issuedAt = $clock->now()->getTimestamp();
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]], 200, [
                'ETag' => '"etag-v1"',
                'Expires' => $this->httpDate($issuedAt + self::EXPIRES_AFTER_SECONDS),
            ]),
            $this->errorLimitedResponse(),
            $this->notModifiedResponse(),
        ], new ArrayAdapter(clock: $clock));
        $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());
        $clock->sleep(self::EXPIRES_AFTER_SECONDS + 1);
        $conditionalRequestsStart = count($this->recordedRequests);

        $result = $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());

        $this->assertSame([['type_id' => 34, 'quantity' => 1000]], $result);
        $conditionalRequests = array_slice($this->recordedRequests, $conditionalRequestsStart);
        $this->assertSame(['GET', 'GET'], array_column($conditionalRequests, 'method'));
        foreach ($conditionalRequests as $conditionalRequest) {
            $this->assertSame(['If-None-Match: "etag-v1"'], $conditionalRequest['headers']['if-none-match'] ?? null);
            $this->assertSame(
                ['Authorization: Bearer ' . self::ACCESS_TOKEN],
                $conditionalRequest['headers']['authorization'] ?? null,
            );
        }
    }

    // ---------------------------------------------------------------
    // RED: issue #26, getBatch(): concurrent GETs, per-key failure, 420/429 retry
    // ---------------------------------------------------------------

    public function testGetBatchReturnsDecodedArraysUnderTheirOwnKeysFromTheConfiguredBaseUrl(): void
    {
        $esiClient = $this->createEsiClientServingByPath([
            '/contracts/public/items/20001/' => [$this->jsonResponse([['type_id' => 34, 'quantity' => 50, 'is_included' => true]])],
            '/contracts/public/items/20003/' => [$this->jsonResponse([['type_id' => 35, 'quantity' => 60, 'is_included' => true]])],
        ]);

        $result = $esiClient->getBatch([
            20001 => '/contracts/public/items/20001/',
            20003 => '/contracts/public/items/20003/',
        ]);

        // Integer keys are kept as is (no renumbering), bodies are associative arrays.
        $this->assertSame([
            20001 => [['type_id' => 34, 'quantity' => 50, 'is_included' => true]],
            20003 => [['type_id' => 35, 'quantity' => 60, 'is_included' => true]],
        ], $result);
        $this->assertSame([
            self::BASE_URL . '/contracts/public/items/20001/',
            self::BASE_URL . '/contracts/public/items/20003/',
        ], array_column($this->recordedRequests, 'url'));
        $this->assertSame(['GET', 'GET'], array_column($this->recordedRequests, 'method'));
    }

    public function testGetBatchLaunchesEveryRequestBeforeReadingAnyResponse(): void
    {
        $esiClient = $this->createEsiClientServingByPath([
            '/universe/systems/30000142/' => [$this->readTrackedJsonResponse('/universe/systems/30000142/', ['name' => 'Jita'])],
            '/universe/systems/30002187/' => [$this->readTrackedJsonResponse('/universe/systems/30002187/', ['name' => 'Amarr'])],
            '/universe/systems/30002659/' => [$this->readTrackedJsonResponse('/universe/systems/30002659/', ['name' => 'Dodixie'])],
        ]);

        $result = $esiClient->getBatch([
            'jita' => '/universe/systems/30000142/',
            'amarr' => '/universe/systems/30002187/',
            'dodixie' => '/universe/systems/30002659/',
        ]);

        $this->assertSame(['jita' => ['name' => 'Jita'], 'amarr' => ['name' => 'Amarr'], 'dodixie' => ['name' => 'Dodixie']], $result);
        // Concurrent: the three requests are all in flight before the first response is read.
        $this->assertSame([
            'request /universe/systems/30000142/',
            'request /universe/systems/30002187/',
            'request /universe/systems/30002659/',
        ], array_slice($this->esiEvents, 0, 3));
        $this->assertCount(6, $this->esiEvents);
    }

    public function testGetBatchReturnsNullForFailedKeysWithoutFailingTheOthers(): void
    {
        $esiClient = $this->createEsiClientServingByPath([
            '/contracts/public/items/30001/' => [new MockResponse('{"error":"Contract not found"}', ['http_code' => 404])],
            '/contracts/public/items/30002/' => [$this->jsonResponse([['type_id' => 34, 'quantity' => 100, 'is_included' => true]])],
            '/contracts/public/items/30003/' => [new MockResponse('{"error":"Internal server error"}', ['http_code' => 500])],
            '/contracts/public/items/30004/' => [new MockResponse('', ['error' => 'Connection reset by peer'])],
        ]);

        $result = $esiClient->getBatch([
            30001 => '/contracts/public/items/30001/',
            30002 => '/contracts/public/items/30002/',
            30003 => '/contracts/public/items/30003/',
            30004 => '/contracts/public/items/30004/',
        ]);

        $this->assertSame([
            30001 => null,
            30002 => [['type_id' => 34, 'quantity' => 100, 'is_included' => true]],
            30003 => null,
            30004 => null,
        ], $result);
        // 404, 5xx and network errors are not retried: one request per key.
        $this->assertCount(4, $this->recordedRequests);
    }

    public function testGetBatchSendsBearerAuthorizationOnEveryRequestWhenTokenGiven(): void
    {
        $esiClient = $this->createEsiClientServingByPath([
            '/characters/2112000001/' => [$this->jsonResponse(['name' => 'Pilot One'])],
            '/characters/2112000002/' => [$this->jsonResponse(['name' => 'Pilot Two'])],
        ]);

        $esiClient->getBatch([
            'one' => '/characters/2112000001/',
            'two' => '/characters/2112000002/',
        ], $this->createEveToken());

        $this->assertCount(2, $this->recordedRequests);
        foreach ($this->recordedRequests as $recordedRequest) {
            $this->assertSame(['Authorization: Bearer ' . self::ACCESS_TOKEN], $recordedRequest['headers']['authorization'] ?? null);
        }
    }

    public function testGetBatchRetriesRateLimited429KeyOnceAndReturnsItsResult(): void
    {
        $esiClient = $this->createEsiClientServingByPath([
            '/universe/systems/30000142/' => [
                $this->rateLimitedResponse(retryAfterSeconds: 0),
                $this->jsonResponse(['name' => 'Jita']),
            ],
            '/universe/systems/30002187/' => [$this->jsonResponse(['name' => 'Amarr'])],
        ]);

        $result = $esiClient->getBatch([
            'jita' => '/universe/systems/30000142/',
            'amarr' => '/universe/systems/30002187/',
        ]);

        $this->assertSame(['jita' => ['name' => 'Jita'], 'amarr' => ['name' => 'Amarr']], $result);
        // Only the rate-limited key is replayed.
        $this->assertSame([
            self::BASE_URL . '/universe/systems/30000142/',
            self::BASE_URL . '/universe/systems/30002187/',
            self::BASE_URL . '/universe/systems/30000142/',
        ], array_column($this->recordedRequests, 'url'));
    }

    public function testGetBatchRetriesErrorLimited420KeyOnceAndReturnsItsResult(): void
    {
        $esiClient = $this->createEsiClientServingByPath([
            '/universe/systems/30000142/' => [
                $this->errorLimitedResponse(),
                $this->jsonResponse(['name' => 'Jita']),
            ],
        ]);

        $result = $esiClient->getBatch(['jita' => '/universe/systems/30000142/']);

        $this->assertSame(['jita' => ['name' => 'Jita']], $result);
        $this->assertSame(['GET', 'GET'], array_column($this->recordedRequests, 'method'));
    }

    public function testGetBatchReturnsNullAfterSecondConsecutive429ForThatKeyOnly(): void
    {
        $esiClient = $this->createEsiClientServingByPath([
            '/universe/systems/30000142/' => [
                $this->rateLimitedResponse(retryAfterSeconds: 0),
                $this->rateLimitedResponse(retryAfterSeconds: 0),
            ],
            '/universe/systems/30002187/' => [$this->jsonResponse(['name' => 'Amarr'])],
        ]);

        $result = $esiClient->getBatch([
            'jita' => '/universe/systems/30000142/',
            'amarr' => '/universe/systems/30002187/',
        ]);

        $this->assertSame(['jita' => null, 'amarr' => ['name' => 'Amarr']], $result);
        // Exactly one retry, no loop
        $this->assertCount(3, $this->recordedRequests);
    }

    public function testGetBatchRecordsErrorLimitHeadersSoTheNextRequestIsThrottled(): void
    {
        $logRecords = [];
        $esiClient = $this->createEsiClientServingByPath([
            '/universe/systems/30000142/' => [$this->jsonResponse(['name' => 'Jita'], 200, ['X-Esi-Error-Limit-Remain' => '19'])],
            '/universe/systems/30002187/' => [$this->jsonResponse(['name' => 'Amarr'], 200, ['X-Esi-Error-Limit-Remain' => '19'])],
        ], $this->createRecordingLogger($logRecords));

        $esiClient->getBatch(['jita' => '/universe/systems/30000142/']);
        $this->assertSame([], $logRecords);

        // 19 errors left (< 20): the next request waits (20 - 19) * 100 ms.
        $esiClient->get('/universe/systems/30002187/');

        $throttleRecords = array_values(array_filter(
            $logRecords,
            static fn (array $logRecord): bool => ($logRecord['context']['remain'] ?? null) === 19,
        ));
        $this->assertCount(1, $throttleRecords);
        $this->assertSame(100, $throttleRecords[0]['context']['delay'] ?? null);
    }

    public function testGetBatchWithoutEndpointsSendsNoRequest(): void
    {
        $esiClient = $this->createEsiClientServingByPath([]);

        $this->assertSame([], $esiClient->getBatch([]));
        $this->assertSame([], $this->recordedRequests);
    }

    // ---------------------------------------------------------------
    // RED: issue #26 follow-up -- a 200 with an unusable JSON body only nulls its own key
    // ---------------------------------------------------------------

    public function testGetBatchReturnsNullForKeyWithInvalidJsonBodyWithoutFailingTheOthers(): void
    {
        $esiClient = $this->createEsiClientServingByPath([
            '/contracts/public/items/30001/' => [$this->rawBodyResponse('[{"type_id": 34, "quantity": 1')],
            '/contracts/public/items/30002/' => [$this->jsonResponse([['type_id' => 35, 'quantity' => 250, 'is_included' => true]])],
        ]);

        $result = $esiClient->getBatch([
            30001 => '/contracts/public/items/30001/',
            30002 => '/contracts/public/items/30002/',
        ]);

        $this->assertSame([
            30001 => null,
            30002 => [['type_id' => 35, 'quantity' => 250, 'is_included' => true]],
        ], $result);
        // An unusable body is not retried: one request per key.
        $this->assertCount(2, $this->recordedRequests);
    }

    public function testGetBatchReturnsNullForKeyWhoseJsonBodyIsAScalar(): void
    {
        $esiClient = $this->createEsiClientServingByPath([
            '/contracts/public/items/30001/' => [$this->rawBodyResponse('42')],
            '/contracts/public/items/30002/' => [$this->jsonResponse([['type_id' => 36, 'quantity' => 1000, 'is_included' => true]])],
        ]);

        $result = $esiClient->getBatch([
            30001 => '/contracts/public/items/30001/',
            30002 => '/contracts/public/items/30002/',
        ]);

        $this->assertSame([
            30001 => null,
            30002 => [['type_id' => 36, 'quantity' => 1000, 'is_included' => true]],
        ], $result);
    }

    public function testGetBatchLogsOneWarningWithTheEndpointForEachKeyWithUnusableJsonBody(): void
    {
        $logRecords = [];
        $esiClient = $this->createEsiClientServingByPath([
            '/contracts/public/items/30001/' => [$this->rawBodyResponse('not json at all')],
            '/contracts/public/items/30002/' => [$this->jsonResponse([['type_id' => 34, 'quantity' => 100, 'is_included' => true]])],
            '/contracts/public/items/30003/' => [$this->rawBodyResponse('42')],
        ], $this->createRecordingLogger($logRecords));

        $esiClient->getBatch([
            30001 => '/contracts/public/items/30001/',
            30002 => '/contracts/public/items/30002/',
            30003 => '/contracts/public/items/30003/',
        ]);

        $warningEndpoints = array_map(
            static fn (array $logRecord): mixed => $logRecord['context']['endpoint'] ?? null,
            array_values(array_filter(
                $logRecords,
                static fn (array $logRecord): bool => $logRecord['level'] === LogLevel::WARNING,
            )),
        );
        $this->assertSame([
            '/contracts/public/items/30001/',
            '/contracts/public/items/30003/',
        ], $warningEndpoints);
    }

    // ---------------------------------------------------------------
    // RED: issue #41 -- getWithCache() honours ESI Expires and keeps the ETag
    //
    // Clock note: the MockClock starts at the real current time because
    // CacheItem::expiresAfter() uses microtime(true), not the pool clock;
    // only ArrayAdapter freshness checks follow the MockClock. Expires
    // headers are whole seconds, so "+299 s" stays before Expires and
    // "+301 s" is past it.
    // ---------------------------------------------------------------

    public function testGetWithCacheServesCachedDataWithoutHttpRequestBeforeExpires(): void
    {
        $clock = new MockClock();
        $issuedAt = $clock->now()->getTimestamp();
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]], 200, [
                'ETag' => '"etag-v1"',
                'Expires' => $this->httpDate($issuedAt + self::EXPIRES_AFTER_SECONDS),
            ]),
        ], new ArrayAdapter(clock: $clock));
        $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());

        $clock->sleep(self::EXPIRES_AFTER_SECONDS - 1);
        $result = $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());

        $this->assertSame([['type_id' => 34, 'quantity' => 1000]], $result);
        $this->assertCount(1, $this->recordedRequests);
    }

    public function testGetWithCacheRevalidatesWithStoredEtagAfterExpiresAndReturnsCachedDataOn304(): void
    {
        $clock = new MockClock();
        $issuedAt = $clock->now()->getTimestamp();
        $revalidatedAt = $issuedAt + self::EXPIRES_AFTER_SECONDS + 1;
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]], 200, [
                'ETag' => '"etag-v1"',
                'Expires' => $this->httpDate($issuedAt + self::EXPIRES_AFTER_SECONDS),
            ]),
            $this->notModifiedResponse([
                'ETag' => '"etag-v1"',
                'Expires' => $this->httpDate($revalidatedAt + self::EXPIRES_AFTER_SECONDS),
            ]),
        ], new ArrayAdapter(clock: $clock));
        $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());

        $clock->sleep(self::EXPIRES_AFTER_SECONDS + 1);
        $result = $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());

        $this->assertSame([['type_id' => 34, 'quantity' => 1000]], $result);
        $this->assertCount(2, $this->recordedRequests);
        $this->assertSame(['If-None-Match: "etag-v1"'], $this->recordedRequests[1]['headers']['if-none-match'] ?? null);
        $this->assertSame(
            ['Authorization: Bearer ' . self::ACCESS_TOKEN],
            $this->recordedRequests[1]['headers']['authorization'] ?? null,
        );
    }

    public function testGetWithCacheRefreshesFreshnessFromExpiresOf304(): void
    {
        $clock = new MockClock();
        $issuedAt = $clock->now()->getTimestamp();
        $revalidatedAt = $issuedAt + self::EXPIRES_AFTER_SECONDS + 1;
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]], 200, [
                'ETag' => '"etag-v1"',
                'Expires' => $this->httpDate($issuedAt + self::EXPIRES_AFTER_SECONDS),
            ]),
            $this->notModifiedResponse([
                'ETag' => '"etag-v1"',
                'Expires' => $this->httpDate($revalidatedAt + self::EXPIRES_AFTER_SECONDS),
            ]),
        ], new ArrayAdapter(clock: $clock));
        $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());
        $clock->sleep(self::EXPIRES_AFTER_SECONDS + 1);
        $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());

        $clock->sleep(self::EXPIRES_AFTER_SECONDS - 1);
        $result = $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());

        $this->assertSame([['type_id' => 34, 'quantity' => 1000]], $result);
        $this->assertCount(2, $this->recordedRequests);
    }

    public function testGetWithCacheReplacesDataAndEtagWhenRevalidationReturns200(): void
    {
        $clock = new MockClock();
        $issuedAt = $clock->now()->getTimestamp();
        $replacedAt = $issuedAt + self::EXPIRES_AFTER_SECONDS + 1;
        $revalidatedAt = $replacedAt + self::EXPIRES_AFTER_SECONDS + 1;
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]], 200, [
                'ETag' => '"etag-v1"',
                'Expires' => $this->httpDate($issuedAt + self::EXPIRES_AFTER_SECONDS),
            ]),
            $this->jsonResponse([['type_id' => 34, 'quantity' => 750]], 200, [
                'ETag' => '"etag-v2"',
                'Expires' => $this->httpDate($replacedAt + self::EXPIRES_AFTER_SECONDS),
            ]),
            $this->notModifiedResponse([
                'ETag' => '"etag-v2"',
                'Expires' => $this->httpDate($revalidatedAt + self::EXPIRES_AFTER_SECONDS),
            ]),
        ], new ArrayAdapter(clock: $clock));
        $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());

        $clock->sleep(self::EXPIRES_AFTER_SECONDS + 1);
        $replacedResult = $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());
        $clock->sleep(self::EXPIRES_AFTER_SECONDS + 1);
        $revalidatedResult = $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());

        $this->assertSame([['type_id' => 34, 'quantity' => 750]], $replacedResult);
        $this->assertSame([['type_id' => 34, 'quantity' => 750]], $revalidatedResult);
        $this->assertCount(3, $this->recordedRequests);
        $this->assertSame(['If-None-Match: "etag-v1"'], $this->recordedRequests[1]['headers']['if-none-match'] ?? null);
        $this->assertSame(['If-None-Match: "etag-v2"'], $this->recordedRequests[2]['headers']['if-none-match'] ?? null);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Serves each ESI path its own responses, in order, whatever the order of the requests.
     * Records every request and logs "request <path>" in $esiEvents when it is launched.
     *
     * @param array<string, list<MockResponse>> $responsesByPath
     */
    private function createEsiClientServingByPath(array $responsesByPath, ?LoggerInterface $logger = null): EsiClient
    {
        $this->recordedRequests = [];
        $this->esiEvents = [];

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$responsesByPath): MockResponse {
            $this->recordedRequests[] = [
                'method' => $method,
                'url' => $url,
                'body' => is_string($options['body'] ?? null) ? $options['body'] : '',
                'headers' => $options['normalized_headers'] ?? [],
            ];
            $path = substr($url, strlen(self::BASE_URL));
            $this->esiEvents[] = 'request ' . $path;

            $response = isset($responsesByPath[$path]) ? array_shift($responsesByPath[$path]) : null;
            if ($response === null) {
                $this->fail(sprintf('Unexpected extra request: %s %s', $method, $url));
            }

            return $response;
        });

        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn(self::ACCESS_TOKEN);

        return new EsiClient(
            $httpClient,
            $this->createStub(CacheItemPoolInterface::class),
            $tokenManager,
            self::BASE_URL,
            $logger ?? new NullLogger(),
        );
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
            yield json_encode($body, JSON_THROW_ON_ERROR);
        })();

        return new MockResponse($bodyChunks, [
            'http_code' => 200,
            'response_headers' => [
                'Content-Type' => 'application/json',
                'X-Esi-Error-Limit-Remain' => '100',
                'X-Esi-Error-Limit-Reset' => '0',
            ],
        ]);
    }

    /**
     * @param list<array{level: mixed, message: string, context: array<mixed>}> $logRecords
     */
    private function createRecordingLogger(array &$logRecords): LoggerInterface
    {
        return new class ($logRecords) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<mixed>}> $logRecords */
            public function __construct(private array &$logRecords)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logRecords[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    /**
     * @param list<MockResponse> $responses served in order; each request is recorded
     */
    private function createEsiClient(array $responses, ?CacheItemPoolInterface $esiCache = null): EsiClient
    {
        $this->recordedRequests = [];

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->recordedRequests[] = [
                'method' => $method,
                'url' => $url,
                'body' => is_string($options['body'] ?? null) ? $options['body'] : '',
                'headers' => $options['normalized_headers'] ?? [],
            ];

            $response = array_shift($responses);
            if ($response === null) {
                $this->fail(sprintf('Unexpected extra request: %s %s', $method, $url));
            }

            return $response;
        });

        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn(self::ACCESS_TOKEN);

        return new EsiClient(
            $httpClient,
            $esiCache ?? $this->createStub(CacheItemPoolInterface::class),
            $tokenManager,
            self::BASE_URL,
            new NullLogger(),
        );
    }

    private function createEveToken(): EveToken
    {
        return (new EveToken())
            ->setAccessToken(self::ACCESS_TOKEN)
            ->setAccessTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
    }

    /**
     * @param array<mixed> $body
     * @param array<string, string> $headers
     */
    private function jsonResponse(array $body, int $statusCode = 200, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => $statusCode,
            'response_headers' => [
                'Content-Type' => 'application/json',
                'X-Esi-Error-Limit-Remain' => '100',
                'X-Esi-Error-Limit-Reset' => '0',
                ...$headers,
            ],
        ]);
    }

    /**
     * A 200 response announced as JSON whose body is served verbatim (possibly invalid or non-array).
     */
    private function rawBodyResponse(string $body): MockResponse
    {
        return new MockResponse($body, [
            'http_code' => 200,
            'response_headers' => [
                'Content-Type' => 'application/json',
                'X-Esi-Error-Limit-Remain' => '100',
                'X-Esi-Error-Limit-Reset' => '0',
            ],
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function errorLimitedResponse(array $headers = []): MockResponse
    {
        return new MockResponse('{"error":"error limited"}', [
            'http_code' => 420,
            'response_headers' => [
                'Content-Type' => 'application/json',
                'X-Esi-Error-Limit-Remain' => '100',
                'X-Esi-Error-Limit-Reset' => '0',
                ...$headers,
            ],
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function notModifiedResponse(array $headers = []): MockResponse
    {
        return new MockResponse('', [
            'http_code' => 304,
            'response_headers' => [
                'X-Esi-Error-Limit-Remain' => '100',
                'X-Esi-Error-Limit-Reset' => '0',
                ...$headers,
            ],
        ]);
    }

    private function httpDate(int $timestamp): string
    {
        return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
    }

    private function rateLimitedResponse(int $retryAfterSeconds): MockResponse
    {
        return new MockResponse('{"error":"rate limited"}', [
            'http_code' => 429,
            'response_headers' => [
                'Content-Type' => 'application/json',
                'Retry-After' => (string) $retryAfterSeconds,
                'X-Ratelimit-Group' => 'esi-test',
                'X-Ratelimit-Limit' => '150/15m',
                'X-Ratelimit-Remaining' => '0',
                'X-Ratelimit-Used' => '150',
                'X-Esi-Error-Limit-Remain' => '100',
                'X-Esi-Error-Limit-Reset' => '0',
            ],
        ]);
    }
}
