<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Exception\EveAuthRequiredException;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\MiningOperation\MiningLedgerEntry;
use App\Service\MiningOperation\MiningLedgerReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(MiningLedgerReader::class)]
#[Group('issue-263')]
final class MiningLedgerReaderTest extends TestCase
{
    use BuildsSpodumainOperation;

    private const string ESI_BASE_URL = 'https://esi.test/latest';
    private const string ACCESS_TOKEN = 'access-token-of-brann-holt';

    private array $requestedUrls = [];
    private array $authorizations = [];

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
    }

    public function testEveryPageOfTheMiningLedgerIsReadWithTheEarliestCacheExpiry(): void
    {
        $brannHolt = $this->character('Brann Holt');
        $reader = $this->readerAnswering([
            1 => $this->ledgerPage([['2026-11-05', 19, 30_003_504, 12_000], ['2026-11-04', 1223, 30_002_813, 2_400]], 2, 'Thu, 05 Nov 2026 20:09:40 GMT'),
            2 => $this->ledgerPage([['2026-10-28', 19, 30_003_504, 7_300]], 2, 'Thu, 05 Nov 2026 20:09:41 GMT'),
        ]);

        $reading = $reader->read($brannHolt);

        self::assertSame(
            [['2026-11-05', 19, 30_003_504, 12_000], ['2026-11-04', 1223, 30_002_813, 2_400], ['2026-10-28', 19, 30_003_504, 7_300]],
            array_map(static fn (MiningLedgerEntry $entry): array => [$entry->date->format('Y-m-d'), $entry->typeId, $entry->solarSystemId, $entry->quantity], $reading->entries),
        );
        self::assertSame('2026-11-05T20:09:40+00:00', $reading->expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM));
        self::assertSame(
            [
                sprintf('%s/characters/%d/mining/?page=1', self::ESI_BASE_URL, $brannHolt->getEveCharacterId()),
                sprintf('%s/characters/%d/mining/?page=2', self::ESI_BASE_URL, $brannHolt->getEveCharacterId()),
            ],
            $this->requestedUrls,
        );
        self::assertSame(['Authorization: Bearer ' . self::ACCESS_TOKEN, 'Authorization: Bearer ' . self::ACCESS_TOKEN], $this->authorizations);
    }

    public function testEmptyMiningLedgerIsALedgerReadWithoutEntry(): void
    {
        $reader = $this->readerAnswering([1 => $this->ledgerPage([], 1, 'Thu, 05 Nov 2026 20:09:40 GMT')]);

        $reading = $reader->read($this->character('Aria II'));

        self::assertSame(
            [[], '2026-11-05T20:09:40+00:00'],
            [$reading->entries, $reading->expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)],
        );
    }

    public function testLedgerPageWithoutExpiresHeaderGivesNoCacheExpiry(): void
    {
        $reader = $this->readerAnswering([1 => $this->ledgerPage([['2026-11-05', 19, 30_003_504, 6_000]], 1, null)]);

        $reading = $reader->read($this->character('Aria II'));

        self::assertSame([1, null], [count($reading->entries), $reading->expiresAt]);
    }

    public function testForbiddenLedgerIsAnEsiErrorWithItsStatus(): void
    {
        $reader = $this->readerAnswering([1 => new MockResponse('{"error":"token is not valid for scope(s): esi-industry.read_character_mining.v1"}', ['http_code' => 403])]);

        $this->expectExceptionObject(EsiApiException::fromResponse(403, 'ESI request failed'));

        $reader->read($this->character('Brann II'));
    }

    public function testServerErrorIsAnEsiErrorWithItsStatus(): void
    {
        $reader = $this->readerAnswering([1 => new MockResponse('{"error":"Bad gateway"}', ['http_code' => 502])]);

        try {
            $reader->read($this->character('Brann II'));
            self::fail('A 502 from ESI must not become an empty ledger');
        } catch (EsiApiException $esiError) {
            self::assertSame(502, $esiError->statusCode);
        }
    }

    public function testRevokedCharacterIsRefusedBeforeAnyEsiRequest(): void
    {
        $reader = $this->readerAnswering([1 => $this->ledgerPage([], 1, null)], refusedToken: true);

        try {
            $reader->read($this->character('Brann II'));
            self::fail('A revoked character must need reconnecting');
        } catch (EveAuthRequiredException $authRequired) {
            self::assertSame([(string) $this->character('Brann II')->getEveCharacterId(), []], [$authRequired->characterId, $this->requestedUrls]);
        }
    }

    private function readerAnswering(array $responsesByPage, bool $refusedToken = false): MiningLedgerReader
    {
        $tokenManager = $this->createStub(TokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturnCallback(function (EveToken $token) use ($refusedToken): string {
            if ($refusedToken) {
                throw new EveAuthRequiredException((string) $token->getCharacter()?->getEveCharacterId());
            }

            return self::ACCESS_TOKEN;
        });

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($responsesByPage): MockResponse {
            $this->requestedUrls[] = $url;
            $this->authorizations[] = current(array_filter($options['headers'] ?? [], static fn (string $header): bool => str_starts_with($header, 'Authorization:')));
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return $responsesByPage[(int) ($query['page'] ?? 1)];
        });

        $esiClient = new EsiClient($httpClient, new TagAwareAdapter(new ArrayAdapter()), $tokenManager, self::ESI_BASE_URL, new NullLogger(), new MockClock('2026-11-05T20:00:02+00:00'));

        return new MiningLedgerReader($esiClient, $tokenManager);
    }

    private function ledgerPage(array $rows, int $pages, ?string $expires): MockResponse
    {
        $headers = [sprintf('X-Pages: %d', $pages)];
        if ($expires !== null) {
            $headers[] = 'Expires: ' . $expires;
        }

        return new MockResponse(json_encode(array_map(static fn (array $row): array => [
            'date' => $row[0],
            'type_id' => $row[1],
            'solar_system_id' => $row[2],
            'quantity' => $row[3],
        ], $rows), JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => $headers]);
    }
}
