<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Exception\EsiApiException;
use App\Service\ESI\EsiClient;
use App\Service\ESI\EsiErrorBudget;
use App\Service\ESI\TokenManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(EsiClient::class)]
final class EsiClientErrorBudgetTest extends TestCase
{
    private const ASSETS_FAMILY = '/characters/{id}/assets/';

    private EsiErrorBudget $errorBudget;

    protected function setUp(): void
    {
        $this->errorBudget = new EsiErrorBudget(new ArrayAdapter());
    }

    public function testBudgetHeadersOfASuccessfulResponseAreShared(): void
    {
        $esiClient = $this->esiClient([$this->response(200, remain: '57', reset: '33')]);

        $esiClient->get('/status/');

        $snapshot = $this->errorBudget->snapshot();
        $this->assertSame(57, $snapshot['remain']);
        $this->assertSame(33, $snapshot['resetSeconds']);
    }

    public function testResponseWithoutBudgetHeadersObservesNothing(): void
    {
        $esiClient = $this->esiClient([new MockResponse('{}', ['response_headers' => ['Content-Type' => 'application/json']])]);

        $esiClient->get('/status/');

        $this->assertNull($this->errorBudget->snapshot()['remain']);
    }

    public function testErrorLimitedResponseIsCountedOnceThenReplayed(): void
    {
        $esiClient = $this->esiClient([$this->response(420), $this->response(200)]);

        $esiClient->get('/characters/123/assets/');

        $snapshot = $this->errorBudget->snapshot();
        $this->assertSame(1, $snapshot['limited420']);
        $this->assertSame(0, $snapshot['limited429']);
        $this->assertSame(
            [['family' => self::ASSETS_FAMILY, 'limited420' => 1, 'limited429' => 0]],
            $snapshot['byFamily'],
        );
    }

    public function testEveryLimitedResponseOfAFailedReplayIsCounted(): void
    {
        $esiClient = $this->esiClient([$this->response(429), $this->response(429)]);

        try {
            $esiClient->get('/characters/123/assets/');
            $this->fail('A second 429 must fail the call');
        } catch (EsiApiException) {
        }

        $this->assertSame(2, $this->errorBudget->snapshot()['limited429']);
    }

    public function testLimitedKeysOfABatchAreCountedOncePerResponse(): void
    {
        $esiClient = $this->esiClient([$this->response(200), $this->response(420), $this->response(200)]);

        $esiClient->getBatch(['a' => '/characters/1/assets/', 'b' => '/characters/2/assets/']);

        $this->assertSame(1, $this->errorBudget->snapshot()['limited420']);
    }

    public function testLimitedPageOfAPaginatedReadIsCounted(): void
    {
        $esiClient = $this->esiClient([$this->response(420), $this->response(420)]);

        try {
            $esiClient->getPaginated('/characters/123/assets/');
            $this->fail('A second 420 must fail the call');
        } catch (EsiApiException) {
        }

        $this->assertSame(2, $this->errorBudget->snapshot()['limited420']);
    }

    public function testNonLimitedFailureIsNotCounted(): void
    {
        $esiClient = $this->esiClient([$this->response(404)]);

        try {
            $esiClient->get('/characters/123/assets/');
            $this->fail('A 404 must fail the call');
        } catch (EsiApiException) {
        }

        $snapshot = $this->errorBudget->snapshot();
        $this->assertSame(0, $snapshot['limited420']);
        $this->assertSame(0, $snapshot['limited429']);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function esiClient(array $responses): EsiClient
    {
        $tokenManager = $this->createStub(TokenManager::class);

        return new EsiClient(
            httpClient: new MockHttpClient($responses),
            esiCache: new ArrayAdapter(),
            tokenManager: $tokenManager,
            baseUrl: 'https://esi.test/latest',
            logger: new NullLogger(),
            clock: new MockClock(),
            errorBudget: $this->errorBudget,
        );
    }

    private function response(int $statusCode, string $remain = '100', string $reset = '0'): MockResponse
    {
        return new MockResponse('{}', [
            'http_code' => $statusCode,
            'response_headers' => [
                'Content-Type' => 'application/json',
                'X-Esi-Error-Limit-Remain' => $remain,
                'X-Esi-Error-Limit-Reset' => $reset,
            ],
        ]);
    }
}
