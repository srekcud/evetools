<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Service\ESI\EsiClient;
use App\Service\ESI\EsiErrorBudget;
use App\Service\ESI\EsiRateLimitBudget;
use App\Service\ESI\TokenManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

#[CoversClass(EsiClient::class)]
#[Group('issue-822')]
final class EsiClientRateLimitTest extends TestCase
{
    private const ASSETS_ENDPOINT = '/characters/2112000001/assets/';
    private const STATUS_ENDPOINT = '/status/';
    private const PRICES_ENDPOINT = '/markets/prices/';
    private const PILOT_A_SCOPE = '0198c4a0-1111-7000-8000-00000000000a';
    private const PILOT_B_SCOPE = '0198c4a0-2222-7000-8000-00000000000b';
    private const FLOAT_DELTA = 0.001;

    private MockClock $clock;
    private EsiRateLimitBudget $rateLimitBudget;
    private EsiErrorBudget $errorBudget;
    private float $startedAt;

    /** @var list<array{path: string, at: float}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-12 10:00:00 UTC');
        $this->startedAt = $this->clockSeconds();
        $this->rateLimitBudget = new EsiRateLimitBudget(new ArrayAdapter(clock: $this->clock), clock: $this->clock);
        $this->errorBudget = new EsiErrorBudget(new ArrayAdapter(clock: $this->clock), clock: $this->clock);
    }

    public function testGroupBudgetObservedOnASuccessfulResponseWithoutErrorLimitHeadersIsStored(): void
    {
        $esiClient = $this->esiClient([
            new MockResponse('{}', ['response_headers' => [
                'Content-Type' => 'application/json',
                'X-Ratelimit-Group' => 'status',
                'X-Ratelimit-Limit' => '600/15m',
                'X-Ratelimit-Remaining' => '598',
                'X-Ratelimit-Used' => '2',
            ]]),
        ]);

        $esiClient->get(self::STATUS_ENDPOINT);

        $bucket = $this->rateLimitBudget->bucketOf(self::STATUS_ENDPOINT, EsiRateLimitBudget::PUBLIC_SCOPE);
        $this->assertNotNull($bucket);
        $this->assertSame('status', $bucket['group']);
        $this->assertSame(600, $bucket['limit']);
        $this->assertSame(900, $bucket['windowSeconds']);
        $this->assertSame(598, $bucket['remaining']);
        $this->assertNull($this->errorBudget->snapshot()['remain']);
        $this->assertSame(0.0, $this->elapsedSeconds());
    }

    public function testGroupBudgetOfABatchedResponseIsStoredForTheScopeOfItsToken(): void
    {
        $esiClient = $this->esiClient([$this->response(headers: $this->groupHeaders('assets', '150/15m', '77'))]);

        $esiClient->getBatch(['a' => self::ASSETS_ENDPOINT], $this->tokenOfCharacter(self::PILOT_A_SCOPE));

        $bucket = $this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE);
        $this->assertNotNull($bucket);
        $this->assertSame(77, $bucket['remaining']);
        $this->assertNull($this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_B_SCOPE));
    }

    public function testGroupBudgetOfAPaginatedPageIsStoredForTheScopeOfItsToken(): void
    {
        $esiClient = $this->esiClient([$this->response(headers: [...$this->groupHeaders('assets', '150/15m', '119'), 'X-Pages' => '1'])]);

        $esiClient->getPaginated(self::ASSETS_ENDPOINT, $this->tokenOfCharacter(self::PILOT_A_SCOPE));

        $bucket = $this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE);
        $this->assertNotNull($bucket);
        $this->assertSame(119, $bucket['remaining']);
    }

    public function testRequestIsNotDelayedWhenTheGroupBudgetIsOk(): void
    {
        $this->seedBucket(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 'assets', '150/15m', '100');
        $esiClient = $this->esiClient([$this->response()]);

        $esiClient->get(self::ASSETS_ENDPOINT, $this->tokenOfCharacter(self::PILOT_A_SCOPE));

        $this->assertSame(0.0, $this->requests[0]['at']);
    }

    public function testLowGroupBudgetSlowsTheNextRequestDown(): void
    {
        $this->seedBucket(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 'assets', '150/15m', '15');
        $esiClient = $this->esiClient([$this->response()]);

        $esiClient->get(self::ASSETS_ENDPOINT, $this->tokenOfCharacter(self::PILOT_A_SCOPE));

        $this->assertEqualsWithDelta(0.5, $this->requests[0]['at'], self::FLOAT_DELTA);
    }

    public function testCriticalGroupBudgetPausesTheNextRequestWithinTheCap(): void
    {
        $this->seedBucket(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 'assets', '150/15m', '3');
        $esiClient = $this->esiClient([$this->response()]);

        $esiClient->get(self::ASSETS_ENDPOINT, $this->tokenOfCharacter(self::PILOT_A_SCOPE));

        $this->assertGreaterThan(0.0, $this->requests[0]['at']);
        $this->assertLessThanOrEqual(EsiRateLimitBudget::MAX_PAUSE_SECONDS, $this->requests[0]['at']);
    }

    public function testCriticalPauseEndsWhenTheEstimatedRemainingIsBackAtTheLowThreshold(): void
    {
        $this->seedBucket(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 'assets', '150/1m', '3');
        $esiClient = $this->esiClient([$this->response()]);

        $esiClient->get(self::ASSETS_ENDPOINT, $this->tokenOfCharacter(self::PILOT_A_SCOPE));

        $this->assertGreaterThanOrEqual(10.8 - self::FLOAT_DELTA, $this->requests[0]['at']);
        $this->assertLessThan(11.8, $this->requests[0]['at']);
    }

    public function testTooManyRequestsRetryAfterBeyondTheCapPausesTheNextRequestForTheCap(): void
    {
        $this->seedBucket(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 'assets', '150/15m', '140');
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 429, [
            ...$this->headerLists($this->groupHeaders('assets', '150/15m', '0')),
            'retry-after' => ['120'],
        ]);
        $esiClient = $this->esiClient([$this->response()]);

        $esiClient->get(self::ASSETS_ENDPOINT, $this->tokenOfCharacter(self::PILOT_A_SCOPE));

        $this->assertEqualsWithDelta(60.0, $this->requests[0]['at'], self::FLOAT_DELTA);
    }

    public function testReplayAfterTooManyRequestsKeepsTheCappedRetryAfterSleepAndNothingMore(): void
    {
        $esiClient = $this->esiClient([
            $this->response(429, [...$this->groupHeaders('assets', '150/15m', '0'), 'Retry-After' => '120']),
            $this->response(headers: $this->groupHeaders('assets', '150/15m', '140')),
        ]);

        $esiClient->get(self::ASSETS_ENDPOINT, $this->tokenOfCharacter(self::PILOT_A_SCOPE));

        $this->assertCount(2, $this->requests);
        $this->assertEqualsWithDelta(0.0, $this->requests[0]['at'], self::FLOAT_DELTA);
        $this->assertEqualsWithDelta(60.0, $this->requests[1]['at'], self::FLOAT_DELTA);
        $this->assertEqualsWithDelta(60.0, $this->elapsedSeconds(), self::FLOAT_DELTA);
    }

    public function testTooManyRequestsIsRecordedWithItsRetryDeadline(): void
    {
        $esiClient = $this->esiClient([
            $this->response(429, [...$this->groupHeaders('assets', '150/15m', '0'), 'Retry-After' => '120']),
            $this->response(429, [...$this->groupHeaders('assets', '150/15m', '0'), 'Retry-After' => '120']),
        ]);

        try {
            $esiClient->get(self::ASSETS_ENDPOINT, $this->tokenOfCharacter(self::PILOT_A_SCOPE));
        } catch (\Throwable) {
        }

        $bucket = $this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE);
        $this->assertNotNull($bucket);
        $this->assertSame(0, $bucket['remaining']);
        $this->assertSame($this->startedAt + 60 + 120, (float) $bucket['retryUntil']);
    }

    public function testNothingIsPausedBeforeAnyGroupIsKnown(): void
    {
        $esiClient = $this->esiClient([$this->response(), $this->response()]);

        $esiClient->get(self::ASSETS_ENDPOINT, $this->tokenOfCharacter(self::PILOT_A_SCOPE));
        $esiClient->get('/characters/2112000001/skills/', $this->tokenOfCharacter(self::PILOT_A_SCOPE));

        $this->assertSame(0.0, $this->requests[0]['at']);
        $this->assertSame(0.0, $this->requests[1]['at']);
    }

    public function testEachCharacterIsThrottledOnItsOwnBucket(): void
    {
        $this->seedBucket(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 'assets', '150/15m', '3');
        $this->seedBucket(self::ASSETS_ENDPOINT, self::PILOT_B_SCOPE, 'assets', '150/15m', '100');
        $esiClient = $this->esiClient([$this->response(), $this->response()]);

        $esiClient->get(self::ASSETS_ENDPOINT, $this->tokenOfCharacter(self::PILOT_B_SCOPE));
        $esiClient->get(self::ASSETS_ENDPOINT, $this->tokenOfCharacter(self::PILOT_A_SCOPE));

        $this->assertSame(0.0, $this->requests[0]['at']);
        $this->assertGreaterThan(0.0, $this->requests[1]['at']);
    }

    public function testPublicRouteIsThrottledOnThePublicBucket(): void
    {
        $this->seedBucket(self::PRICES_ENDPOINT, EsiRateLimitBudget::PUBLIC_SCOPE, 'market', '300/15m', '30');
        $this->seedBucket(self::PRICES_ENDPOINT, self::PILOT_A_SCOPE, 'market', '300/15m', '280');
        $esiClient = $this->esiClient([$this->response()]);

        $esiClient->get(self::PRICES_ENDPOINT);

        $this->assertEqualsWithDelta(0.5, $this->requests[0]['at'], self::FLOAT_DELTA);
    }

    public function testTokenWithoutCharacterIsNeitherReadNorRecorded(): void
    {
        $this->seedBucket(self::ASSETS_ENDPOINT, EsiRateLimitBudget::PUBLIC_SCOPE, 'assets', '150/15m', '3');
        $esiClient = $this->esiClient([$this->response(headers: $this->groupHeaders('assets', '150/15m', '2'))]);

        $esiClient->get(self::ASSETS_ENDPOINT, new EveToken());

        $this->assertSame(0.0, $this->requests[0]['at']);
        $bucket = $this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, EsiRateLimitBudget::PUBLIC_SCOPE);
        $this->assertNotNull($bucket);
        $this->assertSame(3, $bucket['remaining']);
    }

    public function testErrorLimitPauseStaysAndTheGroupBudgetIsCheckedAfterIt(): void
    {
        $esiClient = $this->esiClient([
            $this->response(headers: ['X-Esi-Error-Limit-Remain' => '3', 'X-Esi-Error-Limit-Reset' => '30']),
            $this->response(),
        ]);
        $esiClient->get(self::STATUS_ENDPOINT);
        $this->seedBucket(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 'assets', '150/15m', '15');

        $esiClient->get(self::ASSETS_ENDPOINT, $this->tokenOfCharacter(self::PILOT_A_SCOPE));

        $this->assertGreaterThan(30.0, $this->requests[1]['at']);
        $this->assertLessThan(31.0, $this->requests[1]['at']);
    }

    public function testBatchWaitsBeforeLaunchingEveryRequestWhenItsSpendWouldEmptyTheGroupBudget(): void
    {
        $this->seedBucket(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 'assets', '150/15m', '50');
        $endpoints = [];
        for ($index = 1; $index <= 40; ++$index) {
            $endpoints["pilot{$index}"] = "/characters/{$index}/assets/";
        }
        $esiClient = $this->esiClient(array_map(fn (): MockResponse => $this->response(), $endpoints));

        $results = $esiClient->getBatch($endpoints, $this->tokenOfCharacter(self::PILOT_A_SCOPE));

        $this->assertCount(40, $this->requests);
        $this->assertCount(40, $results);
        foreach ($this->requests as $request) {
            $this->assertEqualsWithDelta(60.0, $request['at'], self::FLOAT_DELTA);
        }
    }

    public function testBatchWithinTheGroupBudgetIsLaunchedWithoutWaiting(): void
    {
        $this->seedBucket(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 'assets', '150/15m', '100');
        $endpoints = [];
        for ($index = 1; $index <= 10; ++$index) {
            $endpoints["pilot{$index}"] = "/characters/{$index}/assets/";
        }
        $esiClient = $this->esiClient(array_map(fn (): MockResponse => $this->response(), $endpoints));

        $esiClient->getBatch($endpoints, $this->tokenOfCharacter(self::PILOT_A_SCOPE));

        $this->assertCount(10, $this->requests);
        foreach ($this->requests as $request) {
            $this->assertSame(0.0, $request['at']);
        }
    }

    public function testBatchOfAnUnknownGroupIsLaunchedWithoutWaiting(): void
    {
        $endpoints = ['a' => '/characters/1/assets/', 'b' => '/characters/2/assets/'];
        $esiClient = $this->esiClient(array_map(fn (): MockResponse => $this->response(), $endpoints));

        $esiClient->getBatch($endpoints, $this->tokenOfCharacter(self::PILOT_A_SCOPE));

        $this->assertCount(2, $this->requests);
        $this->assertSame(0.0, $this->requests[0]['at']);
        $this->assertSame(0.0, $this->requests[1]['at']);
    }

    public function testScalarBatchWaitsOnlyForTheBucketWhoseSpendIsTooHigh(): void
    {
        $this->seedBucket(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 'assets', '150/15m', '4');
        $this->seedBucket(self::ASSETS_ENDPOINT, self::PILOT_B_SCOPE, 'assets', '150/15m', '100');
        $pilotA = $this->tokenOfCharacter(self::PILOT_A_SCOPE);
        $pilotB = $this->tokenOfCharacter(self::PILOT_B_SCOPE);
        $requests = [
            'a1' => ['endpoint' => '/characters/1/assets/', 'token' => $pilotA],
            'a2' => ['endpoint' => '/characters/2/assets/', 'token' => $pilotA],
            'a3' => ['endpoint' => '/characters/3/assets/', 'token' => $pilotA],
            'b1' => ['endpoint' => '/characters/4/assets/', 'token' => $pilotB],
        ];
        $esiClient = $this->esiClient(array_map(fn (): MockResponse => $this->response(), $requests));

        $results = $esiClient->getScalarBatch($requests);

        $this->assertCount(4, $this->requests);
        $this->assertSame(['a1', 'a2', 'a3', 'b1'], array_keys($results));
        foreach ($this->requests as $request) {
            $this->assertGreaterThanOrEqual(57.0 - self::FLOAT_DELTA, $request['at']);
            $this->assertLessThan(58.0, $request['at']);
        }
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function esiClient(array $responses): EsiClient
    {
        $queue = array_values($responses);
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$queue): MockResponse {
            $this->requests[] = ['path' => substr($url, strlen('https://esi.test/latest')), 'at' => $this->elapsedSeconds()];

            return array_shift($queue) ?? throw new \LogicException('No response queued for ' . $url);
        });

        return new EsiClient(
            httpClient: $httpClient,
            esiCache: new ArrayAdapter(),
            tokenManager: $this->createStub(TokenManager::class),
            baseUrl: 'https://esi.test/latest',
            logger: new NullLogger(),
            clock: $this->clock,
            errorBudget: $this->errorBudget,
            rateLimitBudget: $this->rateLimitBudget,
        );
    }

    private function seedBucket(string $endpoint, string $scope, string $group, string $limit, string $remaining): void
    {
        $this->rateLimitBudget->record($endpoint, $scope, 200, $this->headerLists($this->groupHeaders($group, $limit, $remaining)));
    }

    /**
     * @param array<string, string> $headers
     */
    private function response(int $statusCode = 200, array $headers = []): MockResponse
    {
        return new MockResponse('[]', [
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
     * @return array<string, string>
     */
    private function groupHeaders(string $group, string $limit, string $remaining): array
    {
        return [
            'X-Ratelimit-Group' => $group,
            'X-Ratelimit-Limit' => $limit,
            'X-Ratelimit-Remaining' => $remaining,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, list<string>>
     */
    private function headerLists(array $headers): array
    {
        $lists = [];
        foreach ($headers as $name => $value) {
            $lists[strtolower($name)] = [$value];
        }

        return $lists;
    }

    private function tokenOfCharacter(string $characterUuid): EveToken
    {
        $character = new Character();
        (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::fromString($characterUuid));

        return (new EveToken())->setCharacter($character);
    }

    private function clockSeconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    private function elapsedSeconds(): float
    {
        return $this->clockSeconds() - $this->startedAt;
    }
}
