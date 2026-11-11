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
use Symfony\Component\Mercure\HubInterface;

#[CoversClass(SyncTracker::class)]
class SyncTrackerStuckRunningTest extends TestCase
{
    private const INDUSTRY_STUCK_AFTER = 3600;
    private const ON_DEMAND_STUCK_AFTER = 3600;

    private SyncTracker $syncTracker;

    protected function setUp(): void
    {
        $this->syncTracker = new SyncTracker(
            new ArrayAdapter(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );
    }

    protected function tearDown(): void
    {
        ClockMock::withClockMock(false);
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function runningAgeProvider(): iterable
    {
        yield 'just started' => [0, 'running', 'running'];
        yield 'exactly twice the interval' => [3600, 'running', 'running'];
        yield 'just over twice the interval' => [3601, 'error', 'stale'];
        yield 'three intervals' => [5400, 'error', 'stale'];
    }

    #[DataProvider('runningAgeProvider')]
    public function testScheduledSyncRunningLongerThanTwiceItsIntervalIsReportedStuck(
        int $secondsSinceStart,
        string $expectedStatus,
        string $expectedHealth,
    ): void {
        $this->syncTracker->start('industry');
        ClockMock::withClockMock($this->startedAtOf('industry') + $secondsSinceStart);

        $entry = $this->entryFor('industry');

        $this->assertSame($expectedStatus, $entry['status']);
        $this->assertSame($expectedHealth, $entry['health']);
    }

    public function testStuckSyncExplainsItselfAndKeepsItsStartTime(): void
    {
        $this->syncTracker->start('industry');
        $startedAt = $this->entryFor('industry')['started_at'];
        ClockMock::withClockMock($this->startedAtOf('industry') + self::INDUSTRY_STUCK_AFTER + 1);

        $entry = $this->entryFor('industry');

        $this->assertSame('Stuck: still running after 60 min without completion', $entry['message']);
        $this->assertSame($startedAt, $entry['started_at']);
    }

    public function testRunningSyncWithinItsLimitHasNoMessage(): void
    {
        $this->syncTracker->start('industry');
        ClockMock::withClockMock($this->startedAtOf('industry') + 60);

        $this->assertNull($this->entryFor('industry')['message']);
    }

    public function testPublicContractsRunningForThreeIntervalsIsNotReportedRunning(): void
    {
        $this->syncTracker->start('public-contracts');
        ClockMock::withClockMock($this->startedAtOf('public-contracts') + 3 * 1800);

        $entry = $this->entryFor('public-contracts');

        $this->assertNotSame('running', $entry['health']);
        $this->assertNotSame('running', $entry['status']);
    }

    public function testStuckOnDemandSyncKeepsOnDemandHealthButIsReportedInError(): void
    {
        $this->syncTracker->start('assets');
        ClockMock::withClockMock($this->startedAtOf('assets') + self::ON_DEMAND_STUCK_AFTER + 1);

        $entry = $this->entryFor('assets');

        $this->assertSame('error', $entry['status']);
        $this->assertSame('on-demand', $entry['health']);
        $this->assertSame('Stuck: still running after 60 min without completion', $entry['message']);
    }

    public function testOnDemandSyncRunningWithinItsLimitStaysRunning(): void
    {
        $this->syncTracker->start('assets');
        ClockMock::withClockMock($this->startedAtOf('assets') + self::ON_DEMAND_STUCK_AFTER);

        $entry = $this->entryFor('assets');

        $this->assertSame('running', $entry['status']);
        $this->assertSame('on-demand', $entry['health']);
    }

    public function testStuckSyncRecoversWhenItRunsAgain(): void
    {
        $this->syncTracker->start('industry');
        ClockMock::withClockMock($this->startedAtOf('industry') + self::INDUSTRY_STUCK_AFTER + 1);
        $this->assertSame('error', $this->entryFor('industry')['status']);

        ClockMock::withClockMock(false);
        $this->syncTracker->start('industry');
        $this->assertSame('running', $this->entryFor('industry')['status']);

        $this->syncTracker->complete('industry', 'done');
        $entry = $this->entryFor('industry');

        $this->assertSame('ok', $entry['status']);
        $this->assertSame('healthy', $entry['health']);
        $this->assertSame('done', $entry['message']);
    }

    public function testStuckSyncIsNotCountedAsALastRunFailure(): void
    {
        $this->syncTracker->start('industry');
        ClockMock::withClockMock($this->startedAtOf('industry') + self::INDUSTRY_STUCK_AFTER + 1);

        $this->assertFalse($this->syncTracker->getFreshness('industry')['lastRunFailed']);
    }

    private function startedAtOf(string $type): int
    {
        return (new \DateTimeImmutable($this->entryFor($type)['started_at']))->getTimestamp();
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
}
