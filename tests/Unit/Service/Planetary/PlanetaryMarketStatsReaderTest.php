<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary;

use App\Constant\EveConstants;
use App\Repository\MarketPriceHistoryRepository;
use App\Service\Planetary\PlanetaryMarketStatsReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryMarketStatsReader::class)]
#[Group('issue-1404')]
final class PlanetaryMarketStatsReaderTest extends TestCase
{
    private const int COOLANT = 91_021;
    private const int MECHANICAL_PARTS = 91_022;
    private const int ROBOTICS = 91_031;
    private const int THE_FORGE = EveConstants::THE_FORGE_REGION_ID;
    private const string FIRST_DAY = '2026-01-01';

    public function testThirtyEntriesAt100ThenThirtyAt110GiveAPriceChangeOfTenAndTheRecentAverages(): void
    {
        $reader = $this->readerOver([
            self::COOLANT => [...$this->entries(30, 100.0, 10, self::FIRST_DAY), ...$this->entries(30, 110.0, 20, '2026-01-31')],
        ]);

        $stats = $reader->statsFor([self::COOLANT], self::THE_FORGE);

        $this->assertSame([
            ['typeId' => self::COOLANT, 'averageDailyVolume' => 20.0, 'averagePrice' => 110.0, 'priceChange' => 10.0, 'lastDate' => '2026-03-01'],
        ], $stats);
    }

    public function testThirtyEntriesOnlyLeaveThePriceChangeUnknown(): void
    {
        $reader = $this->readerOver([self::COOLANT => $this->entries(30, 100.0, 10, self::FIRST_DAY)]);

        $stats = $reader->statsFor([self::COOLANT], self::THE_FORGE);

        $this->assertCount(1, $stats);
        $this->assertNull($stats[0]['priceChange']);
        $this->assertSame(100.0, $stats[0]['averagePrice']);
        $this->assertSame(10.0, $stats[0]['averageDailyVolume']);
    }

    public function testAFewEntriesAreAveragedTogetherWithoutAPreviousWindow(): void
    {
        $reader = $this->readerOver([
            self::COOLANT => [
                ['date' => '2026-02-01', 'average' => 40.0, 'volume' => 6],
                ['date' => '2026-02-02', 'average' => 50.0, 'volume' => 9],
                ['date' => '2026-02-03', 'average' => 60.0, 'volume' => 12],
            ],
        ]);

        $stats = $reader->statsFor([self::COOLANT], self::THE_FORGE);

        $this->assertSame([
            ['typeId' => self::COOLANT, 'averageDailyVolume' => 9.0, 'averagePrice' => 50.0, 'priceChange' => null, 'lastDate' => '2026-02-03'],
        ], $stats);
    }

    public function testFortyFiveEntriesCompareTheLatestThirtyWithThePreviousFifteen(): void
    {
        $reader = $this->readerOver([
            self::COOLANT => [...$this->entries(15, 100.0, 8, self::FIRST_DAY), ...$this->entries(30, 120.0, 14, '2026-01-16')],
        ]);

        $stats = $reader->statsFor([self::COOLANT], self::THE_FORGE);

        $this->assertCount(1, $stats);
        $this->assertSame(20.0, $stats[0]['priceChange']);
        $this->assertSame(120.0, $stats[0]['averagePrice']);
        $this->assertSame(14.0, $stats[0]['averageDailyVolume']);
    }

    public function testAZeroPreviousAverageLeavesThePriceChangeUnknown(): void
    {
        $reader = $this->readerOver([
            self::COOLANT => [...$this->entries(30, 0.0, 10, self::FIRST_DAY), ...$this->entries(30, 55.0, 12, '2026-01-31')],
        ]);

        $stats = $reader->statsFor([self::COOLANT], self::THE_FORGE);

        $this->assertCount(1, $stats);
        $this->assertNull($stats[0]['priceChange']);
        $this->assertSame(55.0, $stats[0]['averagePrice']);
    }

    public function testASixtyFirstEntryOlderThanTheWindowsIsIgnored(): void
    {
        $reader = $this->readerOver([
            self::COOLANT => [
                ['date' => '2025-12-31', 'average' => 10_000.0, 'volume' => 99_999],
                ...$this->entries(30, 100.0, 10, self::FIRST_DAY),
                ...$this->entries(30, 110.0, 20, '2026-01-31'),
            ],
        ]);

        $stats = $reader->statsFor([self::COOLANT], self::THE_FORGE);

        $this->assertCount(1, $stats);
        $this->assertSame(10.0, $stats[0]['priceChange']);
        $this->assertSame(110.0, $stats[0]['averagePrice']);
    }

    public function testTheAveragesAreRoundedToTheCentAndThePriceChangeToATenth(): void
    {
        $recent = [...$this->entries(29, 110.0, 10, '2026-01-31'), ['date' => '2026-03-01', 'average' => 124.0, 'volume' => 11]];
        $reader = $this->readerOver([self::COOLANT => [...$this->entries(30, 100.0, 10, self::FIRST_DAY), ...$recent]]);

        $stats = $reader->statsFor([self::COOLANT], self::THE_FORGE);

        $this->assertCount(1, $stats);
        $this->assertSame(110.47, $stats[0]['averagePrice']);
        $this->assertSame(10.03, $stats[0]['averageDailyVolume']);
        $this->assertSame(10.5, $stats[0]['priceChange']);
    }

    public function testAPriceFallIsANegativePriceChange(): void
    {
        $reader = $this->readerOver([
            self::COOLANT => [...$this->entries(30, 100.0, 10, self::FIRST_DAY), ...$this->entries(30, 90.0, 10, '2026-01-31')],
        ]);

        $stats = $reader->statsFor([self::COOLANT], self::THE_FORGE);

        $this->assertCount(1, $stats);
        $this->assertSame(-10.0, $stats[0]['priceChange']);
    }

    public function testEachTypeKeepsItsOwnFiguresSortedByTypeAndATypeWithoutHistoryIsAbsent(): void
    {
        $reader = $this->readerOver([
            self::ROBOTICS => $this->entries(30, 29_800.0, 460, self::FIRST_DAY),
            self::COOLANT => $this->entries(30, 1_010.0, 12_000, '2026-02-01'),
        ]);

        $stats = $reader->statsFor([self::ROBOTICS, self::MECHANICAL_PARTS, self::COOLANT], self::THE_FORGE);

        $this->assertSame([self::COOLANT, self::ROBOTICS], array_column($stats, 'typeId'));
        $this->assertSame([12_000.0, 460.0], array_column($stats, 'averageDailyVolume'));
        $this->assertSame([1_010.0, 29_800.0], array_column($stats, 'averagePrice'));
        $this->assertSame(['2026-03-02', '2026-01-30'], array_column($stats, 'lastDate'));
    }

    public function testTheRepositoryIsAskedOnceForTheRequestedTypesOfTheRegionWithTheLimitOfBothWindows(): void
    {
        $historyRepository = $this->createMock(MarketPriceHistoryRepository::class);
        $historyRepository->expects($this->once())
            ->method('findLatestEntriesByType')
            ->with([self::COOLANT, self::MECHANICAL_PARTS], self::THE_FORGE, 60)
            ->willReturn([]);

        $stats = (new PlanetaryMarketStatsReader($historyRepository))->statsFor([self::COOLANT, self::MECHANICAL_PARTS], self::THE_FORGE);

        $this->assertSame([], $stats);
    }

    private function readerOver(array $entriesByType): PlanetaryMarketStatsReader
    {
        $historyRepository = $this->createStub(MarketPriceHistoryRepository::class);
        $historyRepository->method('findLatestEntriesByType')->willReturn($entriesByType);

        return new PlanetaryMarketStatsReader($historyRepository);
    }

    private function entries(int $count, float $average, int $volume, string $firstDay): array
    {
        $entries = [];
        $day = new \DateTimeImmutable($firstDay);
        for ($position = 0; $position < $count; ++$position) {
            $entries[] = ['date' => $day->modify(sprintf('+%d days', $position))->format('Y-m-d'), 'average' => $average, 'volume' => $volume];
        }

        return $entries;
    }
}
