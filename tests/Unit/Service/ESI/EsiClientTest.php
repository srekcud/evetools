<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Symfony\Component\Uid\Uuid;

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

    /** @var list<array{method: string, url: string, body: string, headers: array<string, list<string>>, timeout?: float|null}> */
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
        $logRecords = [];
        $esiClient = $this->createEsiClient([
            $this->errorLimitedResponse(),
            $this->jsonResponse(['name' => 'Jita']),
        ], logger: $this->createRecordingLogger($logRecords));

        $result = $esiClient->get('/universe/systems/30000142/');

        $this->assertSame(['name' => 'Jita'], $result);
        // Error-limit reset 0 s: the retry still waits at least 1 s.
        $this->assertSame([[
            'level' => 'warning',
            'message' => 'ESI {status} received, sleeping {seconds}s before retry',
            'context' => ['status' => 420, 'seconds' => 1, 'endpoint' => '/universe/systems/30000142/'],
        ]], $logRecords);
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
            $this->assertSame('Error limited', $exception->getMessage());
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
    // Issue #43 -- getPaginated(): X-Pages, query string, failing page
    // ---------------------------------------------------------------

    public function testGetPaginatedWithoutXPagesHeaderRequestsOnlyTheFirstPage(): void
    {
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]]),
        ]);

        $result = $esiClient->getPaginated('/characters/2112000001/assets/');

        $this->assertSame([['type_id' => 34, 'quantity' => 1000]], $result);
        $this->assertSame(
            [self::BASE_URL . '/characters/2112000001/assets/?page=1'],
            array_column($this->recordedRequests, 'url'),
        );
    }

    public function testGetPaginatedAppendsPageWithAmpersandWhenEndpointHasQueryString(): void
    {
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['order_id' => 6000000001, 'volume_remain' => 10]], 200, ['X-Pages' => '2']),
            $this->jsonResponse([['order_id' => 6000000002, 'volume_remain' => 20]], 200, ['X-Pages' => '2']),
        ]);

        $result = $esiClient->getPaginated('/markets/10000002/orders/?order_type=sell');

        $this->assertSame([
            ['order_id' => 6000000001, 'volume_remain' => 10],
            ['order_id' => 6000000002, 'volume_remain' => 20],
        ], $result);
        $this->assertSame([
            self::BASE_URL . '/markets/10000002/orders/?order_type=sell&page=1',
            self::BASE_URL . '/markets/10000002/orders/?order_type=sell&page=2',
        ], array_column($this->recordedRequests, 'url'));
    }

    public function testGetPaginatedThrowsForTheFailingPageWithoutRequestingTheNextOnes(): void
    {
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]], 200, ['X-Pages' => '3']),
            $this->errorResponse(404, ['X-Pages' => '3']),
        ]);

        try {
            $esiClient->getPaginated('/characters/2112000001/assets/');
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(404, $exception->statusCode);
            $this->assertSame('ESI request failed', $exception->getMessage());
            $this->assertSame('/characters/2112000001/assets/?page=2', $exception->endpoint);
        }
        $this->assertCount(2, $this->recordedRequests);
    }

    public function testGetPaginatedThrowsEsiApiExceptionOnA300Page(): void
    {
        $esiClient = $this->createEsiClient([$this->errorResponse(300)]);

        try {
            $esiClient->getPaginated('/characters/2112000001/assets/');
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(300, $exception->statusCode);
            $this->assertSame('ESI request failed', $exception->getMessage());
        }
    }

    public function testGetPaginatedThrowsNetworkErrorWithStatusZero(): void
    {
        $esiClient = $this->createEsiClient([$this->networkErrorResponse()]);

        try {
            $esiClient->getPaginated('/characters/2112000001/assets/');
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(0, $exception->statusCode);
            $this->assertSame('Network error: Connection reset by peer', $exception->getMessage());
            $this->assertSame('/characters/2112000001/assets/?page=1', $exception->endpoint);
        }
    }

    public function testGetPaginatedThrottlesOnceBeforeEachFollowingPageAndNotAfterTheLastWhenErrorLimitRemainIsLow(): void
    {
        // Issue #84: 19 errors left -> one 100 ms pause before page 2, one before page 3, none after page 3.
        $logRecords = [];
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]], 200, ['X-Pages' => '3', 'X-Esi-Error-Limit-Remain' => '19']),
            $this->jsonResponse([['type_id' => 35, 'quantity' => 500]], 200, ['X-Pages' => '3', 'X-Esi-Error-Limit-Remain' => '19']),
            $this->jsonResponse([['type_id' => 36, 'quantity' => 250]], 200, ['X-Pages' => '3', 'X-Esi-Error-Limit-Remain' => '19']),
        ], logger: $this->createRecordingLogger($logRecords));

        $result = $esiClient->getPaginated('/characters/2112000001/assets/');

        $this->assertSame([
            ['type_id' => 34, 'quantity' => 1000],
            ['type_id' => 35, 'quantity' => 500],
            ['type_id' => 36, 'quantity' => 250],
        ], $result);
        $this->assertSame([
            ['remain' => 19, 'delay' => 100],
            ['remain' => 19, 'delay' => 100],
        ], array_column($logRecords, 'context'));
    }

    public function testGetPaginatedDoesNotThrottleAfterItsOnlyPageWhenErrorLimitRemainIsLow(): void
    {
        // Issue #84: no request follows the last page, so no pause either.
        $logRecords = [];
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]], 200, ['X-Esi-Error-Limit-Remain' => '19']),
        ], logger: $this->createRecordingLogger($logRecords));

        $esiClient->getPaginated('/characters/2112000001/assets/');

        $this->assertSame([], $logRecords);
    }

    // ---------------------------------------------------------------
    // Issue #43 -- post() and postEmpty()
    // ---------------------------------------------------------------

    public function testPostSendsJsonBodyWithAcceptAndContentTypeHeaders(): void
    {
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['id' => 34, 'name' => 'Tritanium', 'category' => 'inventory_type']]),
        ]);

        $result = $esiClient->post('/universe/names/', [34]);

        $this->assertSame([['id' => 34, 'name' => 'Tritanium', 'category' => 'inventory_type']], $result);
        $this->assertSame('POST', $this->recordedRequests[0]['method']);
        $this->assertSame(self::BASE_URL . '/universe/names/', $this->recordedRequests[0]['url']);
        $this->assertSame('[34]', $this->recordedRequests[0]['body']);
        $this->assertSame(['Accept: application/json'], $this->recordedRequests[0]['headers']['accept'] ?? null);
        $this->assertSame(['Content-Type: application/json'], $this->recordedRequests[0]['headers']['content-type'] ?? null);
    }

    public function testPostThrowsEsiApiExceptionWithStatusMessageAndEndpointOnClientError(): void
    {
        $esiClient = $this->createEsiClient([$this->errorResponse(403)]);

        try {
            $esiClient->post('/characters/2112000001/assets/names/', [1000000001], $this->createEveToken());
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(403, $exception->statusCode);
            $this->assertSame('Access forbidden', $exception->getMessage());
            $this->assertSame('/characters/2112000001/assets/names/', $exception->endpoint);
        }
    }

    public function testPostThrowsNetworkErrorWithStatusZero(): void
    {
        $esiClient = $this->createEsiClient([$this->networkErrorResponse()]);

        try {
            $esiClient->post('/universe/names/', [34]);
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(0, $exception->statusCode);
            $this->assertSame('Network error: Connection reset by peer', $exception->getMessage());
        }
    }

    public function testPostEmptySendsPostWithoutBodyWithBearerAuthorization(): void
    {
        $esiClient = $this->createEsiClient([
            new MockResponse('', ['http_code' => 204, 'response_headers' => ['X-Esi-Error-Limit-Remain' => '100']]),
        ]);

        $esiClient->postEmpty('/ui/openwindow/marketdetails/?type_id=34', $this->createEveToken());

        $this->assertCount(1, $this->recordedRequests);
        $this->assertSame('POST', $this->recordedRequests[0]['method']);
        $this->assertSame(self::BASE_URL . '/ui/openwindow/marketdetails/?type_id=34', $this->recordedRequests[0]['url']);
        $this->assertSame('', $this->recordedRequests[0]['body']);
        $this->assertSame(['Authorization: Bearer ' . self::ACCESS_TOKEN], $this->recordedRequests[0]['headers']['authorization'] ?? null);
    }

    public function testPostEmptyAcceptsA200Response(): void
    {
        $esiClient = $this->createEsiClient([$this->rawJsonResponse('')]);

        $esiClient->postEmpty('/ui/autopilot/waypoint/?destination_id=30000142', $this->createEveToken());

        $this->assertCount(1, $this->recordedRequests);
    }

    /**
     * postEmpty() does not replay a 420/429, unlike get()/post(): a single request is sent.
     */
    #[DataProvider('esiErrorStatusProvider')]
    public function testPostEmptyThrowsEsiApiExceptionWithStatusAndMessageWithoutRetry(int $statusCode, string $expectedMessage): void
    {
        $esiClient = $this->createEsiClient([$this->errorResponse($statusCode)]);

        try {
            $esiClient->postEmpty('/ui/openwindow/marketdetails/?type_id=34', $this->createEveToken());
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame($statusCode, $exception->statusCode);
            $this->assertSame($expectedMessage, $exception->getMessage());
            $this->assertSame('/ui/openwindow/marketdetails/?type_id=34', $exception->endpoint);
        }
        $this->assertCount(1, $this->recordedRequests);
    }

    public function testPostEmptyThrowsNetworkErrorWithStatusZero(): void
    {
        $esiClient = $this->createEsiClient([$this->networkErrorResponse()]);

        try {
            $esiClient->postEmpty('/ui/openwindow/marketdetails/?type_id=34', $this->createEveToken());
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(0, $exception->statusCode);
            $this->assertSame('Network error: Connection reset by peer', $exception->getMessage());
        }
    }

    // ---------------------------------------------------------------
    // Issue #43 -- handleResponse(): 4xx/5xx mapped to EsiApiException
    // ---------------------------------------------------------------

    /**
     * 429 is replayed once (Retry-After: 0), so it reaches the exception after a second 429.
     * 420 is left out: its replay always sleeps 1 s; testGetThrowsAfterSecondConsecutive420 covers it.
     */
    #[DataProvider('esiErrorStatusWithoutErrorLimitedProvider')]
    public function testGetThrowsEsiApiExceptionWithStatusMessageAndEndpointOfTheFailedResponse(int $statusCode, string $expectedMessage): void
    {
        $esiClient = $this->createEsiClient($statusCode === 429
            ? [$this->rateLimitedResponse(retryAfterSeconds: 0), $this->rateLimitedResponse(retryAfterSeconds: 0)]
            : [$this->errorResponse($statusCode)]);

        try {
            $esiClient->get('/characters/2112000001/wallet/journal/', $this->createEveToken());
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame($statusCode, $exception->statusCode);
            $this->assertSame($expectedMessage, $exception->getMessage());
            $this->assertSame('/characters/2112000001/wallet/journal/', $exception->endpoint);
        }
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function esiErrorStatusProvider(): iterable
    {
        yield '300 multiple choices' => [300, 'ESI request failed'];
        yield '400 bad request' => [400, 'ESI request failed'];
        yield '401 unauthorized' => [401, 'Authentication failed'];
        yield '403 forbidden' => [403, 'Access forbidden'];
        yield '404 not found' => [404, 'Resource not found'];
        yield '420 error limited' => [420, 'Error limited'];
        yield '429 rate limited' => [429, 'Rate limit exceeded'];
        yield '500 internal server error' => [500, 'ESI server error'];
        yield '502 bad gateway' => [502, 'ESI server error'];
        yield '503 service unavailable' => [503, 'ESI server error'];
        yield '504 gateway timeout' => [504, 'ESI server error'];
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function esiErrorStatusWithoutErrorLimitedProvider(): iterable
    {
        foreach (self::esiErrorStatusProvider() as $label => $statusAndMessage) {
            if ($statusAndMessage[0] !== 420) {
                yield $label => $statusAndMessage;
            }
        }
    }

    public function testGetThrowsNetworkErrorWithStatusZero(): void
    {
        $esiClient = $this->createEsiClient([$this->networkErrorResponse()]);

        try {
            $esiClient->get('/universe/systems/30000142/');
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(0, $exception->statusCode);
            $this->assertSame('Network error: Connection reset by peer', $exception->getMessage());
            $this->assertSame('/universe/systems/30000142/', $exception->endpoint);
        }
    }

    // ---------------------------------------------------------------
    // Issue #43 -- getScalar() and getScalarBatch()
    // ---------------------------------------------------------------

    public function testGetScalarReturnsDecodedScalarWithBearerAuthorizationAndDefaultTimeout(): void
    {
        $esiClient = $this->createEsiClient([$this->rawJsonResponse('1234567.89')]);

        $walletBalance = $esiClient->getScalar('/characters/2112000001/wallet/', $this->createEveToken());

        $this->assertSame(1234567.89, $walletBalance);
        $this->assertSame('GET', $this->recordedRequests[0]['method']);
        $this->assertSame(self::BASE_URL . '/characters/2112000001/wallet/', $this->recordedRequests[0]['url']);
        $this->assertSame(['Authorization: Bearer ' . self::ACCESS_TOKEN], $this->recordedRequests[0]['headers']['authorization'] ?? null);
        $this->assertSame(30.0, $this->recordedRequests[0]['timeout']);
    }

    public function testGetScalarSendsTheGivenTimeout(): void
    {
        $esiClient = $this->createEsiClient([$this->rawJsonResponse('1234567.89')]);

        $esiClient->getScalar('/characters/2112000001/wallet/', $this->createEveToken(), 5);

        $this->assertSame(5.0, $this->recordedRequests[0]['timeout']);
    }

    public function testGetScalarRetriesOnceAfterRateLimited429KeepingTokenAndTimeout(): void
    {
        // Issue #85: getScalar() replays a 429 once, like get()/post().
        $esiClient = $this->createEsiClient([
            $this->rateLimitedResponse(retryAfterSeconds: 0),
            $this->rawJsonResponse('1234567.89'),
        ]);

        $walletBalance = $esiClient->getScalar('/characters/2112000001/wallet/', $this->createEveToken(), 5);

        $this->assertSame(1234567.89, $walletBalance);
        $this->assertSame(['GET', 'GET'], array_column($this->recordedRequests, 'method'));
        $this->assertSame([
            self::BASE_URL . '/characters/2112000001/wallet/',
            self::BASE_URL . '/characters/2112000001/wallet/',
        ], array_column($this->recordedRequests, 'url'));
        $this->assertSame(['Authorization: Bearer ' . self::ACCESS_TOKEN], $this->recordedRequests[1]['headers']['authorization'] ?? null);
        $this->assertSame([5.0, 5.0], array_column($this->recordedRequests, 'timeout'));
    }

    public function testGetScalarThrows429WithExplicitMessageAfterSecondConsecutiveRateLimit(): void
    {
        // Issue #85: a second consecutive 429 is not replayed again and is reported as such.
        $esiClient = $this->createEsiClient([
            $this->rateLimitedResponse(retryAfterSeconds: 0),
            $this->rateLimitedResponse(retryAfterSeconds: 0),
        ]);

        try {
            $esiClient->getScalar('/characters/2112000001/wallet/', $this->createEveToken());
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(429, $exception->statusCode);
            $this->assertSame('Rate limit exceeded', $exception->getMessage());
            $this->assertSame('/characters/2112000001/wallet/', $exception->endpoint);
        }
        // Exactly one retry, no loop
        $this->assertCount(2, $this->recordedRequests);
    }

    public function testGetScalarThrowsEsiApiExceptionOnA300Response(): void
    {
        $esiClient = $this->createEsiClient([$this->errorResponse(300)]);

        try {
            $esiClient->getScalar('/characters/2112000001/wallet/', $this->createEveToken());
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(300, $exception->statusCode);
            $this->assertSame('ESI request failed', $exception->getMessage());
        }
    }

    public function testGetScalarReturnsJsonObjectAsStdClass(): void
    {
        $esiClient = $this->createEsiClient([$this->rawJsonResponse('{"total_sp":5000000}')]);

        $skills = $esiClient->getScalar('/characters/2112000001/skills/', $this->createEveToken());

        $this->assertEquals((object) ['total_sp' => 5000000], $skills);
    }

    public function testGetScalarThrowsNetworkErrorWithStatusZero(): void
    {
        $esiClient = $this->createEsiClient([$this->networkErrorResponse()]);

        try {
            $esiClient->getScalar('/characters/2112000001/wallet/', $this->createEveToken());
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(0, $exception->statusCode);
            $this->assertSame('Network error: Connection reset by peer', $exception->getMessage());
        }
    }

    public function testGetScalarBatchReturnsScalarsUnderTheirKeysAndNullForFailedRequests(): void
    {
        $esiClient = $this->createEsiClient([
            $this->rawJsonResponse('1500000.5'),
            $this->errorResponse(404),
            $this->networkErrorResponse(),
            $this->rawJsonResponse('42'),
            $this->errorResponse(300),
            $this->rawJsonResponse('{"total_sp":5000000}'),
        ]);

        $result = $esiClient->getScalarBatch([
            'pilot_one' => ['endpoint' => '/characters/2112000001/wallet/', 'token' => $this->createEveToken()],
            'pilot_two' => ['endpoint' => '/characters/2112000002/wallet/', 'token' => $this->createEveToken()],
            'pilot_three' => ['endpoint' => '/characters/2112000003/wallet/', 'token' => $this->createEveToken()],
            'public' => ['endpoint' => '/universe/system_kills/', 'token' => null],
            'redirected' => ['endpoint' => '/characters/2112000005/wallet/', 'token' => $this->createEveToken()],
            'skills' => ['endpoint' => '/characters/2112000001/skills/', 'token' => $this->createEveToken()],
        ]);

        $this->assertSame([
            'pilot_one' => 1500000.5,
            'pilot_two' => null,
            'pilot_three' => null,
            'public' => 42,
            'redirected' => null,
            'skills' => $result['skills'],
        ], $result);
        $this->assertEquals((object) ['total_sp' => 5000000], $result['skills']);
        // No retry on 4xx, 3xx or network error: one request per key.
        $this->assertSame([
            self::BASE_URL . '/characters/2112000001/wallet/',
            self::BASE_URL . '/characters/2112000002/wallet/',
            self::BASE_URL . '/characters/2112000003/wallet/',
            self::BASE_URL . '/universe/system_kills/',
            self::BASE_URL . '/characters/2112000005/wallet/',
            self::BASE_URL . '/characters/2112000001/skills/',
        ], array_column($this->recordedRequests, 'url'));
        $this->assertSame([10.0, 10.0, 10.0, 10.0, 10.0, 10.0], array_column($this->recordedRequests, 'timeout'));
        $this->assertSame(['Authorization: Bearer ' . self::ACCESS_TOKEN], $this->recordedRequests[0]['headers']['authorization'] ?? null);
        $this->assertArrayNotHasKey('authorization', $this->recordedRequests[3]['headers']);
    }

    public function testGetScalarBatchSendsTheGivenTimeout(): void
    {
        $esiClient = $this->createEsiClient([$this->rawJsonResponse('1500000.5')]);

        $esiClient->getScalarBatch(
            ['pilot_one' => ['endpoint' => '/characters/2112000001/wallet/', 'token' => $this->createEveToken()]],
            3,
        );

        $this->assertSame(3.0, $this->recordedRequests[0]['timeout']);
    }

    public function testGetScalarBatchRetriesRateLimited429KeyOnceAndReturnsItsValue(): void
    {
        // Issue #85: same mechanism as getBatch(), only the rate-limited key is replayed.
        $esiClient = $this->createEsiClientServingByPath([
            '/characters/2112000001/wallet/' => [
                $this->rateLimitedResponse(retryAfterSeconds: 0),
                $this->rawJsonResponse('1500000.5'),
            ],
            '/characters/2112000002/wallet/' => [$this->rawJsonResponse('42')],
        ]);

        $result = $esiClient->getScalarBatch([
            'pilot_one' => ['endpoint' => '/characters/2112000001/wallet/', 'token' => $this->createEveToken()],
            'pilot_two' => ['endpoint' => '/characters/2112000002/wallet/', 'token' => $this->createEveToken()],
        ]);

        $this->assertSame(['pilot_one' => 1500000.5, 'pilot_two' => 42], $result);
        $this->assertSame([
            self::BASE_URL . '/characters/2112000001/wallet/',
            self::BASE_URL . '/characters/2112000002/wallet/',
            self::BASE_URL . '/characters/2112000001/wallet/',
        ], array_column($this->recordedRequests, 'url'));
        $this->assertSame(['Authorization: Bearer ' . self::ACCESS_TOKEN], $this->recordedRequests[2]['headers']['authorization'] ?? null);
    }

    public function testGetScalarBatchReturnsNullAfterSecondConsecutive429ForThatKeyOnly(): void
    {
        $esiClient = $this->createEsiClientServingByPath([
            '/characters/2112000001/wallet/' => [
                $this->rateLimitedResponse(retryAfterSeconds: 0),
                $this->rateLimitedResponse(retryAfterSeconds: 0),
            ],
            '/characters/2112000002/wallet/' => [$this->rawJsonResponse('42')],
        ]);

        $result = $esiClient->getScalarBatch([
            'pilot_one' => ['endpoint' => '/characters/2112000001/wallet/', 'token' => $this->createEveToken()],
            'pilot_two' => ['endpoint' => '/characters/2112000002/wallet/', 'token' => $this->createEveToken()],
        ]);

        $this->assertSame(['pilot_one' => null, 'pilot_two' => 42], $result);
        // Exactly one retry, no loop
        $this->assertCount(3, $this->recordedRequests);
    }

    public function testGetScalarBatchWaitsOnceForAllRateLimitedKeysBeforeReplayingThem(): void
    {
        $logRecords = [];
        $esiClient = $this->createEsiClientServingByPath([
            '/characters/2112000001/wallet/' => [$this->rateLimitedResponse(retryAfterSeconds: 0), $this->rawJsonResponse('1500000.5')],
            '/characters/2112000002/wallet/' => [$this->rateLimitedResponse(retryAfterSeconds: 0), $this->rawJsonResponse('42')],
        ], $this->createRecordingLogger($logRecords));

        $result = $esiClient->getScalarBatch([
            'pilot_one' => ['endpoint' => '/characters/2112000001/wallet/', 'token' => $this->createEveToken()],
            'pilot_two' => ['endpoint' => '/characters/2112000002/wallet/', 'token' => $this->createEveToken()],
        ]);

        $this->assertSame(['pilot_one' => 1500000.5, 'pilot_two' => 42], $result);
        $this->assertSame([[
            'level' => 'warning',
            'message' => 'ESI 420/429 received on {count} batched requests, sleeping {seconds}s before retry',
            'context' => ['count' => 2, 'seconds' => 0],
        ]], $logRecords);
    }

    public function testGetScalarBatchGetScalarAndPostEmptyRecordErrorLimitHeadersSoTheNextRequestIsThrottled(): void
    {
        $logRecords = [];
        $esiClient = $this->createEsiClient([
            $this->rawJsonResponse('1500000.5', ['X-Esi-Error-Limit-Remain' => '19']),
            $this->rawJsonResponse('1500000.5', ['X-Esi-Error-Limit-Remain' => '18']),
            new MockResponse('', ['http_code' => 204, 'response_headers' => ['X-Esi-Error-Limit-Remain' => '17']]),
            $this->jsonResponse(['name' => 'Jita']),
        ], logger: $this->createRecordingLogger($logRecords));

        $esiClient->getScalarBatch([
            'pilot_one' => ['endpoint' => '/characters/2112000001/wallet/', 'token' => $this->createEveToken()],
        ]);
        $esiClient->getScalar('/characters/2112000001/wallet/', $this->createEveToken());
        $esiClient->postEmpty('/ui/openwindow/marketdetails/?type_id=34', $this->createEveToken());
        $esiClient->get('/universe/systems/30000142/');

        // Each request is throttled with the error limit of the previous response.
        $this->assertSame([
            ['remain' => 19, 'delay' => 100],
            ['remain' => 18, 'delay' => 200],
            ['remain' => 17, 'delay' => 300],
        ], array_column($logRecords, 'context'));
    }

    // ---------------------------------------------------------------

    public function testRequestIsNotThrottledWhenErrorLimitRemainIsTwenty(): void
    {
        $logRecords = [];
        $esiClient = $this->createEsiClient([
            $this->jsonResponse(['name' => 'Jita'], 200, ['X-Esi-Error-Limit-Remain' => '20']),
            $this->jsonResponse(['name' => 'Amarr']),
        ], logger: $this->createRecordingLogger($logRecords));

        $esiClient->get('/universe/systems/30000142/');
        $esiClient->get('/universe/systems/30002187/');

        $this->assertSame([], $logRecords);
    }

    public function testGetRecordsErrorLimitHeadersSoTheNextRequestIsThrottledByTheMissingErrors(): void
    {
        $logRecords = [];
        $esiClient = $this->createEsiClient([
            $this->jsonResponse(['name' => 'Jita'], 200, ['X-Esi-Error-Limit-Remain' => '19']),
            $this->jsonResponse(['name' => 'Amarr']),
        ], logger: $this->createRecordingLogger($logRecords));

        $esiClient->get('/universe/systems/30000142/');
        $esiClient->get('/universe/systems/30002187/');

        // 19 errors left: (20 - 19) * 100 ms.
        $this->assertSame([[
            'level' => 'info',
            'message' => 'ESI error limit low ({remain} remaining), throttling {delay}ms',
            'context' => ['remain' => 19, 'delay' => 100],
        ]], $logRecords);
    }

    public function testGetRetryAfter429IsNotThrottledAndKeepsTheErrorLimitOfThe429(): void
    {
        $logRecords = [];
        $esiClient = $this->createEsiClient([
            $this->rateLimitedResponse(retryAfterSeconds: 0, headers: ['X-Esi-Error-Limit-Remain' => '19']),
            $this->jsonResponseWithoutErrorLimitHeaders(['name' => 'Jita']),
            $this->jsonResponse(['name' => 'Amarr']),
        ], logger: $this->createRecordingLogger($logRecords));

        $esiClient->get('/universe/systems/30000142/');
        $esiClient->get('/universe/systems/30002187/');

        $this->assertSame([
            [
                'level' => 'warning',
                'message' => 'ESI {status} received, sleeping {seconds}s before retry',
                'context' => ['status' => 429, 'seconds' => 0, 'endpoint' => '/universe/systems/30000142/'],
            ],
            // The replay itself is not throttled; the next request is, with the 429's error limit.
            [
                'level' => 'info',
                'message' => 'ESI error limit low ({remain} remaining), throttling {delay}ms',
                'context' => ['remain' => 19, 'delay' => 100],
            ],
        ], $logRecords);
    }

    public function testGetWithCacheRecordsErrorLimitHeadersOf304SoTheNextRequestIsThrottled(): void
    {
        $clock = new MockClock();
        $issuedAt = $clock->now()->getTimestamp();
        $logRecords = [];
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]], 200, [
                'ETag' => '"etag-v1"',
                'Expires' => $this->httpDate($issuedAt + self::EXPIRES_AFTER_SECONDS),
            ]),
            $this->notModifiedResponse([
                'ETag' => '"etag-v1"',
                'X-Esi-Error-Limit-Remain' => '19',
            ]),
            $this->jsonResponse(['name' => 'Jita']),
        ], new ArrayAdapter(clock: $clock), $this->createRecordingLogger($logRecords));
        $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());
        $clock->sleep(self::EXPIRES_AFTER_SECONDS + 1);

        $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());
        $esiClient->get('/universe/systems/30000142/');

        $this->assertSame([['remain' => 19, 'delay' => 100]], array_column($logRecords, 'context'));
    }

    // ---------------------------------------------------------------
    // Issue #43 -- getBatch(): throttling and retry log
    // ---------------------------------------------------------------

    public function testGetBatchThrottlesOnceBeforeLaunchingAllRequestsWhenErrorLimitRemainIsLow(): void
    {
        $logRecords = [];
        $esiClient = $this->createEsiClient([
            $this->jsonResponse(['name' => 'Jita'], 200, ['X-Esi-Error-Limit-Remain' => '19']),
            $this->jsonResponse(['name' => 'Amarr']),
            $this->jsonResponse(['name' => 'Dodixie']),
        ], logger: $this->createRecordingLogger($logRecords));
        $esiClient->get('/universe/systems/30000142/');

        $result = $esiClient->getBatch([
            'amarr' => '/universe/systems/30002187/',
            'dodixie' => '/universe/systems/30002659/',
        ]);

        $this->assertSame(['amarr' => ['name' => 'Amarr'], 'dodixie' => ['name' => 'Dodixie']], $result);
        $this->assertSame([['remain' => 19, 'delay' => 100]], array_column($logRecords, 'context'));
    }

    public function testGetBatchWithoutEndpointsIsNotThrottled(): void
    {
        $logRecords = [];
        $esiClient = $this->createEsiClient([
            $this->jsonResponse(['name' => 'Jita'], 200, ['X-Esi-Error-Limit-Remain' => '19']),
        ], logger: $this->createRecordingLogger($logRecords));
        $esiClient->get('/universe/systems/30000142/');

        $this->assertSame([], $esiClient->getBatch([]));
        $this->assertSame([], $logRecords);
    }

    public function testGetBatchLogsRateLimitedKeyCountAndWaitBeforeReplaying(): void
    {
        $logRecords = [];
        $esiClient = $this->createEsiClientServingByPath([
            '/universe/systems/30000142/' => [$this->rateLimitedResponse(retryAfterSeconds: 0), $this->jsonResponse(['name' => 'Jita'])],
            '/universe/systems/30002187/' => [$this->rateLimitedResponse(retryAfterSeconds: 0), $this->jsonResponse(['name' => 'Amarr'])],
        ], $this->createRecordingLogger($logRecords));

        $esiClient->getBatch([
            'jita' => '/universe/systems/30000142/',
            'amarr' => '/universe/systems/30002187/',
        ]);

        $this->assertSame([[
            'level' => 'warning',
            'message' => 'ESI 420/429 received on {count} batched requests, sleeping {seconds}s before retry',
            'context' => ['count' => 2, 'seconds' => 0],
        ]], $logRecords);
    }

    public function testGetBatchReturnsNullForA300ResponseKey(): void
    {
        $esiClient = $this->createEsiClientServingByPath([
            '/universe/systems/30000142/' => [$this->errorResponse(300)],
        ]);

        $this->assertSame(['jita' => null], $esiClient->getBatch(['jita' => '/universe/systems/30000142/']));
    }

    // ---------------------------------------------------------------
    // Issue #43 -- getWithCache(): one cached copy per endpoint and character
    // ---------------------------------------------------------------

    public function testGetWithCacheForgetsTheStoredEtagWhenRevalidationReturns200WithoutEtag(): void
    {
        $clock = new MockClock();
        $issuedAt = $clock->now()->getTimestamp();
        $replacedAt = $issuedAt + self::EXPIRES_AFTER_SECONDS + 1;
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]], 200, [
                'ETag' => '"etag-v1"',
                'Expires' => $this->httpDate($issuedAt + self::EXPIRES_AFTER_SECONDS),
            ]),
            $this->jsonResponse([['type_id' => 34, 'quantity' => 750]], 200, [
                'Expires' => $this->httpDate($replacedAt + self::EXPIRES_AFTER_SECONDS),
            ]),
            $this->jsonResponse([['type_id' => 34, 'quantity' => 500]]),
        ], new ArrayAdapter(clock: $clock));
        $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());
        $clock->sleep(self::EXPIRES_AFTER_SECONDS + 1);
        $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());
        $clock->sleep(self::EXPIRES_AFTER_SECONDS + 1);

        $result = $esiClient->getWithCache('/characters/2112000001/assets/', $this->createEveToken());

        $this->assertSame([['type_id' => 34, 'quantity' => 500]], $result);
        $this->assertCount(3, $this->recordedRequests);
        $this->assertArrayNotHasKey('if-none-match', $this->recordedRequests[2]['headers']);
    }

    public function testGetWithCacheKeepsOneCopyPerCharacter(): void
    {
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]]),
            $this->jsonResponse([['type_id' => 35, 'quantity' => 2]]),
        ], new ArrayAdapter());
        $firstPilotToken = $this->createEveTokenForCharacter('0190a6f0-0000-7000-8000-000000000001');
        $secondPilotToken = $this->createEveTokenForCharacter('0190a6f0-0000-7000-8000-000000000002');

        $firstPilotAssets = $esiClient->getWithCache('/characters/2112000001/assets/', $firstPilotToken);
        $secondPilotAssets = $esiClient->getWithCache('/characters/2112000001/assets/', $secondPilotToken);
        $firstPilotCachedAssets = $esiClient->getWithCache('/characters/2112000001/assets/', $firstPilotToken);

        $this->assertSame([['type_id' => 34, 'quantity' => 1000]], $firstPilotAssets);
        $this->assertSame([['type_id' => 35, 'quantity' => 2]], $secondPilotAssets);
        $this->assertSame([['type_id' => 34, 'quantity' => 1000]], $firstPilotCachedAssets);
        $this->assertCount(2, $this->recordedRequests);
    }

    public function testGetWithCacheKeepsOneCopyPerEndpointForTheSameCharacter(): void
    {
        $esiClient = $this->createEsiClient([
            $this->jsonResponse([['type_id' => 34, 'quantity' => 1000]]),
            $this->jsonResponse([['blueprint_id' => 1000000001, 'runs' => 10]]),
        ], new ArrayAdapter());
        $pilotToken = $this->createEveTokenForCharacter('0190a6f0-0000-7000-8000-000000000001');

        $assets = $esiClient->getWithCache('/characters/2112000001/assets/', $pilotToken);
        $blueprints = $esiClient->getWithCache('/characters/2112000001/blueprints/', $pilotToken);

        $this->assertSame([['type_id' => 34, 'quantity' => 1000]], $assets);
        $this->assertSame([['blueprint_id' => 1000000001, 'runs' => 10]], $blueprints);
        $this->assertCount(2, $this->recordedRequests);
    }

    public function testGetWithCacheKeepsOneCopyPerPublicEndpoint(): void
    {
        $esiClient = $this->createEsiClient([
            $this->jsonResponse(['name' => 'Jita']),
            $this->jsonResponse(['name' => 'Amarr']),
        ], new ArrayAdapter());

        $jita = $esiClient->getWithCache('/universe/systems/30000142/');
        $amarr = $esiClient->getWithCache('/universe/systems/30002187/');

        $this->assertSame(['name' => 'Jita'], $jita);
        $this->assertSame(['name' => 'Amarr'], $amarr);
        $this->assertCount(2, $this->recordedRequests);
    }

    // ---------------------------------------------------------------
    // RED: issue #26, getUnversioned(): unversioned ESI routes (corp projects)
    // URL = origin of the configured base URL without its "/latest" segment,
    // dated by X-Compatibility-Date instead of a version prefix.
    // ---------------------------------------------------------------

    public function testGetUnversionedTargetsConfiguredOriginWithoutLatestSegment(): void
    {
        $esiClient = $this->createEsiClient([
            $this->jsonResponse(['projects' => [['id' => 'a1b2c3d4-project', 'state' => 'Active']]]),
        ]);

        $result = $esiClient->getUnversioned('/corporations/98000001/projects', $this->createEveToken(), '2025-12-16');

        $this->assertSame(['projects' => [['id' => 'a1b2c3d4-project', 'state' => 'Active']]], $result);
        $this->assertSame(['GET'], array_column($this->recordedRequests, 'method'));
        $this->assertSame(['https://esi.test/corporations/98000001/projects'], array_column($this->recordedRequests, 'url'));
    }

    public function testGetUnversionedSendsCompatibilityDateAndBearerAuthorization(): void
    {
        $esiClient = $this->createEsiClient([$this->jsonResponse(['contributors' => []])]);

        $esiClient->getUnversioned('/corporations/98000001/projects/a1b2c3d4-project/contributors', $this->createEveToken(), '2025-12-16');

        $this->assertSame(['X-Compatibility-Date: 2025-12-16'], $this->recordedRequests[0]['headers']['x-compatibility-date'] ?? null);
        $this->assertSame(['Authorization: Bearer ' . self::ACCESS_TOKEN], $this->recordedRequests[0]['headers']['authorization'] ?? null);
        $this->assertSame(['Accept: application/json'], $this->recordedRequests[0]['headers']['accept'] ?? null);
    }

    public function testGetUnversionedRetriesOnceAfterRateLimited429KeepingUrlAndHeaders(): void
    {
        $esiClient = $this->createEsiClient([
            $this->rateLimitedResponse(retryAfterSeconds: 0),
            $this->jsonResponse(['contributed' => 1000]),
        ]);

        $result = $esiClient->getUnversioned(
            '/corporations/98000001/projects/a1b2c3d4-project/contribution/2112000001',
            $this->createEveToken(),
            '2025-12-16',
        );

        $this->assertSame(['contributed' => 1000], $result);
        $this->assertSame(['GET', 'GET'], array_column($this->recordedRequests, 'method'));
        $this->assertSame([
            'https://esi.test/corporations/98000001/projects/a1b2c3d4-project/contribution/2112000001',
            'https://esi.test/corporations/98000001/projects/a1b2c3d4-project/contribution/2112000001',
        ], array_column($this->recordedRequests, 'url'));
        $this->assertSame(['X-Compatibility-Date: 2025-12-16'], $this->recordedRequests[1]['headers']['x-compatibility-date'] ?? null);
        $this->assertSame(['Authorization: Bearer ' . self::ACCESS_TOKEN], $this->recordedRequests[1]['headers']['authorization'] ?? null);
    }

    public function testGetUnversionedRetriesOnceAfterErrorLimited420(): void
    {
        $esiClient = $this->createEsiClient([
            $this->errorLimitedResponse(),
            $this->jsonResponse(['projects' => []]),
        ]);

        $result = $esiClient->getUnversioned('/corporations/98000001/projects', $this->createEveToken(), '2025-12-16');

        $this->assertSame(['projects' => []], $result);
        $this->assertCount(2, $this->recordedRequests);
    }

    public function testGetUnversionedThrows429AfterSecondConsecutiveRateLimit(): void
    {
        $esiClient = $this->createEsiClient([
            $this->rateLimitedResponse(retryAfterSeconds: 0),
            $this->rateLimitedResponse(retryAfterSeconds: 0),
        ]);

        try {
            $esiClient->getUnversioned('/corporations/98000001/projects', $this->createEveToken(), '2025-12-16');
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(429, $exception->statusCode);
        }
        // Exactly one retry, no loop
        $this->assertCount(2, $this->recordedRequests);
    }

    public function testGetUnversionedThrowsEsiApiExceptionWithStatusOnClientError(): void
    {
        $esiClient = $this->createEsiClient([$this->jsonResponse(['error' => 'Not found'], 404)]);

        try {
            $esiClient->getUnversioned('/corporations/98000001/projects/unknown-project', $this->createEveToken(), '2025-12-16');
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(404, $exception->statusCode);
        }
        $this->assertCount(1, $this->recordedRequests);
    }

    public function testGetUnversionedThrowsEsiApiExceptionWithStatusOnServerError(): void
    {
        $esiClient = $this->createEsiClient([$this->jsonResponse(['error' => 'Internal error'], 500)]);

        try {
            $esiClient->getUnversioned('/corporations/98000001/projects', $this->createEveToken(), '2025-12-16');
            $this->fail('Expected EsiApiException');
        } catch (EsiApiException $exception) {
            $this->assertSame(500, $exception->statusCode);
        }
    }

    public function testGetUnversionedRecordsErrorLimitHeadersSoTheNextRequestIsThrottled(): void
    {
        $logRecords = [];
        $esiClient = $this->createEsiClient([
            $this->jsonResponse(['projects' => []], 200, ['X-Esi-Error-Limit-Remain' => '19']),
            $this->jsonResponse(['name' => 'Jita']),
        ], logger: $this->createRecordingLogger($logRecords));

        $esiClient->getUnversioned('/corporations/98000001/projects', $this->createEveToken(), '2025-12-16');
        $this->assertSame([], $logRecords);

        // 19 errors left (< 20): the next request waits (20 - 19) * 100 ms.
        $esiClient->get('/universe/systems/30000142/');

        $throttleRecords = array_values(array_filter(
            $logRecords,
            static fn (array $logRecord): bool => ($logRecord['context']['remain'] ?? null) === 19,
        ));
        $this->assertCount(1, $throttleRecords);
        $this->assertSame(100, $throttleRecords[0]['context']['delay'] ?? null);
    }

    public function testGetKeepsLatestSegmentAfterAnUnversionedRequest(): void
    {
        $esiClient = $this->createEsiClient([
            $this->jsonResponse(['projects' => []]),
            $this->jsonResponse(['name' => 'Jita']),
        ]);

        $esiClient->getUnversioned('/corporations/98000001/projects', $this->createEveToken(), '2025-12-16');
        $esiClient->get('/universe/systems/30000142/');

        $this->assertSame([
            'https://esi.test/corporations/98000001/projects',
            self::BASE_URL . '/universe/systems/30000142/',
        ], array_column($this->recordedRequests, 'url'));
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
    private function createEsiClient(
        array $responses,
        ?CacheItemPoolInterface $esiCache = null,
        ?LoggerInterface $logger = null,
    ): EsiClient {
        $this->recordedRequests = [];

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->recordedRequests[] = [
                'method' => $method,
                'url' => $url,
                'body' => is_string($options['body'] ?? null) ? $options['body'] : '',
                'headers' => $options['normalized_headers'] ?? [],
                'timeout' => $options['timeout'] ?? null,
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
            $logger ?? new NullLogger(),
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

    /**
     * @param array<string, string> $headers
     */
    private function rateLimitedResponse(int $retryAfterSeconds, array $headers = []): MockResponse
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
                ...$headers,
            ],
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function errorResponse(int $statusCode, array $headers = []): MockResponse
    {
        return new MockResponse('{"error":"ESI error"}', [
            'http_code' => $statusCode,
            'response_headers' => [
                'Content-Type' => 'application/json',
                'X-Esi-Error-Limit-Remain' => '100',
                'X-Esi-Error-Limit-Reset' => '0',
                ...$headers,
            ],
        ]);
    }

    private function networkErrorResponse(): MockResponse
    {
        return new MockResponse('', ['error' => 'Connection reset by peer']);
    }

    /**
     * @param array<string, string> $headers
     */
    private function rawJsonResponse(string $json, array $headers = []): MockResponse
    {
        return new MockResponse($json, [
            'http_code' => 200,
            'response_headers' => [
                'Content-Type' => 'application/json',
                'X-Esi-Error-Limit-Remain' => '100',
                'X-Esi-Error-Limit-Reset' => '0',
                ...$headers,
            ],
        ]);
    }

    /**
     * @param array<mixed> $body
     */
    private function jsonResponseWithoutErrorLimitHeaders(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    /**
     * The character id is assigned by Doctrine on persist; set it as the database would.
     */
    private function createEveTokenForCharacter(string $characterId): EveToken
    {
        $character = new Character();
        (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::fromString($characterId));

        return $this->createEveToken()->setCharacter($character);
    }
}
