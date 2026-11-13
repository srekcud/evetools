<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sde;

use App\Service\Sde\SdeLatestBuildFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(SdeLatestBuildFetcher::class)]
#[Group('issue-848')]
final class SdeLatestBuildFetcherTest extends TestCase
{
    private const LATEST_URL = 'https://developers.eveonline.com/static-data/tranquility/latest.jsonl';

    public function testReadsTheLatestBuildPublishedByCcp(): void
    {
        $requested = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = [$method, $url];

            return new MockResponse('{"_key":"sde","buildNumber":3693310,"releaseDate":"2026-11-12T11:07:00Z"}');
        });

        $build = (new SdeLatestBuildFetcher($httpClient))->fetch();

        self::assertSame([['GET', self::LATEST_URL]], $requested);
        self::assertSame(3693310, $build->buildNumber);
        self::assertSame('2026-11-12T11:07:00+00:00', $build->releaseDate->format(\DateTimeInterface::ATOM));
    }

    #[DataProvider('unreadableResponses')]
    public function testFailsExplicitlyWhenTheLatestBuildCannotBeRead(MockResponse $response): void
    {
        $fetcher = new SdeLatestBuildFetcher(new MockHttpClient($response));

        $failure = null;
        try {
            $fetcher->fetch();
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertNotNull($failure, 'An unreadable latest build must never fall back to a default value');
        self::assertStringContainsString('latest', $failure->getMessage());
    }

    public static function unreadableResponses(): iterable
    {
        yield 'transport error' => [new MockResponse('', ['error' => 'connection refused'])];
        yield 'server error' => [new MockResponse('', ['http_code' => 500])];
        yield 'not found' => [new MockResponse('not found', ['http_code' => 404])];
        yield 'empty body' => [new MockResponse('')];
        yield 'not json' => [new MockResponse('<html>maintenance</html>')];
        yield 'no build number' => [new MockResponse('{"_key":"sde","releaseDate":"2026-11-12T11:07:00Z"}')];
        yield 'build number as string' => [new MockResponse('{"_key":"sde","buildNumber":"3693310","releaseDate":"2026-11-12T11:07:00Z"}')];
        yield 'build number as float' => [new MockResponse('{"_key":"sde","buildNumber":3693310.5,"releaseDate":"2026-11-12T11:07:00Z"}')];
        yield 'null build number' => [new MockResponse('{"_key":"sde","buildNumber":null,"releaseDate":"2026-11-12T11:07:00Z"}')];
        yield 'no release date' => [new MockResponse('{"_key":"sde","buildNumber":3693310}')];
        yield 'null release date' => [new MockResponse('{"_key":"sde","buildNumber":3693310,"releaseDate":null}')];
        yield 'unreadable release date' => [new MockResponse('{"_key":"sde","buildNumber":3693310,"releaseDate":"soon"}')];
    }
}
