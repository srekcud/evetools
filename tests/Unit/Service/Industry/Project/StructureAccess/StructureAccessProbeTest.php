<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\StructureAccess;

use App\Enum\StructureAccessStatus;
use App\Exception\EsiApiException;
use App\Service\Industry\Project\StructureAccess\StructureAccessProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(StructureAccessProbe::class)]
#[Group('issue-1299')]
final class StructureAccessProbeTest extends TestCase
{
    use BuildsStructureAccess;

    private const string STRUCTURE_URL = self::ESI_BASE_URL.'/universe/structures/'.self::KEEPSTAR_STRUCTURE_ID.'/';

    public function testStructureReadWithTheTokenOfTheCharacterIsGranted(): void
    {
        $status = $this->probeAnswering(static fn (): MockResponse => new MockResponse('{"name":"Keepstar","owner_id":98206101,"solar_system_id":30000144}'))
            ->probe($this->characterOf(), self::KEEPSTAR_STRUCTURE_ID);

        self::assertSame(StructureAccessStatus::Granted, $status);
        self::assertSame([self::STRUCTURE_URL], $this->requestedUrls);
        self::assertSame(['Authorization: Bearer '.self::ACCESS_TOKEN], $this->authorizations);
    }

    #[DataProvider('forbiddenCharactersWithTheirStatus')]
    public function testForbiddenAnswerIsDeniedOnlyForATokenWithTheStructureScope(bool $withScope, StructureAccessStatus $expected): void
    {
        $status = $this->probeAnswering(static fn (): MockResponse => new MockResponse('{"error":"Forbidden"}', ['http_code' => 403]))
            ->probe($this->characterOf($withScope), self::KEEPSTAR_STRUCTURE_ID);

        self::assertSame($expected, $status);
    }

    public static function forbiddenCharactersWithTheirStatus(): iterable
    {
        yield 'token with the scope' => [true, StructureAccessStatus::Denied];
        yield 'token without the scope' => [false, StructureAccessStatus::Unknown];
    }

    #[DataProvider('failuresThatProveNothing')]
    public function testAnyOtherFailureIsUnknown(\Closure $answer): void
    {
        $status = $this->probeAnswering($answer)->probe($this->characterOf(), self::KEEPSTAR_STRUCTURE_ID);

        self::assertSame(StructureAccessStatus::Unknown, $status);
    }

    public static function failuresThatProveNothing(): iterable
    {
        yield 'unauthorized' => [static fn (): MockResponse => new MockResponse('{"error":"Unauthorized"}', ['http_code' => 401])];
        yield 'not found' => [static fn (): MockResponse => new MockResponse('{"error":"Not found"}', ['http_code' => 404])];
        yield 'error limited' => [static fn (): MockResponse => new MockResponse('{"error":"Error limited"}', ['http_code' => 420])];
        yield 'rate limited' => [static fn (): MockResponse => new MockResponse('{"error":"Too many requests"}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '0']])];
        yield 'server error' => [static fn (): MockResponse => new MockResponse('{"error":"Bad gateway"}', ['http_code' => 502])];
        yield 'network error' => [static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused'])];
    }

    public function testCharacterWithoutUsableTokenIsUnknownWithoutAnyRequest(): void
    {
        $probe = $this->probeAnswering(static fn (): MockResponse => new MockResponse('{"name":"Keepstar"}'));

        $withoutToken = $probe->probe($this->characterOf(withToken: false), self::KEEPSTAR_STRUCTURE_ID);

        self::assertSame(StructureAccessStatus::Unknown, $withoutToken);
        self::assertSame([], $this->requestedUrls);
    }

    #[DataProvider('tokenFailuresThatProveNothing')]
    public function testFailureOfTheTokenRefreshIsUnknownNeverDenied(\Throwable $tokenFailure): void
    {
        $probe = $this->probeAnswering(static fn (): MockResponse => new MockResponse('{"name":"Keepstar"}'), tokenFailure: $tokenFailure);

        $status = $probe->probe($this->characterOf(), self::KEEPSTAR_STRUCTURE_ID);

        self::assertSame(StructureAccessStatus::Unknown, $status);
        self::assertSame([], $this->requestedUrls);
    }

    public static function tokenFailuresThatProveNothing(): iterable
    {
        yield 'single sign-on refused the refresh' => [EsiApiException::forbidden('Single sign-on refused the refresh')];
        yield 'token cannot be decrypted' => [new \RuntimeException('Cannot decrypt the refresh token')];
    }

    public function testCharacterWhoseAuthMustBeRedoneIsUnknown(): void
    {
        $probe = $this->probeAnswering(static fn (): MockResponse => new MockResponse('{"name":"Keepstar"}'), refusedToken: true);

        $status = $probe->probe($this->characterOf(), self::KEEPSTAR_STRUCTURE_ID);

        self::assertSame(StructureAccessStatus::Unknown, $status);
    }
}
