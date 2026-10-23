<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Service\ESI\EveSsoTokenRevoker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(EveSsoTokenRevoker::class)]
#[Group('issue-53')]
final class EveSsoTokenRevokerTest extends TestCase
{
    private const string REVOCATION_URL = 'https://login.eveonline.com/v2/oauth/revoke';
    private const string CLIENT_ID = 'evetools-client-id';
    private const string CLIENT_SECRET = 'evetools-client-secret';
    private const string REFRESH_TOKEN = 'decrypted-refresh-token-of-kaelen';
    private const int EVE_CHARACTER_ID = 2_112_000_053;
    private const float REVOCATION_TIMEOUT_SECONDS = 5.0;

    private array $recordedRequests = [];

    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new BufferingLogger();
    }

    public function testRevocationPostsTheRefreshTokenToTheEveSsoRevocationEndpointWithBasicAuth(): void
    {
        $this->revoker(new MockResponse('', ['http_code' => 200]))->revoke(self::REFRESH_TOKEN, self::EVE_CHARACTER_ID);

        self::assertCount(1, $this->recordedRequests);
        self::assertSame('POST', $this->recordedRequests[0]['method']);
        self::assertSame(self::REVOCATION_URL, $this->recordedRequests[0]['url']);
        self::assertSame(
            ['Authorization: Basic ' . base64_encode(self::CLIENT_ID . ':' . self::CLIENT_SECRET)],
            $this->recordedRequests[0]['headers']['authorization'] ?? null,
        );
        self::assertSame(
            ['Content-Type: application/x-www-form-urlencoded'],
            $this->recordedRequests[0]['headers']['content-type'] ?? null,
        );
        parse_str($this->recordedRequests[0]['body'], $form);
        self::assertSame(['token_type_hint' => 'refresh_token', 'token' => self::REFRESH_TOKEN], $form);
    }

    public function testRevocationRequestTimesOutAfterFiveSeconds(): void
    {
        $this->revoker(new MockResponse('', ['http_code' => 200]))->revoke(self::REFRESH_TOKEN, self::EVE_CHARACTER_ID);

        self::assertCount(1, $this->recordedRequests);
        self::assertSame(self::REVOCATION_TIMEOUT_SECONDS, $this->recordedRequests[0]['timeout']);
    }

    public function testSuccessfulRevocationLogsNoWarning(): void
    {
        $this->revoker(new MockResponse('', ['http_code' => 200]))->revoke(self::REFRESH_TOKEN, self::EVE_CHARACTER_ID);

        self::assertSame([], $this->warnings());
    }

    public function testRevocationAnsweredWith500LogsOneWarningWithoutTheTokenAndDoesNotThrow(): void
    {
        $this->revoker(new MockResponse('{"error":"server_error"}', ['http_code' => 500]))->revoke(self::REFRESH_TOKEN, self::EVE_CHARACTER_ID);

        $warnings = $this->warnings();
        self::assertCount(1, $warnings);
        self::assertStringNotContainsString(self::REFRESH_TOKEN, $this->serialized($warnings[0]));
    }

    public function testRevocationRejectedWith400LogsOneWarningAndDoesNotThrow(): void
    {
        $this->revoker(new MockResponse('{"error":"invalid_request"}', ['http_code' => 400]))->revoke(self::REFRESH_TOKEN, self::EVE_CHARACTER_ID);

        $warnings = $this->warnings();
        self::assertCount(1, $warnings);
        self::assertStringNotContainsString(self::REFRESH_TOKEN, $this->serialized($warnings[0]));
    }

    public function testRevocationNetworkErrorLogsOneWarningWithoutTheTokenAndDoesNotThrow(): void
    {
        $this->revoker(new MockResponse('', ['error' => 'Could not resolve host: login.eveonline.com']))->revoke(self::REFRESH_TOKEN, self::EVE_CHARACTER_ID);

        $warnings = $this->warnings();
        self::assertCount(1, $warnings);
        self::assertStringNotContainsString(self::REFRESH_TOKEN, $this->serialized($warnings[0]));
    }

    private function revoker(MockResponse $response): EveSsoTokenRevoker
    {
        $this->recordedRequests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->recordedRequests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['normalized_headers'] ?? [],
                'body' => is_string($options['body'] ?? null) ? $options['body'] : '',
                'timeout' => $options['timeout'] ?? null,
            ];

            return $response;
        });

        return new EveSsoTokenRevoker($httpClient, $this->logger, self::CLIENT_ID, self::CLIENT_SECRET);
    }

    private function warnings(): array
    {
        return array_values(array_filter(
            $this->logger->cleanLogs(),
            static fn (array $record): bool => $record[0] === LogLevel::WARNING,
        ));
    }

    private function serialized(array $record): string
    {
        return $record[1] . json_encode($record[2], JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
