<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\MiningOperation\MiningCharacterShipReader;
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

#[CoversClass(MiningCharacterShipReader::class)]
#[Group('issue-364')]
final class MiningCharacterShipReaderTest extends TestCase
{
    private const string ESI_BASE_URL = 'https://esi.test/latest';
    private const string ACCESS_TOKEN = 'access-token-of-brann-holt';
    private const int BRANN_HOLT_EVE_CHARACTER_ID = 2_112_262_103;
    private const int HULK = 22544;

    private array $requestedUrls = [];
    private array $authorizations = [];

    public function testShipTypeIdOfTheCharacterIsReadWithItsToken(): void
    {
        $reader = $this->readerAnswering(new MockResponse('{"ship_item_id":1040000000001,"ship_name":"Brann\'s Hulk","ship_type_id":22544}'));

        $shipTypeId = $reader->read($this->brannHolt());

        self::assertSame(
            [self::HULK, [sprintf('%s/characters/%d/ship/', self::ESI_BASE_URL, self::BRANN_HOLT_EVE_CHARACTER_ID)], ['Authorization: Bearer ' . self::ACCESS_TOKEN]],
            [$shipTypeId, $this->requestedUrls, $this->authorizations],
        );
    }

    #[DataProvider('answersWithoutAShipTypeId')]
    public function testAnswerWithoutAnIntegerShipTypeIdLeavesTheShipUnknown(string $body): void
    {
        $reader = $this->readerAnswering(new MockResponse($body));

        self::assertNull($reader->read($this->brannHolt()));
    }

    public static function answersWithoutAShipTypeId(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'string' => ['{"ship_type_id":"22544"}'];
        yield 'float' => ['{"ship_type_id":22544.5}'];
        yield 'null' => ['{"ship_type_id":null}'];
    }

    #[DataProvider('refusedOrBrokenEsiStatuses')]
    public function testEsiErrorLeavesTheShipUnknownInsteadOfFailing(int $statusCode): void
    {
        $reader = $this->readerAnswering(new MockResponse('{"error":"failure"}', ['http_code' => $statusCode]));

        self::assertNull($reader->read($this->brannHolt()));
    }

    public static function refusedOrBrokenEsiStatuses(): iterable
    {
        yield '403 forbidden' => [403];
        yield '502 bad gateway' => [502];
    }

    public function testRevokedCharacterLeavesTheShipUnknownWithoutAnyEsiRequest(): void
    {
        $reader = $this->readerAnswering(new MockResponse('{"ship_type_id":22544}'), refusedToken: true);

        self::assertSame([null, []], [$reader->read($this->brannHolt()), $this->requestedUrls]);
    }

    public function testCharacterWithoutTokenLeavesTheShipUnknownWithoutAnyEsiRequest(): void
    {
        $reader = $this->readerAnswering(new MockResponse('{"ship_type_id":22544}'));
        $withoutToken = (new Character())->setEveCharacterId(2_112_262_105)->setName('Brann III');

        self::assertSame([null, []], [$reader->read($withoutToken), $this->requestedUrls]);
    }

    private function brannHolt(): Character
    {
        $brannHolt = (new Character())->setEveCharacterId(self::BRANN_HOLT_EVE_CHARACTER_ID)->setName('Brann Holt');
        $brannHolt->setEveToken((new EveToken())->setScopes(['esi-location.read_ship_type.v1']));

        return $brannHolt;
    }

    private function readerAnswering(MockResponse $response, bool $refusedToken = false): MiningCharacterShipReader
    {
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturnCallback(static function () use ($refusedToken): string {
            if ($refusedToken) {
                throw new EveAuthRequiredException((string) self::BRANN_HOLT_EVE_CHARACTER_ID);
            }

            return self::ACCESS_TOKEN;
        });

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requestedUrls[] = $url;
            $this->authorizations[] = current(array_filter($options['headers'] ?? [], static fn (string $header): bool => str_starts_with($header, 'Authorization:')));

            return $response;
        });

        return new MiningCharacterShipReader(
            new EsiClient($httpClient, new TagAwareAdapter(new ArrayAdapter()), $tokenManager, self::ESI_BASE_URL, new NullLogger(), new MockClock('2026-11-05T20:00:02+00:00')),
        );
    }
}
