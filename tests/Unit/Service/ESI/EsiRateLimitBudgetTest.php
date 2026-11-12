<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Service\ESI\EsiRateLimitBudget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(EsiRateLimitBudget::class)]
#[Group('issue-822')]
final class EsiRateLimitBudgetTest extends TestCase
{
    private const ASSETS_ENDPOINT = '/characters/2112000001/assets/';
    private const OTHER_ASSETS_ENDPOINT = '/characters/2112000002/assets/';
    private const SKILLS_ENDPOINT = '/characters/2112000001/skills/';
    private const MARKET_ENDPOINT = '/markets/10000002/orders/';
    private const SKILLQUEUE_ENDPOINT = '/characters/2112000001/skillqueue/';
    private const PILOT_A_SCOPE = '0198c4a0-1111-7000-8000-00000000000a';
    private const PILOT_B_SCOPE = '0198c4a0-2222-7000-8000-00000000000b';
    private const ASSETS_WINDOW_SECONDS = 900;
    private const GROUP_ASSOCIATION_TTL_SECONDS = 7 * 24 * 3600;

    private MockClock $clock;
    private EsiRateLimitBudget $rateLimitBudget;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-12 10:00:00 UTC');
        $this->rateLimitBudget = new EsiRateLimitBudget(new ArrayAdapter(clock: $this->clock), clock: $this->clock);
    }

    public function testObservationStoresTheGroupBudgetOfTheScope(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '140'));

        $bucket = $this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE);
        $this->assertNotNull($bucket);
        $this->assertSame('assets', $bucket['group']);
        $this->assertSame(150, $bucket['limit']);
        $this->assertSame(self::ASSETS_WINDOW_SECONDS, $bucket['windowSeconds']);
        $this->assertSame(140, $bucket['remaining']);
        $this->assertSame($this->clock->now()->getTimestamp(), $bucket['observedAt']);
        $this->assertNull($bucket['retryUntil']);
    }

    public function testLimitInHoursIsConvertedToSeconds(): void
    {
        $this->rateLimitBudget->record(self::MARKET_ENDPOINT, EsiRateLimitBudget::PUBLIC_SCOPE, 200, $this->headers('market', '600/1h', '580'));

        $bucket = $this->rateLimitBudget->bucketOf(self::MARKET_ENDPOINT, EsiRateLimitBudget::PUBLIC_SCOPE);
        $this->assertNotNull($bucket);
        $this->assertSame(600, $bucket['limit']);
        $this->assertSame(3600, $bucket['windowSeconds']);
    }

    /**
     * @return array<string, array{?string, ?string, ?string}>
     */
    public static function unreadableHeaders(): array
    {
        return [
            'group absent' => [null, '150/15m', '140'],
            'group empty' => ['', '150/15m', '140'],
            'limit absent' => ['assets', null, '140'],
            'limit not a ratio' => ['assets', 'abc', '140'],
            'limit without window' => ['assets', '150', '140'],
            'limit with unknown unit' => ['assets', '150/15d', '140'],
            'limit as a float' => ['assets', '1.5/15m', '140'],
            'window of zero' => ['assets', '150/0m', '140'],
            'remaining absent' => ['assets', '150/15m', null],
            'remaining not a number' => ['assets', '150/15m', 'abc'],
            'remaining as a float' => ['assets', '150/15m', '1.5'],
        ];
    }

    #[DataProvider('unreadableHeaders')]
    public function testUnreadableHeadersStoreNothingAndAssumeNothing(?string $group, ?string $limit, ?string $remaining): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers($group, $limit, $remaining));

        $this->assertNull($this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE));
        $this->assertNull($this->rateLimitBudget->groupOf(self::ASSETS_ENDPOINT));
        $this->assertSame([], $this->rateLimitBudget->snapshot());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function outOfBoundsObservations(): array
    {
        return [
            'limit of zero' => ['0/15m', '0'],
            'negative remaining' => ['150/15m', '-1'],
            'remaining above the limit' => ['150/15m', '151'],
        ];
    }

    #[DataProvider('outOfBoundsObservations')]
    public function testOutOfBoundsObservationIsIgnoredWithAWarning(string $limit, string $remaining): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $rateLimitBudget = new EsiRateLimitBudget(new ArrayAdapter(clock: $this->clock), $logger, $this->clock);

        $rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', $limit, $remaining));

        $this->assertNull($rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE));
        $this->assertNull($rateLimitBudget->groupOf(self::ASSETS_ENDPOINT));
    }

    public function testRemainingEqualToTheLimitAndZeroAreAccepted(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '150'));
        $this->rateLimitBudget->record(self::SKILLS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('skills', '50/15m', '0'));

        $full = $this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE);
        $empty = $this->rateLimitBudget->bucketOf(self::SKILLS_ENDPOINT, self::PILOT_A_SCOPE);
        $this->assertNotNull($full);
        $this->assertNotNull($empty);
        $this->assertSame(150, $full['remaining']);
        $this->assertSame(0, $empty['remaining']);
    }

    public function testFamilyOfTheRouteIsAssociatedWithTheGroupOfItsResponse(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '140'));

        $this->assertSame('assets', $this->rateLimitBudget->groupOf(self::OTHER_ASSETS_ENDPOINT));
        $this->assertSame('assets', $this->rateLimitBudget->groupOf(self::ASSETS_ENDPOINT . '?page=2'));
        $this->assertNull($this->rateLimitBudget->groupOf(self::SKILLS_ENDPOINT));
    }

    public function testResponseWithoutGroupHeaderCreatesNoBucketAndNoAssociation(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers(null, '150/15m', '140'));

        $this->assertNull($this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE));
        $this->assertNull($this->rateLimitBudget->groupOf(self::ASSETS_ENDPOINT));
    }

    public function testEachCharacterAndThePublicScopeHaveTheirOwnBucketForTheSameGroup(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '140'));
        $this->rateLimitBudget->record(self::OTHER_ASSETS_ENDPOINT, self::PILOT_B_SCOPE, 200, $this->headers('assets', '150/15m', '20'));
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, EsiRateLimitBudget::PUBLIC_SCOPE, 200, $this->headers('assets', '150/15m', '98'));

        $pilotA = $this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE);
        $pilotB = $this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_B_SCOPE);
        $public = $this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, EsiRateLimitBudget::PUBLIC_SCOPE);
        $this->assertNotNull($pilotA);
        $this->assertNotNull($pilotB);
        $this->assertNotNull($public);
        $this->assertSame(140, $pilotA['remaining']);
        $this->assertSame(20, $pilotB['remaining']);
        $this->assertSame(98, $public['remaining']);
        $this->assertNull($this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, '0198c4a0-3333-7000-8000-00000000000c'));
    }

    public function testScopeIsThePublicKeyWithoutTokenAndTheCharacterUuidWithOne(): void
    {
        $this->assertSame('public', EsiRateLimitBudget::scopeOf(null));
        $this->assertSame(self::PILOT_A_SCOPE, EsiRateLimitBudget::scopeOf($this->tokenOfCharacter(self::PILOT_A_SCOPE)));
    }

    public function testTokenWithoutCharacterHasNoScope(): void
    {
        $this->assertNull(EsiRateLimitBudget::scopeOf(new EveToken()));
    }

    public function testEstimatedRemainingGrowsAtTheAverageRateOfTheWindowUpToTheLimit(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '100'));

        $this->assertEqualsWithDelta(100.0, $this->rateLimitBudget->estimatedRemaining(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE), 0.001);

        $this->clock->sleep(30);
        $this->assertEqualsWithDelta(105.0, $this->rateLimitBudget->estimatedRemaining(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE), 0.001);

        $this->clock->sleep(570);
        $this->assertEqualsWithDelta(150.0, $this->rateLimitBudget->estimatedRemaining(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE), 0.001);
    }

    public function testEstimatedRemainingOfAnEmptyBucketNineSecondsLaterIsStillCritical(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '0'));

        $this->clock->sleep(9);

        $estimated = $this->rateLimitBudget->estimatedRemaining(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE);
        $this->assertEqualsWithDelta(1.5, $estimated, 0.001);
        $this->assertSame('critical', EsiRateLimitBudget::levelOf($estimated, 150));
    }

    public function testEstimatedRemainingIsUnknownWithoutABucket(): void
    {
        $this->assertNull($this->rateLimitBudget->estimatedRemaining(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE));

        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '100'));

        $this->assertNull($this->rateLimitBudget->estimatedRemaining(self::ASSETS_ENDPOINT, self::PILOT_B_SCOPE));
        $this->assertNull($this->rateLimitBudget->estimatedRemaining(self::SKILLS_ENDPOINT, self::PILOT_A_SCOPE));
    }

    public function testLevelsFollowTheRatioOfTheLimit(): void
    {
        $this->assertSame('critical', EsiRateLimitBudget::levelOf(7.4, 150));
        $this->assertSame('low', EsiRateLimitBudget::levelOf(7.5, 150));
        $this->assertSame('low', EsiRateLimitBudget::levelOf(29.9, 150));
        $this->assertSame('ok', EsiRateLimitBudget::levelOf(30.0, 150));
        $this->assertSame('ok', EsiRateLimitBudget::levelOf(100.0, 150));
        $this->assertSame('critical', EsiRateLimitBudget::levelOf(29.0, 600));
        $this->assertSame('low', EsiRateLimitBudget::levelOf(30.0, 600));
    }

    public function testTooManyRequestsEmptiesTheBucketUntilRetryAfter(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '140'));
        $limitedAt = $this->clock->now()->getTimestamp();

        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 429, $this->headers('assets', '150/15m', '30', '120'));

        $bucket = $this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE);
        $this->assertNotNull($bucket);
        $this->assertSame(0, $bucket['remaining']);
        $this->assertSame($limitedAt + 120, $bucket['retryUntil']);

        $this->clock->sleep(60);
        $this->assertEqualsWithDelta(0.0, $this->rateLimitBudget->estimatedRemaining(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE), 0.001);

        $this->clock->sleep(60);
        $this->assertEqualsWithDelta(20.0, $this->rateLimitBudget->estimatedRemaining(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE), 0.001);
    }

    public function testTooManyRequestsWithoutGroupHeaderTouchesNoBucket(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '140'));

        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 429, $this->headers(null, null, null, '120'));

        $bucket = $this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE);
        $this->assertNotNull($bucket);
        $this->assertSame(140, $bucket['remaining']);
        $this->assertNull($bucket['retryUntil']);
    }

    public function testBucketExpiresWithItsWindowAndTheGroupAssociationAfterSevenDays(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '140'));

        $this->clock->sleep(self::ASSETS_WINDOW_SECONDS - 1);
        $this->assertNotNull($this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE));

        $this->clock->sleep(2);
        $this->assertNull($this->rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE));
        $this->assertSame('assets', $this->rateLimitBudget->groupOf(self::ASSETS_ENDPOINT));

        $this->clock->sleep(self::GROUP_ASSOCIATION_TTL_SECONDS);
        $this->assertNull($this->rateLimitBudget->groupOf(self::ASSETS_ENDPOINT));
    }

    public function testSnapshotListsTheLowestRemainingOfEachGroupSortedByRatioOfTheLimit(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '20'));
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '5'));
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '90'));
        $this->rateLimitBudget->record(self::MARKET_ENDPOINT, EsiRateLimitBudget::PUBLIC_SCOPE, 200, $this->headers('market', '300/15m', '30'));
        $this->rateLimitBudget->record(self::SKILLQUEUE_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('skillqueue', '600/1h', '580'));
        $this->rateLimitBudget->record(self::SKILLS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('skills', '50/15m', '9'));

        $snapshot = $this->rateLimitBudget->snapshot();

        $this->assertSame(['assets', 'market', 'skills', 'skillqueue'], array_column($snapshot, 'group'));
        $this->assertSame('2026-11-12T10:00:00+00:00', $snapshot[0]['observedAt']);
        $this->assertSame(5, $snapshot[0]['remaining']);
        $this->assertSame(150, $snapshot[0]['limit']);
        $this->assertSame(900, $snapshot[0]['windowSeconds']);
        $this->assertSame('critical', $snapshot[0]['level']);
        $this->assertSame(30, $snapshot[1]['remaining']);
        $this->assertSame(300, $snapshot[1]['limit']);
        $this->assertSame('low', $snapshot[1]['level']);
        $this->assertSame(9, $snapshot[2]['remaining']);
        $this->assertSame('low', $snapshot[2]['level']);
        $this->assertSame(580, $snapshot[3]['remaining']);
        $this->assertSame(3600, $snapshot[3]['windowSeconds']);
        $this->assertSame('ok', $snapshot[3]['level']);
    }

    public function testSnapshotHoldsOneEntryPerGroupWhateverTheScopeWithoutExposingIt(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '40'));
        $this->rateLimitBudget->record(self::OTHER_ASSETS_ENDPOINT, self::PILOT_B_SCOPE, 200, $this->headers('assets', '150/15m', '12'));

        $snapshot = $this->rateLimitBudget->snapshot();

        $this->assertCount(1, $snapshot);
        $this->assertSame(12, $snapshot[0]['remaining']);
        $this->assertSame(['group', 'remaining', 'limit', 'windowSeconds', 'observedAt', 'level'], array_keys($snapshot[0]));
        $this->assertStringNotContainsString(self::PILOT_B_SCOPE, json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function testSnapshotLowestIsReplacedByAnEqualObservationAndKeptAgainstAHigherOne(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '5'));
        $this->clock->sleep(60);
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '5'));
        $this->clock->sleep(60);
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '70'));

        $snapshot = $this->rateLimitBudget->snapshot();

        $this->assertCount(1, $snapshot);
        $this->assertSame(5, $snapshot[0]['remaining']);
        $this->assertSame('2026-11-12T10:01:00+00:00', $snapshot[0]['observedAt']);
    }

    public function testSnapshotLowestIsReplacedOnceItsWindowHasElapsed(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '5'));
        $this->clock->sleep(self::ASSETS_WINDOW_SECONDS + 1);

        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '80'));

        $snapshot = $this->rateLimitBudget->snapshot();
        $this->assertCount(1, $snapshot);
        $this->assertSame(80, $snapshot[0]['remaining']);
        $this->assertSame('ok', $snapshot[0]['level']);
    }

    public function testSnapshotLevelIsUnknownOnceTheObservationIsOlderThanItsWindow(): void
    {
        $this->rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '20'));

        $this->clock->sleep(self::ASSETS_WINDOW_SECONDS);
        $withinWindow = $this->rateLimitBudget->snapshot();
        $this->assertCount(1, $withinWindow);
        $this->assertSame('low', $withinWindow[0]['level']);

        $this->clock->sleep(1);
        $snapshot = $this->rateLimitBudget->snapshot();
        $this->assertCount(1, $snapshot);
        $this->assertSame(20, $snapshot[0]['remaining']);
        $this->assertSame('unknown', $snapshot[0]['level']);
    }

    public function testSnapshotIsEmptyBeforeAnyObservation(): void
    {
        $this->assertSame([], $this->rateLimitBudget->snapshot());
    }

    public function testUnavailableCacheNeverRaisesAndNeverInventsABudget(): void
    {
        $failure = new \RuntimeException('redis down');
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException($failure);
        $pool->method('getItems')->willThrowException($failure);
        $pool->method('hasItem')->willThrowException($failure);
        $pool->method('save')->willThrowException($failure);
        $pool->method('saveDeferred')->willThrowException($failure);
        $pool->method('commit')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $rateLimitBudget = new EsiRateLimitBudget($pool, $logger, $this->clock);

        $rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 200, $this->headers('assets', '150/15m', '3'));
        $rateLimitBudget->record(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE, 429, $this->headers('assets', '150/15m', '0', '120'));

        $this->assertNull($rateLimitBudget->groupOf(self::ASSETS_ENDPOINT));
        $this->assertNull($rateLimitBudget->bucketOf(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE));
        $this->assertNull($rateLimitBudget->estimatedRemaining(self::ASSETS_ENDPOINT, self::PILOT_A_SCOPE));
        $this->assertSame([], $rateLimitBudget->snapshot());
    }

    /**
     * @return array<string, list<string>>
     */
    private function headers(?string $group, ?string $limit, ?string $remaining, ?string $retryAfter = null): array
    {
        $headers = [];
        $values = [
            'x-ratelimit-group' => $group,
            'x-ratelimit-limit' => $limit,
            'x-ratelimit-remaining' => $remaining,
            'retry-after' => $retryAfter,
        ];
        foreach ($values as $name => $value) {
            if ($value !== null) {
                $headers[$name] = [$value];
            }
        }

        return $headers;
    }

    private function tokenOfCharacter(string $characterUuid): EveToken
    {
        $character = new Character();
        (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::fromString($characterUuid));

        return (new EveToken())->setCharacter($character);
    }
}
