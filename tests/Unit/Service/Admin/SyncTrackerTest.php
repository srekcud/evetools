<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\SyncTracker;
use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Issues #40 / #31 : la santé du scheduler affichée dans l'admin doit refléter
 * les intervalles réels du schedule, et les syncs à la demande ne sont jamais en retard.
 */
#[CoversClass(SyncTracker::class)]
class SyncTrackerTest extends TestCase
{
    private const INDUSTRY_INTERVAL = 1800;

    private const STATE_TTL = 86400 * 7;
    private const TRIGGERED_BY_TTL = 3600;

    private SyncTracker $syncTracker;

    /** Horloge du cache uniquement : pilote l'expiration des entrées. */
    private MockClock $cacheClock;

    /** @var list<Update> */
    private array $publishedUpdates = [];

    public static function setUpBeforeClass(): void
    {
        // computeHealth() utilise time() : on le fige via ClockMock (namespace App\Service\Admin).
        ClockMock::register(SyncTracker::class);
    }

    protected function setUp(): void
    {
        $this->publishedUpdates = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedUpdates[] = $update;

            return 'id';
        });

        $this->cacheClock = new MockClock();
        $this->syncTracker = new SyncTracker(
            new ArrayAdapter(clock: $this->cacheClock),
            new MercurePublisherService($hub, new NullLogger()),
        );
    }

    protected function tearDown(): void
    {
        ClockMock::withClockMock(false);
    }

    public function testGetAllListsScheduledSyncsThenOnDemandSyncsWithLabelsAndExpectedIntervals(): void
    {
        $entries = $this->syncTracker->getAll();

        $this->assertSame(
            [
                ['industry', 'Jobs industrie', 1800, false],
                ['pve', 'PVE', 3600, false],
                ['wallet', 'Wallet', 3600, false],
                ['mining', 'Mining', 3600, false],
                ['ansiblex', 'Ansiblex', 43200, false],
                ['planetary', 'Planetary', 1800, false],
                ['market-jita', 'Market Jita', 3600, false],
                ['market-structure', 'Market Structure', 3600, false],
                ['alert-prices', 'Alert Price Refresh', 1800, false],
                ['adjusted-prices', 'Adjusted Prices', 86400, false],
                ['cost-indices', 'Cost Indices', 7200, false],
                ['public-contracts', 'Public Contracts', 1800, false],
                ['assets', 'Assets', null, true],
                ['market-alerts', 'Market Alerts', null, true],
            ],
            array_map(
                static fn (array $entry): array => [$entry['type'], $entry['label'], $entry['expected_interval'], $entry['on_demand']],
                $entries,
            ),
        );
    }

    public function testNeverRunScheduledSyncIsUnknown(): void
    {
        $this->assertSame(
            [
                'type' => 'industry',
                'label' => 'Jobs industrie',
                'status' => 'unknown',
                'health' => 'unknown',
                'started_at' => null,
                'completed_at' => null,
                'message' => null,
                'expected_interval' => self::INDUSTRY_INTERVAL,
                'on_demand' => false,
            ],
            $this->entryFor('industry'),
        );
    }

    public function testNeverRunOnDemandSyncIsOnDemand(): void
    {
        $this->assertSame(
            [
                'type' => 'assets',
                'label' => 'Assets',
                'status' => 'unknown',
                'health' => 'on-demand',
                'started_at' => null,
                'completed_at' => null,
                'message' => null,
                'expected_interval' => null,
                'on_demand' => true,
            ],
            $this->entryFor('assets'),
        );
    }

    public function testStartMarksSyncAsRunning(): void
    {
        $before = \time();
        $this->syncTracker->start('industry');
        $after = \time();

        $entry = $this->entryFor('industry');

        $this->assertSame('running', $entry['status']);
        $this->assertSame('running', $entry['health']);
        $this->assertNull($entry['completed_at']);
        $this->assertNull($entry['message']);
        $this->assertTimestampBetween($before, $after, $entry['started_at']);
    }

    public function testCompleteStoresOkStatusMessageAndKeepsStartTime(): void
    {
        $this->syncTracker->start('industry');
        $startedAt = $this->entryFor('industry')['started_at'];

        $before = \time();
        $this->syncTracker->complete('industry', '42 jobs synced');
        $after = \time();

        $entry = $this->entryFor('industry');

        $this->assertSame('ok', $entry['status']);
        $this->assertSame('42 jobs synced', $entry['message']);
        $this->assertSame($startedAt, $entry['started_at']);
        $this->assertTimestampBetween($before, $after, $entry['completed_at']);
    }

    public function testCompleteWithoutStartUsesCompletionTimeAsStartTime(): void
    {
        $this->syncTracker->complete('industry');

        $entry = $this->entryFor('industry');

        $this->assertSame('ok', $entry['status']);
        $this->assertNull($entry['message']);
        $this->assertNotNull($entry['completed_at']);
        $this->assertSame($entry['completed_at'], $entry['started_at']);
    }

    public function testFailStoresErrorStatusAndMessageAndKeepsStartTime(): void
    {
        $this->syncTracker->start('industry');
        $startedAt = $this->entryFor('industry')['started_at'];

        $before = \time();
        $this->syncTracker->fail('industry', 'ESI 502');
        $after = \time();

        $entry = $this->entryFor('industry');

        $this->assertSame('error', $entry['status']);
        $this->assertSame('ESI 502', $entry['message']);
        $this->assertSame($startedAt, $entry['started_at']);
        $this->assertTimestampBetween($before, $after, $entry['completed_at']);
    }

    public function testFailWithoutStartUsesFailureTimeAsStartTime(): void
    {
        $this->syncTracker->fail('industry', 'ESI 502');

        $entry = $this->entryFor('industry');

        $this->assertNotNull($entry['completed_at']);
        $this->assertSame($entry['completed_at'], $entry['started_at']);
    }

    public function testRestartingSyncKeepsPreviousCompletionTime(): void
    {
        $this->syncTracker->complete('industry');
        $completedAt = $this->entryFor('industry')['completed_at'];

        $this->syncTracker->start('industry');

        $this->assertSame($completedAt, $this->entryFor('industry')['completed_at']);
    }

    /** @return iterable<string, array{int, string}> */
    public static function lastRunAgeProvider(): iterable
    {
        // industry : intervalle 1800 s -> healthy <= 2700 s, late <= 5400 s, stale au-delà.
        yield 'just completed' => [0, 'healthy'];
        yield 'exactly 1.5x interval' => [2700, 'healthy'];
        yield 'just over 1.5x interval' => [2701, 'late'];
        yield 'exactly 3x interval' => [5400, 'late'];
        yield 'just over 3x interval' => [5401, 'stale'];
    }

    #[DataProvider('lastRunAgeProvider')]
    public function testScheduledSyncHealthDependsOnLastRunAge(int $secondsSinceCompletion, string $expectedHealth): void
    {
        $this->syncTracker->complete('industry');
        $completedAt = (new \DateTimeImmutable($this->entryFor('industry')['completed_at']))->getTimestamp();

        ClockMock::withClockMock($completedAt + $secondsSinceCompletion);

        $this->assertSame($expectedHealth, $this->entryFor('industry')['health']);
    }

    public function testFailedSyncHealthIsAlsoComputedFromItsAge(): void
    {
        $this->syncTracker->fail('industry', 'ESI 502');
        $completedAt = (new \DateTimeImmutable($this->entryFor('industry')['completed_at']))->getTimestamp();

        ClockMock::withClockMock($completedAt + 5401);

        $this->assertSame('stale', $this->entryFor('industry')['health']);
    }

    public function testOnDemandSyncIsNeverLateOrStaleEvenIfVeryOld(): void
    {
        $this->syncTracker->complete('assets', 'done');
        $completedAt = (new \DateTimeImmutable($this->entryFor('assets')['completed_at']))->getTimestamp();

        ClockMock::withClockMock($completedAt + 86400 * 365);

        $entry = $this->entryFor('assets');

        $this->assertSame('ok', $entry['status']);
        $this->assertSame('on-demand', $entry['health']);
        $this->assertSame('done', $entry['message']);
        $this->assertNull($entry['expected_interval']);
        $this->assertTrue($entry['on_demand']);
    }

    public function testCompletingManuallyTriggeredSyncNotifiesTheTriggeringAdminOnce(): void
    {
        $this->syncTracker->setTriggeredBy('market-jita', 'admin-uuid');

        $this->syncTracker->complete('market-jita', 'done');
        $this->syncTracker->complete('market-jita', 'done again');

        $this->assertCount(1, $this->publishedUpdates);
        $this->assertSame(['/user/admin-uuid/sync/admin-sync'], $this->publishedUpdates[0]->getTopics());

        $payload = json_decode($this->publishedUpdates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('completed', $payload['status']);
        $this->assertSame(100, $payload['progress']);
        $this->assertSame('done', $payload['message']);
        $this->assertSame(['syncType' => 'market-jita'], $payload['data']);
    }

    public function testStartingManuallyTriggeredSyncNotifiesWithoutProgress(): void
    {
        $this->syncTracker->setTriggeredBy('pve', 'admin-uuid');

        $this->syncTracker->start('pve');

        $this->assertCount(1, $this->publishedUpdates);
        $payload = json_decode($this->publishedUpdates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('started', $payload['status']);
        $this->assertNull($payload['progress']);
        $this->assertSame('Sync in progress...', $payload['message']);
    }

    public function testFailingManuallyTriggeredSyncNotifiesError(): void
    {
        $this->syncTracker->setTriggeredBy('pve', 'admin-uuid');

        $this->syncTracker->fail('pve', 'ESI 502');

        $this->assertCount(1, $this->publishedUpdates);
        $payload = json_decode($this->publishedUpdates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('error', $payload['status']);
        $this->assertNull($payload['progress']);
        $this->assertSame('ESI 502', $payload['message']);
    }

    /**
     * Issue #80: a handler always calls start() before complete(). The admin who clicked
     * must receive both, otherwise the admin page spinner never stops.
     */
    public function testManuallyTriggeredSyncNotifiesTheTriggeringAdminOfStartThenCompletion(): void
    {
        $this->syncTracker->setTriggeredBy('public-contracts', 'admin-uuid');

        $this->syncTracker->start('public-contracts');
        $this->syncTracker->complete('public-contracts', '3 types indexed');

        $this->assertSame([
            ['/user/admin-uuid/sync/admin-sync', 'started', 'Sync in progress...', ['syncType' => 'public-contracts']],
            ['/user/admin-uuid/sync/admin-sync', 'completed', '3 types indexed', ['syncType' => 'public-contracts']],
        ], $this->publishedAdminSyncUpdates());
    }

    /**
     * Issue #80: same lifecycle when the sync fails after it started.
     */
    public function testManuallyTriggeredSyncNotifiesTheTriggeringAdminOfStartThenError(): void
    {
        $this->syncTracker->setTriggeredBy('public-contracts', 'admin-uuid');

        $this->syncTracker->start('public-contracts');
        $this->syncTracker->fail('public-contracts', 'ESI 502');

        $this->assertSame([
            ['/user/admin-uuid/sync/admin-sync', 'started', 'Sync in progress...', ['syncType' => 'public-contracts']],
            ['/user/admin-uuid/sync/admin-sync', 'error', 'ESI 502', ['syncType' => 'public-contracts']],
        ], $this->publishedAdminSyncUpdates());
    }

    /**
     * @return list<array{string, string, ?string, mixed}> topic, status, message, data
     */
    private function publishedAdminSyncUpdates(): array
    {
        return array_map(static function (Update $update): array {
            $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);

            return [
                implode(',', $update->getTopics()),
                $payload['status'],
                $payload['message'],
                $payload['data'],
            ];
        }, $this->publishedUpdates);
    }

    public function testLatestTriggeringAdminIsTheOneNotified(): void
    {
        $this->syncTracker->setTriggeredBy('pve', 'first-admin');
        $this->syncTracker->setTriggeredBy('pve', 'second-admin');

        $this->syncTracker->complete('pve');

        $this->assertCount(1, $this->publishedUpdates);
        $this->assertSame(['/user/second-admin/sync/admin-sync'], $this->publishedUpdates[0]->getTopics());
    }

    public function testTriggeringAdminIsStillNotifiedJustBeforeOneHour(): void
    {
        $this->syncTracker->setTriggeredBy('pve', 'admin-uuid');
        $this->cacheClock->sleep(self::TRIGGERED_BY_TTL - 1);

        $this->syncTracker->complete('pve');

        $this->assertCount(1, $this->publishedUpdates);
    }

    public function testTriggeringAdminIsForgottenAfterOneHour(): void
    {
        $this->syncTracker->setTriggeredBy('pve', 'admin-uuid');
        $this->cacheClock->sleep(self::TRIGGERED_BY_TTL + 1);

        $this->syncTracker->complete('pve');

        $this->assertSame([], $this->publishedUpdates);
    }

    public function testSyncStateIsKeptForSevenDays(): void
    {
        $this->syncTracker->complete('market-jita', 'done');
        $this->cacheClock->sleep(self::STATE_TTL - 1);

        $entry = $this->entryFor('market-jita');

        $this->assertSame('ok', $entry['status']);
        $this->assertSame('done', $entry['message']);
    }

    public function testSyncStateExpiresAfterSevenDays(): void
    {
        $this->syncTracker->complete('market-jita', 'done');
        $this->cacheClock->sleep(self::STATE_TTL + 1);

        $entry = $this->entryFor('market-jita');

        $this->assertSame('unknown', $entry['status']);
        $this->assertNull($entry['completed_at']);
    }

    public function testStateOfDashedSyncTypeIsReadBack(): void
    {
        $this->syncTracker->fail('market-structure', 'Structure unreachable');

        $entry = $this->entryFor('market-structure');

        $this->assertSame('error', $entry['status']);
        $this->assertSame('Structure unreachable', $entry['message']);
    }

    public function testScheduledSyncWithoutTriggeringAdminPublishesNothing(): void
    {
        $this->syncTracker->start('pve');
        $this->syncTracker->complete('pve');

        $this->assertSame([], $this->publishedUpdates);
    }

    /** @return array<string, mixed> */
    private function entryFor(string $type): array
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
