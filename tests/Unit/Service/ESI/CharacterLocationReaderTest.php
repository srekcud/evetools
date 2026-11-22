<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\CharacterLocation;
use App\Service\ESI\CharacterLocationReader;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(CharacterLocationReader::class)]
#[CoversClass(CharacterLocation::class)]
#[Group('issue-1297')]
final class CharacterLocationReaderTest extends TestCase
{
    private const string ESI_BASE_URL = 'https://esi.test/latest';
    private const string ACCESS_TOKEN = 'access-token-of-brann-holt';
    private const int BRANN_HOLT_EVE_CHARACTER_ID = 2_112_262_103;
    private const int JITA_SYSTEM_ID = 30_000_142;
    private const int JITA_FORTIZAR_STATION_ID = 60_003_760;
    private const int KEEPSTAR_STRUCTURE_ID = 1_040_000_000_001;
    private const string LOCATION_URL = self::ESI_BASE_URL . '/characters/' . self::BRANN_HOLT_EVE_CHARACTER_ID . '/location/';
    private const string ONLINE_URL = self::ESI_BASE_URL . '/characters/' . self::BRANN_HOLT_EVE_CHARACTER_ID . '/online/';

    private array $requestedUrls = [];
    private array $authorizations = [];

    public function testDockedCharacterIsLocatedAtItsStationAndOnline(): void
    {
        $reader = $this->readerAnswering([
            self::LOCATION_URL => new MockResponse(sprintf('{"solar_system_id":%d,"station_id":%d}', self::JITA_SYSTEM_ID, self::JITA_FORTIZAR_STATION_ID)),
            self::ONLINE_URL => new MockResponse('{"online":true,"last_login":"2026-11-22T10:15:00Z","last_logout":"2026-11-21T22:00:00Z","logins":412}'),
        ]);

        $location = $reader->read($this->brannHolt());

        self::assertEquals(
            new CharacterLocation(self::JITA_SYSTEM_ID, self::JITA_FORTIZAR_STATION_ID, null, true, new \DateTimeImmutable('2026-11-22T10:15:00Z')),
            $location,
        );
    }

    public function testCharacterDockedInAStructureIsLocatedAtItsStructure(): void
    {
        $reader = $this->readerAnswering([
            self::LOCATION_URL => new MockResponse(sprintf('{"solar_system_id":%d,"structure_id":%d}', self::JITA_SYSTEM_ID, self::KEEPSTAR_STRUCTURE_ID)),
            self::ONLINE_URL => new MockResponse('{"online":false,"last_login":"2026-11-20T08:00:00Z"}'),
        ]);

        $location = $reader->read($this->brannHolt());

        self::assertSame(
            [self::JITA_SYSTEM_ID, null, self::KEEPSTAR_STRUCTURE_ID, false],
            [$location?->solarSystemId, $location?->stationId, $location?->structureId, $location?->online],
        );
    }

    public function testCharacterInSpaceHasNeitherStationNorStructure(): void
    {
        $reader = $this->readerAnswering([
            self::LOCATION_URL => new MockResponse(sprintf('{"solar_system_id":%d}', self::JITA_SYSTEM_ID)),
            self::ONLINE_URL => new MockResponse('{"online":true}'),
        ]);

        $location = $reader->read($this->brannHolt());

        self::assertSame(
            [self::JITA_SYSTEM_ID, null, null, true, null],
            [$location?->solarSystemId, $location?->stationId, $location?->structureId, $location?->online, $location?->lastLoginAt],
        );
    }

    public function testBothEndpointsAreReadWithTheTokenOfTheCharacter(): void
    {
        $reader = $this->readerAnswering([
            self::LOCATION_URL => new MockResponse(sprintf('{"solar_system_id":%d}', self::JITA_SYSTEM_ID)),
            self::ONLINE_URL => new MockResponse('{"online":true}'),
        ]);

        $reader->read($this->brannHolt());

        self::assertSame(
            [[self::LOCATION_URL, self::ONLINE_URL], ['Authorization: Bearer ' . self::ACCESS_TOKEN, 'Authorization: Bearer ' . self::ACCESS_TOKEN]],
            [$this->requestedUrls, $this->authorizations],
        );
    }

    public function testSecondReadWithinTheEsiCacheLifetimeDoesNotAskEsiAgain(): void
    {
        $reader = $this->readerAnswering([
            self::LOCATION_URL => new MockResponse(sprintf('{"solar_system_id":%d}', self::JITA_SYSTEM_ID), ['response_headers' => ['Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT']]),
            self::ONLINE_URL => new MockResponse('{"online":true}', ['response_headers' => ['Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT']]),
        ]);

        $reader->read($this->brannHolt());
        $reader->read($this->brannHolt());

        self::assertCount(2, $this->requestedUrls);
    }

    #[DataProvider('locationAnswersWithoutASolarSystem')]
    public function testLocationAnswerWithoutAnIntegerSolarSystemLeavesTheLocationUnknown(string $body): void
    {
        $reader = $this->readerAnswering([
            self::LOCATION_URL => new MockResponse($body),
            self::ONLINE_URL => new MockResponse('{"online":true}'),
        ]);

        self::assertNull($reader->read($this->brannHolt()));
    }

    public static function locationAnswersWithoutASolarSystem(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'string' => ['{"solar_system_id":"30000142"}'];
        yield 'float' => ['{"solar_system_id":30000142.5}'];
        yield 'null' => ['{"solar_system_id":null}'];
    }

    #[DataProvider('refusedOrBrokenEsiStatuses')]
    public function testEsiErrorOnTheLocationLeavesTheLocationUnknownInsteadOfFailing(int $statusCode): void
    {
        $reader = $this->readerAnswering([
            self::LOCATION_URL => new MockResponse('{"error":"failure"}', ['http_code' => $statusCode]),
            self::ONLINE_URL => new MockResponse('{"online":true}'),
        ]);

        self::assertNull($reader->read($this->brannHolt()));
    }

    public static function refusedOrBrokenEsiStatuses(): iterable
    {
        yield '403 forbidden' => [403];
        yield '502 bad gateway' => [502];
    }

    public function testEsiErrorOnTheOnlineStatusKeepsTheLocationWithAnUnknownOnlineStatus(): void
    {
        $reader = $this->readerAnswering([
            self::LOCATION_URL => new MockResponse(sprintf('{"solar_system_id":%d,"station_id":%d}', self::JITA_SYSTEM_ID, self::JITA_FORTIZAR_STATION_ID)),
            self::ONLINE_URL => new MockResponse('{"error":"failure"}', ['http_code' => 403]),
        ]);

        $location = $reader->read($this->brannHolt());

        self::assertSame(
            [self::JITA_SYSTEM_ID, self::JITA_FORTIZAR_STATION_ID, null, null],
            [$location?->solarSystemId, $location?->stationId, $location?->online, $location?->lastLoginAt],
        );
    }

    #[DataProvider('onlineAnswersWithoutABoolean')]
    public function testOnlineAnswerWithoutABooleanLeavesTheOnlineStatusUnknown(string $body): void
    {
        $reader = $this->readerAnswering([
            self::LOCATION_URL => new MockResponse(sprintf('{"solar_system_id":%d}', self::JITA_SYSTEM_ID)),
            self::ONLINE_URL => new MockResponse($body),
        ]);

        self::assertNull($reader->read($this->brannHolt())?->online);
    }

    public static function onlineAnswersWithoutABoolean(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'integer' => ['{"online":1}'];
        yield 'string' => ['{"online":"true"}'];
    }

    public function testRevokedCharacterIsUnknownWithoutAnyEsiRequest(): void
    {
        $reader = $this->readerAnswering([], refusedToken: true);

        self::assertSame([null, []], [$reader->read($this->brannHolt()), $this->requestedUrls]);
    }

    public function testCharacterWithoutTokenIsUnknownWithoutAnyEsiRequest(): void
    {
        $reader = $this->readerAnswering([]);
        $withoutToken = (new Character())->setEveCharacterId(2_112_262_105)->setName('Brann III');

        self::assertSame([null, []], [$reader->read($withoutToken), $this->requestedUrls]);
    }

    private function brannHolt(): Character
    {
        $brannHolt = (new Character())->setEveCharacterId(self::BRANN_HOLT_EVE_CHARACTER_ID)->setName('Brann Holt');
        $brannHolt->setEveToken((new EveToken())->setScopes(['esi-location.read_location.v1', 'esi-location.read_online.v1']));

        return $brannHolt;
    }

    /**
     * @param array<string, MockResponse> $responsesByUrl
     */
    private function readerAnswering(array $responsesByUrl, bool $refusedToken = false): CharacterLocationReader
    {
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturnCallback(static function () use ($refusedToken): string {
            if ($refusedToken) {
                throw new EveAuthRequiredException((string) self::BRANN_HOLT_EVE_CHARACTER_ID);
            }

            return self::ACCESS_TOKEN;
        });

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($responsesByUrl): MockResponse {
            $this->requestedUrls[] = $url;
            $this->authorizations[] = current(array_filter($options['headers'] ?? [], static fn (string $header): bool => str_starts_with($header, 'Authorization:')));

            return $responsesByUrl[$url] ?? new MockResponse('{"error":"unexpected url"}', ['http_code' => 500]);
        });

        return new CharacterLocationReader(
            new EsiClient($httpClient, new TagAwareAdapter(new ArrayAdapter()), $tokenManager, self::ESI_BASE_URL, new NullLogger(), new MockClock('2026-11-05T20:00:02+00:00')),
        );
    }
}
