<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\SyncTracker;
use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[CoversClass(SyncTracker::class)]
#[Group('issue-236')]
final class SyncTrackerLastSuccessTest extends TestCase
{
    private const string MARKET_JITA = 'market-jita';
    private const string MARKET_JITA_STATE_KEY = 'sync_tracker_market_jita';
    private const int STATE_TTL = 86400 * 7;
    private const int ONE_DAY = 86400;
    private const int SIX_DAYS = 86400 * 6;

    private const string SUCCESS_AT_TEN = '2026-10-31T10:00:00+00:00';
    private const string FAILURE_AT_HALF_PAST_TEN = '2026-10-31T10:30:00+00:00';

    private ArrayAdapter $cache;
    private MockClock $cacheClock;
    private SyncTracker $syncTracker;

    protected function setUp(): void
    {
        $this->cacheClock = new MockClock();
        $this->cache = new ArrayAdapter(clock: $this->cacheClock);
        $this->syncTracker = new SyncTracker(
            $this->cache,
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );
    }

    public function testCompleteRecordsItsCompletionTimeAsTheLastSuccessfulSync(): void
    {
        $this->syncTracker->start(self::MARKET_JITA);
        $before = \time();
        $this->syncTracker->complete(self::MARKET_JITA, '4210 prices refreshed');
        $after = \time();

        $freshness = $this->syncTracker->getFreshness(self::MARKET_JITA);

        $this->assertSame(
            ['lastSuccessAt' => $this->recordedCompletedAt(self::MARKET_JITA), 'lastRunFailed' => false],
            $freshness,
        );
        $this->assertTimestampBetween($before, $after, $freshness['lastSuccessAt']);
    }

    public function testFailureAfterASuccessKeepsTheLastSuccessfulSyncAndFlagsTheLastRunFailed(): void
    {
        $this->writeMarketJitaState([
            'started_at' => '2026-10-31T09:55:00+00:00',
            'completed_at' => self::SUCCESS_AT_TEN,
            'status' => 'ok',
            'message' => null,
            'last_success_at' => self::SUCCESS_AT_TEN,
        ]);

        $this->syncTracker->start(self::MARKET_JITA);
        $this->syncTracker->fail(self::MARKET_JITA, 'ESI 502 Bad Gateway');

        $this->assertSame(
            ['lastSuccessAt' => self::SUCCESS_AT_TEN, 'lastRunFailed' => true],
            $this->syncTracker->getFreshness(self::MARKET_JITA),
        );
    }

    public function testStartAfterAFailureKeepsTheLastSuccessfulSyncAndNoLongerFlagsTheLastRunFailed(): void
    {
        $this->writeFailedRunAfterSuccessAtTen();

        $this->syncTracker->start(self::MARKET_JITA);

        $this->assertSame(
            ['lastSuccessAt' => self::SUCCESS_AT_TEN, 'lastRunFailed' => false],
            $this->syncTracker->getFreshness(self::MARKET_JITA),
        );
    }

    public function testSuccessAfterAFailureReplacesTheLastSuccessfulSync(): void
    {
        $this->writeFailedRunAfterSuccessAtTen();

        $this->syncTracker->start(self::MARKET_JITA);
        $before = \time();
        $this->syncTracker->complete(self::MARKET_JITA);
        $after = \time();

        $freshness = $this->syncTracker->getFreshness(self::MARKET_JITA);

        $this->assertSame(
            ['lastSuccessAt' => $this->recordedCompletedAt(self::MARKET_JITA), 'lastRunFailed' => false],
            $freshness,
        );
        $this->assertNotSame(self::SUCCESS_AT_TEN, $freshness['lastSuccessAt']);
        $this->assertTimestampBetween($before, $after, $freshness['lastSuccessAt']);
    }

    public static function stateWrittenBeforeTheLastSuccessfulSyncProvider(): iterable
    {
        yield 'ok gives its completion time' => [
            ['started_at' => '2026-10-31T08:55:00+00:00', 'completed_at' => '2026-10-31T09:00:00+00:00', 'status' => 'ok', 'message' => '4210 prices refreshed'],
            ['lastSuccessAt' => '2026-10-31T09:00:00+00:00', 'lastRunFailed' => false],
        ];
        yield 'ok with a null last success gives its completion time' => [
            ['started_at' => '2026-10-31T08:55:00+00:00', 'completed_at' => '2026-10-31T09:00:00+00:00', 'status' => 'ok', 'message' => null, 'last_success_at' => null],
            ['lastSuccessAt' => '2026-10-31T09:00:00+00:00', 'lastRunFailed' => false],
        ];
        yield 'error never shows its failure time as a success' => [
            ['started_at' => '2026-10-31T08:55:00+00:00', 'completed_at' => '2026-10-31T09:00:00+00:00', 'status' => 'error', 'message' => 'ESI 502 Bad Gateway'],
            ['lastSuccessAt' => null, 'lastRunFailed' => true],
        ];
        yield 'running falls back neither on its start nor on its previous completion' => [
            ['started_at' => '2026-10-31T08:30:00+00:00', 'completed_at' => '2026-10-31T08:00:00+00:00', 'status' => 'running', 'message' => null],
            ['lastSuccessAt' => null, 'lastRunFailed' => false],
        ];
    }

    #[DataProvider('stateWrittenBeforeTheLastSuccessfulSyncProvider')]
    public function testStateWrittenBeforeTheLastSuccessfulSyncWasRecorded(array $state, array $expectedFreshness): void
    {
        $this->writeMarketJitaState($state);

        $this->assertSame($expectedFreshness, $this->syncTracker->getFreshness(self::MARKET_JITA));
    }

    public function testFirstRunAfterAnOkStateWithoutLastSuccessHasNoKnownSuccessWhileRunning(): void
    {
        $this->writeOkStateWithoutLastSuccessAtNine();

        $this->syncTracker->start(self::MARKET_JITA);

        $this->assertSame(
            ['lastSuccessAt' => null, 'lastRunFailed' => false],
            $this->syncTracker->getFreshness(self::MARKET_JITA),
        );
    }

    public function testFirstRunAfterAnOkStateWithoutLastSuccessRecordsItsCompletionOnSuccess(): void
    {
        $this->writeOkStateWithoutLastSuccessAtNine();

        $this->syncTracker->start(self::MARKET_JITA);
        $before = \time();
        $this->syncTracker->complete(self::MARKET_JITA);
        $after = \time();

        $freshness = $this->syncTracker->getFreshness(self::MARKET_JITA);

        $this->assertSame(
            ['lastSuccessAt' => $this->recordedCompletedAt(self::MARKET_JITA), 'lastRunFailed' => false],
            $freshness,
        );
        $this->assertTimestampBetween($before, $after, $freshness['lastSuccessAt']);
    }

    public function testFirstRunAfterAnOkStateWithoutLastSuccessHasNoKnownSuccessWhenItFails(): void
    {
        $this->writeOkStateWithoutLastSuccessAtNine();

        $this->syncTracker->start(self::MARKET_JITA);
        $this->syncTracker->fail(self::MARKET_JITA, 'ESI 502 Bad Gateway');

        $this->assertSame(
            ['lastSuccessAt' => null, 'lastRunFailed' => true],
            $this->syncTracker->getFreshness(self::MARKET_JITA),
        );
    }

    public function testFirstRunFailingWithoutAnyStateHasNoKnownSuccessAndFlagsTheLastRunFailed(): void
    {
        $this->syncTracker->fail(self::MARKET_JITA, 'ESI 504 Gateway Timeout');

        $this->assertSame(
            ['lastSuccessAt' => null, 'lastRunFailed' => true],
            $this->syncTracker->getFreshness(self::MARKET_JITA),
        );
    }

    public static function globalSyncProvider(): iterable
    {
        yield 'market-jita' => ['market-jita'];
        yield 'cost-indices' => ['cost-indices'];
        yield 'adjusted-prices' => ['adjusted-prices'];
    }

    #[DataProvider('globalSyncProvider')]
    public function testGlobalSyncNeverRunHasNoKnownSuccessAndNoFailedRun(string $globalSync): void
    {
        $this->assertSame(
            ['lastSuccessAt' => null, 'lastRunFailed' => false],
            $this->syncTracker->getFreshness($globalSync),
        );
    }

    public function testDailyFailuresKeepTheLastSuccessfulSyncBeyondSevenDays(): void
    {
        $this->writeMarketJitaState([
            'started_at' => '2026-10-20T09:55:00+00:00',
            'completed_at' => '2026-10-20T10:00:00+00:00',
            'status' => 'ok',
            'message' => null,
            'last_success_at' => '2026-10-20T10:00:00+00:00',
        ]);

        foreach (range(1, 11) as $day) {
            $this->cacheClock->sleep(self::ONE_DAY);
            $this->syncTracker->start(self::MARKET_JITA);
            $this->syncTracker->fail(self::MARKET_JITA, sprintf('ESI 502 Bad Gateway, day %d', $day));
        }

        $this->assertSame(
            ['lastSuccessAt' => '2026-10-20T10:00:00+00:00', 'lastRunFailed' => true],
            $this->syncTracker->getFreshness(self::MARKET_JITA),
        );
    }

    public function testFailureWrittenSixDaysAfterASuccessRenewsTheStateForSevenMoreDays(): void
    {
        $this->syncTracker->complete(self::MARKET_JITA);
        $lastSuccessAt = $this->recordedCompletedAt(self::MARKET_JITA);

        $this->cacheClock->sleep(self::SIX_DAYS);
        $this->syncTracker->fail(self::MARKET_JITA, 'ESI 502 Bad Gateway');
        $this->cacheClock->sleep(self::SIX_DAYS);

        $this->assertSame(
            ['lastSuccessAt' => $lastSuccessAt, 'lastRunFailed' => true],
            $this->syncTracker->getFreshness(self::MARKET_JITA),
        );
    }

    public function testLastSuccessfulSyncIsForgottenAfterSevenDaysWithoutAnyRun(): void
    {
        $this->syncTracker->complete(self::MARKET_JITA);

        $this->cacheClock->sleep(self::STATE_TTL + 1);

        $this->assertSame(
            ['lastSuccessAt' => null, 'lastRunFailed' => false],
            $this->syncTracker->getFreshness(self::MARKET_JITA),
        );
    }

    public function testAdminEntryOfACompletedSyncKeepsItsKeys(): void
    {
        $this->syncTracker->complete(self::MARKET_JITA, '4210 prices refreshed');

        $this->assertSame(
            ['type', 'label', 'status', 'health', 'started_at', 'completed_at', 'message', 'expected_interval', 'on_demand'],
            array_keys($this->adminEntryFor(self::MARKET_JITA)),
        );
    }

    private function writeFailedRunAfterSuccessAtTen(): void
    {
        $this->writeMarketJitaState([
            'started_at' => self::FAILURE_AT_HALF_PAST_TEN,
            'completed_at' => self::FAILURE_AT_HALF_PAST_TEN,
            'status' => 'error',
            'message' => 'ESI 502 Bad Gateway',
            'last_success_at' => self::SUCCESS_AT_TEN,
        ]);
    }

    private function writeOkStateWithoutLastSuccessAtNine(): void
    {
        $this->writeMarketJitaState([
            'started_at' => '2026-10-31T08:55:00+00:00',
            'completed_at' => '2026-10-31T09:00:00+00:00',
            'status' => 'ok',
            'message' => '4210 prices refreshed',
        ]);
    }

    private function writeMarketJitaState(array $state): void
    {
        $this->cache->get(self::MARKET_JITA_STATE_KEY, static function (ItemInterface $item) use ($state): array {
            $item->expiresAfter(self::STATE_TTL);

            return $state;
        });
    }

    private function recordedCompletedAt(string $type): string
    {
        $completedAt = $this->adminEntryFor($type)['completed_at'];
        $this->assertIsString($completedAt);

        return $completedAt;
    }

    private function adminEntryFor(string $type): array
    {
        foreach ($this->syncTracker->getAll() as $entry) {
            if ($entry['type'] === $type) {
                return $entry;
            }
        }

        $this->fail(sprintf('No entry for sync type "%s".', $type));
    }

    private function assertTimestampBetween(int $before, int $after, mixed $isoDate): void
    {
        $this->assertIsString($isoDate);
        $timestamp = (new \DateTimeImmutable($isoDate))->getTimestamp();
        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }
}
