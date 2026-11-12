<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\SyncRunLog;
use App\Service\Admin\SyncTracker;
use App\Service\Mercure\MercurePublisherService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(SyncTracker::class)]
#[Group('issue-646')]
final class SyncTrackerRunHistoryTest extends TestCase
{
    private const string SCHEDULED_TYPE = 'industry';
    private const string ON_DEMAND_TYPE = 'assets';
    private const int HISTORY_SIZE = 20;

    private InMemorySyncRunLog $runLog;
    private SyncTracker $syncTracker;

    protected function setUp(): void
    {
        $this->runLog = new InMemorySyncRunLog();
        $this->syncTracker = new SyncTracker(
            new ArrayAdapter(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
            $this->runLog,
        );
    }

    public function testACompletedRunIsRecordedWithItsStartEndStatusAndMessage(): void
    {
        $this->syncTracker->start(self::SCHEDULED_TYPE);
        $this->syncTracker->complete(self::SCHEDULED_TYPE, '12 jobs synced');

        $runs = $this->runLog->all();
        self::assertCount(1, $runs);
        self::assertSame(self::SCHEDULED_TYPE, $runs[0]['type']);
        self::assertSame(SyncRunLog::STATUS_OK, $runs[0]['status']);
        self::assertSame('12 jobs synced', $runs[0]['message']);
        self::assertNotNull($runs[0]['completed_at']);
    }

    public function testAFailedRunIsRecordedWithTheErrorMessage(): void
    {
        $this->syncTracker->start(self::SCHEDULED_TYPE);
        $this->syncTracker->fail(self::SCHEDULED_TYPE, 'ESI timeout');

        $runs = $this->runLog->all();
        self::assertCount(1, $runs);
        self::assertSame(SyncRunLog::STATUS_ERROR, $runs[0]['status']);
        self::assertSame('ESI timeout', $runs[0]['message']);
    }

    public function testAStartedRunIsRecordedAsRunningUntilItEnds(): void
    {
        $this->syncTracker->start(self::SCHEDULED_TYPE);

        $runs = $this->runLog->all();
        self::assertCount(1, $runs);
        self::assertSame(SyncRunLog::STATUS_RUNNING, $runs[0]['status']);
        self::assertNull($runs[0]['completed_at']);
    }

    public function testACompletionWithoutAStartIsRecordedAsAFinishedRunStartingWhenItEnds(): void
    {
        $this->syncTracker->complete(self::SCHEDULED_TYPE);

        $runs = $this->runLog->all();
        self::assertCount(1, $runs);
        self::assertSame(SyncRunLog::STATUS_OK, $runs[0]['status']);
        self::assertEquals($runs[0]['started_at'], $runs[0]['completed_at']);
    }

    public function testACompletionEndsTheMostRecentRunningRunOfItsTypeOnly(): void
    {
        $this->runLog->seed(self::SCHEDULED_TYPE, new \DateTimeImmutable('-20 minutes'), SyncRunLog::STATUS_RUNNING);
        $this->runLog->seed(self::SCHEDULED_TYPE, new \DateTimeImmutable('-5 minutes'), SyncRunLog::STATUS_RUNNING);
        $this->runLog->seed('pve', new \DateTimeImmutable('-1 minute'), SyncRunLog::STATUS_RUNNING);

        $this->syncTracker->complete(self::SCHEDULED_TYPE);

        $statuses = array_map(static fn (array $run): string => $run['type'] . ':' . $run['status'], $this->runLog->all());
        self::assertSame(['industry:running', 'industry:ok', 'pve:running'], $statuses);
    }

    public function testTheMessageOfARunIsTruncatedToFiveHundredCharacters(): void
    {
        $this->syncTracker->start(self::SCHEDULED_TYPE);
        $this->syncTracker->fail(self::SCHEDULED_TYPE, str_repeat('x', 800));

        self::assertSame(SyncRunLog::MESSAGE_MAX_LENGTH, mb_strlen((string) $this->runLog->all()[0]['message']));
    }

    public function testTheLastTwentyRunsOfATypeAreExposedNewestFirst(): void
    {
        for ($minutesAgo = 25; $minutesAgo >= 1; --$minutesAgo) {
            $startedAt = new \DateTimeImmutable("-{$minutesAgo} minutes");
            $this->runLog->seed(self::SCHEDULED_TYPE, $startedAt, SyncRunLog::STATUS_OK, $startedAt->modify('+10 seconds'));
        }

        $runs = $this->entryFor(self::SCHEDULED_TYPE)['runs'];

        self::assertCount(self::HISTORY_SIZE, $runs);
        self::assertSame('ok', $runs[0]['status']);
        $startTimes = array_map(static fn (array $run): int => (new \DateTimeImmutable($run['started_at']))->getTimestamp(), $runs);
        $newestFirst = $startTimes;
        rsort($newestFirst);
        self::assertSame($newestFirst, $startTimes);
        self::assertEqualsWithDelta(time() - 60, $startTimes[0], 5);
    }

    public function testATypeWithoutRunExposesNoRunAndNoSuccessRate(): void
    {
        $this->runLog->seed('pve', new \DateTimeImmutable('-1 hour'), SyncRunLog::STATUS_OK, new \DateTimeImmutable('-59 minutes'));

        $entry = $this->entryFor(self::SCHEDULED_TYPE);

        self::assertSame([], $entry['runs']);
        self::assertNull($entry['success_rate_24h']);
    }

    public function testAnOnDemandTypeExposesItsRunsToo(): void
    {
        $this->syncTracker->start(self::ON_DEMAND_TYPE);
        $this->syncTracker->complete(self::ON_DEMAND_TYPE);

        self::assertCount(1, $this->entryFor(self::ON_DEMAND_TYPE)['runs']);
    }

    /** @return iterable<string, array{list<string>, ?int}> */
    public static function finishedRunsOverTheLastDay(): iterable
    {
        yield 'three ok out of four' => [['ok', 'ok', 'ok', 'error'], 75];
        yield 'two ok out of three rounds to the nearest integer' => [['ok', 'ok', 'error'], 67];
        yield 'only failures' => [['error', 'error'], 0];
        yield 'only successes' => [['ok'], 100];
        yield 'a run still running does not count' => [['running'], null];
        yield 'a run still running next to finished runs does not count' => [['ok', 'running'], 100];
        yield 'no run' => [[], null];
    }

    /** @param list<string> $statuses */
    #[DataProvider('finishedRunsOverTheLastDay')]
    public function testTheSuccessRateOverTwentyFourHoursCountsFinishedRunsOnly(array $statuses, ?int $expectedRate): void
    {
        foreach ($statuses as $position => $status) {
            $startedAt = new \DateTimeImmutable('-' . (10 + $position) . ' minutes');
            $this->runLog->seed(
                self::SCHEDULED_TYPE,
                $startedAt,
                $status,
                $status === SyncRunLog::STATUS_RUNNING ? null : $startedAt->modify('+5 seconds'),
            );
        }

        self::assertSame($expectedRate, $this->entryFor(self::SCHEDULED_TYPE)['success_rate_24h']);
    }

    public function testRunsStartedMoreThanTwentyFourHoursAgoLeaveTheSuccessRate(): void
    {
        $this->runLog->seed(self::SCHEDULED_TYPE, new \DateTimeImmutable('-25 hours'), SyncRunLog::STATUS_ERROR, new \DateTimeImmutable('-25 hours +5 seconds'));
        $this->runLog->seed(self::SCHEDULED_TYPE, new \DateTimeImmutable('-1 hour'), SyncRunLog::STATUS_OK, new \DateTimeImmutable('-1 hour +5 seconds'));

        self::assertSame(100, $this->entryFor(self::SCHEDULED_TYPE)['success_rate_24h']);
    }

    public function testARunningRunOlderThanTwiceTheIntervalIsExposedAsAStuckError(): void
    {
        $this->runLog->seed(self::SCHEDULED_TYPE, new \DateTimeImmutable('-3 hours'), SyncRunLog::STATUS_RUNNING);

        $run = $this->entryFor(self::SCHEDULED_TYPE)['runs'][0];

        self::assertSame(SyncRunLog::STATUS_ERROR, $run['status']);
        self::assertTrue($run['stuck']);
        self::assertStringStartsWith('Stuck: still running after', (string) $run['message']);
    }

    public function testARecentRunningRunIsExposedAsRunning(): void
    {
        $this->runLog->seed(self::SCHEDULED_TYPE, new \DateTimeImmutable('-1 minute'), SyncRunLog::STATUS_RUNNING);

        $run = $this->entryFor(self::SCHEDULED_TYPE)['runs'][0];

        self::assertSame(SyncRunLog::STATUS_RUNNING, $run['status']);
        self::assertFalse($run['stuck']);
    }

    /** @return array<string, mixed> */
    private function entryFor(string $type): array
    {
        foreach ($this->syncTracker->getAll() as $entry) {
            if ($entry['type'] === $type) {
                return $entry;
            }
        }

        self::fail("No health entry for {$type}");
    }
}
