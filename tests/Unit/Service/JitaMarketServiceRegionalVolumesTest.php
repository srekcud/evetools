<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\JitaMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(JitaMarketService::class)]
#[Group('issue-419')]
final class JitaMarketServiceRegionalVolumesTest extends TestCase
{
    use SimulatesJitaMarketEsi;

    private const int HEIMATAR = 10000030;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int FORTY_SEVEN_HOURS = 47 * 3600;
    private const int FORTY_NINE_HOURS = 49 * 3600;

    private MockClock $clock;
    private ArrayAdapter $marketCache;

    protected function setUp(): void
    {
        $this->requestedEsiUrls = [];
        $this->clock = new MockClock();
        $this->marketCache = new ArrayAdapter(clock: $this->clock);
    }

    public function testSyncStoresTheThirtyDayAverageUnderTheRegionKeyAndReportsTheCounts(): void
    {
        $service = $this->serviceWithHistories([
            self::TRITANIUM => $this->thirtyTwoDaysOfHistory(),
            self::PYERITE => [],
        ]);

        $result = $service->syncRegionalDailyVolumes(self::HEIMATAR, [self::TRITANIUM, self::PYERITE]);

        $this->assertSame(['synced' => 2, 'failed' => 0, 'aborted' => false], $result);
        $this->assertSame(155.03, $this->marketCache->getItem('volume_10000030_34')->get());
        $this->assertSame(0.0, $this->marketCache->getItem('volume_10000030_35')->get());
        $this->assertFalse($this->marketCache->getItem('jita_volume_34')->isHit());
    }

    public function testSyncKeepsTheVolumesForFortyEightHours(): void
    {
        $service = $this->serviceWithHistories([self::TRITANIUM => $this->thirtyTwoDaysOfHistory()]);

        $service->syncRegionalDailyVolumes(self::HEIMATAR, [self::TRITANIUM]);

        $this->clock->sleep(self::FORTY_SEVEN_HOURS);
        $this->assertSame([self::TRITANIUM => 155.03], $service->getCachedDailyVolumesForRegion(self::HEIMATAR, [self::TRITANIUM]));
        $this->clock->sleep(self::FORTY_NINE_HOURS - self::FORTY_SEVEN_HOURS);
        $this->assertSame([], $service->getCachedDailyVolumesForRegion(self::HEIMATAR, [self::TRITANIUM]));
    }

    public function testATypeWhoseHistoryFailsIsCountedAndLeftUnknown(): void
    {
        $service = $this->serviceWithHistories([self::TRITANIUM => $this->thirtyTwoDaysOfHistory()]);

        $result = $service->syncRegionalDailyVolumes(self::HEIMATAR, [self::TRITANIUM, self::MEXALLON]);

        $this->assertSame(['synced' => 1, 'failed' => 1, 'aborted' => false], $result);
        $this->assertFalse($this->marketCache->getItem('volume_10000030_36')->isHit());
    }

    public function testSyncGivesUpOnTheRegionAfterThreeConsecutiveBatchesWithoutAnyAnswer(): void
    {
        $service = $this->serviceWithHistories([]);
        $typeIds = range(1000, 1099);

        $result = $service->syncRegionalDailyVolumes(self::HEIMATAR, $typeIds);

        $this->assertSame(['synced' => 0, 'failed' => 60, 'aborted' => true], $result);
        $this->assertCount(60, $this->requestedEsiUrls);
    }

    public function testABatchWithAtLeastOneAnswerResetsTheFailureStreak(): void
    {
        $answered = [1000 => $this->thirtyTwoDaysOfHistory(), 1020 => $this->thirtyTwoDaysOfHistory(), 1040 => $this->thirtyTwoDaysOfHistory()];
        $service = $this->serviceWithHistories($answered);

        $result = $service->syncRegionalDailyVolumes(self::HEIMATAR, range(1000, 1079));

        $this->assertSame(['synced' => 3, 'failed' => 77, 'aborted' => false], $result);
    }

    public function testCachedDailyVolumesForRegionReadsTheCacheOnlyAndOmitsMissingTypes(): void
    {
        $service = $this->serviceWithHistories([]);
        $this->store('volume_10000030_34', 12.5);
        $this->store('volume_10000002_35', 99.0);

        $volumes = $service->getCachedDailyVolumesForRegion(self::HEIMATAR, [self::TRITANIUM, self::PYERITE]);

        $this->assertSame([self::TRITANIUM => 12.5], $volumes);
        $this->assertSame([], $this->requestedEsiUrls);
    }

    private function store(string $key, float $volume): void
    {
        $item = $this->marketCache->getItem($key);
        $item->set($volume);
        $this->marketCache->save($item);
    }

    /**
     * @param array<int, list<array<string, mixed>>> $historiesByTypeId
     */
    private function serviceWithHistories(array $historiesByTypeId): JitaMarketService
    {
        $routes = [];
        foreach ($historiesByTypeId as $typeId => $history) {
            $routes[sprintf('/markets/%d/history/#%d', self::HEIMATAR, $typeId)] = [$history];
        }

        return $this->createJitaMarketServiceOnSimulatedEsi($routes, $this->marketCache, $this->createStub(\Doctrine\DBAL\Connection::class));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function thirtyTwoDaysOfHistory(): array
    {
        $history = [];
        $day = new \DateTimeImmutable('2026-09-01');
        foreach ([1_000_000, 1_000_000] as $volume) {
            $history[] = ['date' => $day->format('Y-m-d'), 'order_count' => 50, 'volume' => $volume, 'lowest' => 4.0, 'highest' => 6.0, 'average' => 5.0];
            $day = $day->modify('+1 day');
        }
        for ($i = 1; $i <= 30; ++$i) {
            $volume = $i * 10 + ($i === 30 ? 1 : 0);
            $history[] = ['date' => $day->format('Y-m-d'), 'order_count' => 50, 'volume' => $volume, 'lowest' => 4.0, 'highest' => 6.0, 'average' => 5.0];
            $day = $day->modify('+1 day');
        }

        return $history;
    }
}
