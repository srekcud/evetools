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
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Note on sleeping: EsiClient calls sleep() directly. A 420 retry sleeps
 * max(X-Esi-Error-Limit-Reset, 1) = 1 s, so each 420 scenario costs 1 s.
 * 429 scenarios use Retry-After: 0. Error-limit-remain is kept at 100 so
 * throttleIfNeeded() never sleeps.
 */
#[CoversClass(EsiClient::class)]
final class EsiClientTest extends TestCase
{
    private const BASE_URL = 'https://esi.test/latest';
    private const ACCESS_TOKEN = 'access-token-abc';

    /** @var list<array{method: string, url: string, body: string, headers: array<string, list<string>>}> */
    private array $recordedRequests = [];

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
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param list<MockResponse> $responses served in order; each request is recorded
     */
    private function createEsiClient(array $responses): EsiClient
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
            $this->createStub(CacheItemPoolInterface::class),
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
