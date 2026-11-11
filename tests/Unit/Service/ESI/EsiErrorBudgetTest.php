<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ESI;

use App\Service\ESI\EsiErrorBudget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(EsiErrorBudget::class)]
class EsiErrorBudgetTest extends TestCase
{
    private const START = '2026-11-11 10:30:00';
    private const FAMILY_ASSETS = '/characters/{id}/assets/';
    private const HOURS_IN_WINDOW = 24;

    private MockClock $clock;
    private ArrayAdapter $pool;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::START . ' UTC');
        $this->pool = new ArrayAdapter();
    }

    public function testSnapshotWithoutObservationIsUnknown(): void
    {
        $snapshot = $this->budget()->snapshot();

        $this->assertNull($snapshot['remain']);
        $this->assertNull($snapshot['resetSeconds']);
        $this->assertNull($snapshot['observedAt']);
        $this->assertNull($snapshot['windowEndsAt']);
        $this->assertSame('unknown', $snapshot['level']);
        $this->assertSame(0, $snapshot['limited420']);
        $this->assertSame(0, $snapshot['limited429']);
        $this->assertSame([], $snapshot['byFamily']);
        $this->assertCount(self::HOURS_IN_WINDOW, $snapshot['hourly']);
    }

    public function testObservationIsKeptWithItsTimestampAndWindowEnd(): void
    {
        $this->budget()->observe(73, 40);

        $snapshot = $this->budget()->snapshot();

        $this->assertSame(73, $snapshot['remain']);
        $this->assertSame(40, $snapshot['resetSeconds']);
        $this->assertSame('2026-11-11T10:30:00+00:00', $snapshot['observedAt']);
        $this->assertSame('2026-11-11T10:30:40+00:00', $snapshot['windowEndsAt']);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function remainLevels(): iterable
    {
        yield 'full budget' => [100, 'ok'];
        yield 'twenty remain' => [20, 'ok'];
        yield 'nineteen remain' => [19, 'low'];
        yield 'five remain' => [5, 'low'];
        yield 'four remain' => [4, 'critical'];
        yield 'none remain' => [0, 'critical'];
    }

    #[DataProvider('remainLevels')]
    public function testLevelFollowsTheRemainingBudget(int $remain, string $expectedLevel): void
    {
        $this->budget()->observe($remain, 60);

        $this->assertSame($expectedLevel, $this->budget()->snapshot()['level']);
    }

    public function testLevelIsUnknownOnceTheResetWindowHasElapsed(): void
    {
        $this->budget()->observe(3, 60);
        $this->clock->sleep(61);

        $snapshot = $this->budget()->snapshot();

        $this->assertSame('unknown', $snapshot['level']);
        $this->assertSame(3, $snapshot['remain']);
    }

    public function testObservationIsSharedBetweenProcessesUsingTheSamePool(): void
    {
        $this->budget()->observe(12, 60);

        $otherProcess = $this->budget();

        $this->assertSame(12, $otherProcess->snapshot()['remain']);
    }

    public function testSameRemainWithinThirtySecondsIsNotWrittenAgain(): void
    {
        $budget = $this->budget();
        $budget->observe(50, 60);
        $this->clock->sleep(10);
        $budget->observe(50, 60);

        $this->assertSame('2026-11-11T10:30:00+00:00', $budget->snapshot()['observedAt']);
    }

    public function testSameRemainAfterThirtySecondsIsWrittenAgain(): void
    {
        $budget = $this->budget();
        $budget->observe(50, 60);
        $this->clock->sleep(31);
        $budget->observe(50, 60);

        $this->assertSame('2026-11-11T10:30:31+00:00', $budget->snapshot()['observedAt']);
    }

    public function testChangedRemainIsWrittenAtOnce(): void
    {
        $budget = $this->budget();
        $budget->observe(50, 60);
        $this->clock->sleep(1);
        $budget->observe(49, 59);

        $snapshot = $budget->snapshot();

        $this->assertSame(49, $snapshot['remain']);
        $this->assertSame('2026-11-11T10:30:01+00:00', $snapshot['observedAt']);
    }

    public function testLimitedResponsesAreCountedPerStatusAndEndpointFamily(): void
    {
        $budget = $this->budget();
        $budget->recordLimitedResponse(420, '/characters/123/assets/?page=2');
        $budget->recordLimitedResponse(429, '/characters/456/assets/');
        $budget->recordLimitedResponse(420, '/characters/456/assets/');
        $budget->recordLimitedResponse(420, '/universe/types/34/');

        $snapshot = $budget->snapshot();

        $this->assertSame(3, $snapshot['limited420']);
        $this->assertSame(1, $snapshot['limited429']);
        $this->assertSame(
            [
                ['family' => self::FAMILY_ASSETS, 'limited420' => 2, 'limited429' => 1],
                ['family' => '/universe/types/{id}/', 'limited420' => 1, 'limited429' => 0],
            ],
            $snapshot['byFamily'],
        );
    }

    public function testLimitedResponsesAreBucketedByUtcHour(): void
    {
        $budget = $this->budget();
        $budget->recordLimitedResponse(420, '/status/');
        $this->clock->sleep(3600);
        $budget->recordLimitedResponse(429, '/status/');

        $hourly = $budget->snapshot()['hourly'];

        $this->assertCount(self::HOURS_IN_WINDOW, $hourly);
        $this->assertSame('2026-11-11T11:00:00+00:00', $hourly[self::HOURS_IN_WINDOW - 1]['hour']);
        $this->assertSame(['limited420' => 0, 'limited429' => 1], $this->countsOf($hourly[self::HOURS_IN_WINDOW - 1]));
        $this->assertSame(['limited420' => 1, 'limited429' => 0], $this->countsOf($hourly[self::HOURS_IN_WINDOW - 2]));
        $this->assertSame('2026-11-10T12:00:00+00:00', $hourly[0]['hour']);
    }

    public function testResponseLimitedTwentyThreeHoursAgoIsStillCounted(): void
    {
        $budget = $this->budget();
        $budget->recordLimitedResponse(420, '/status/');
        $this->clock->sleep(23 * 3600);

        $this->assertSame(1, $budget->snapshot()['limited420']);
    }

    public function testResponseLimitedTwentyFiveHoursAgoIsOutsideTheWindow(): void
    {
        $budget = $this->budget();
        $budget->recordLimitedResponse(420, '/status/');
        $this->clock->sleep(25 * 3600);

        $snapshot = $budget->snapshot();

        $this->assertSame(0, $snapshot['limited420']);
        $this->assertSame([], $snapshot['byFamily']);
    }

    public function testCacheFailureNeverBreaksTheCaller(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new \RuntimeException('redis down'));
        $budget = new EsiErrorBudget($pool, $logger, $this->clock);

        $budget->observe(10, 60);
        $budget->recordLimitedResponse(420, '/status/');

        $this->assertCount(2, $logger->warnings);
    }

    private function budget(): EsiErrorBudget
    {
        return new EsiErrorBudget($this->pool, clock: $this->clock);
    }

    /**
     * @param array{hour: string, limited420: int, limited429: int} $hour
     * @return array{limited420: int, limited429: int}
     */
    private function countsOf(array $hour): array
    {
        return ['limited420' => $hour['limited420'], 'limited429' => $hour['limited429']];
    }
}
