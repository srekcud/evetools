<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\StructureTradedVolumeService;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Uid\Uuid;

#[CoversClass(StructureTradedVolumeService::class)]
final class StructureTradedVolumeServiceTest extends IntegrationTestCase
{
    private const int STRUCTURE_ID = 1_035_466_617_946;
    private const int OTHER_STRUCTURE_ID = 1_046_664_001_931;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const string FIRST_SYNC = '2026-11-03 08:00:00';
    private const string SECOND_SYNC = '2026-11-03 09:00:00';
    private const string THIRD_SYNC = '2026-11-03 10:00:00';
    private const string NEXT_DAY_SYNC = '2026-11-04 08:00:00';
    private const string TODAY = '2026-11-05 14:30:00';
    private const int BASELINE_LIFETIME_SECONDS = 10_800;

    private ArrayAdapter $cache;
    private BufferingLogger $logger;
    private StructureTradedVolumeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new ArrayAdapter();
        $this->logger = new BufferingLogger();
        $this->service = $this->createService($this->cache);
    }

    public function testFirstSyncWithoutBaselineCountsNothingAndWritesTheBaseline(): void
    {
        $this->service->recordFromSync(self::STRUCTURE_ID, $this->hundredOrders(), new \DateTimeImmutable(self::FIRST_SYNC));

        self::assertSame([], $this->tradedVolumeRows(self::STRUCTURE_ID));

        $this->service->recordFromSync(self::STRUCTURE_ID, [
            $this->order(7_000_001, self::TRITANIUM, 995),
        ], new \DateTimeImmutable(self::SECOND_SYNC));

        self::assertSame([[self::TRITANIUM, '2026-11-03', 5]], $this->tradedVolumeRows(self::STRUCTURE_ID));
    }

    public function testTwoSyncsOfTheSameDayAccumulateIntoOneRowOfThatDay(): void
    {
        $this->sync(self::FIRST_SYNC, [$this->order(7_100_001, self::TRITANIUM, 1000)]);
        $this->sync(self::SECOND_SYNC, [$this->order(7_100_001, self::TRITANIUM, 700)]);
        $this->sync(self::THIRD_SYNC, [$this->order(7_100_001, self::TRITANIUM, 500)]);

        self::assertSame([[self::TRITANIUM, '2026-11-03', 500]], $this->tradedVolumeRows(self::STRUCTURE_ID));
    }

    public function testSyncsOfDifferentDaysWriteOneRowPerDay(): void
    {
        $this->sync(self::FIRST_SYNC, [$this->order(7_200_001, self::PYERITE, 900)]);
        $this->sync(self::SECOND_SYNC, [$this->order(7_200_001, self::PYERITE, 850)]);
        $this->sync(self::NEXT_DAY_SYNC, [$this->order(7_200_001, self::PYERITE, 820)]);

        self::assertSame([
            [self::PYERITE, '2026-11-03', 50],
            [self::PYERITE, '2026-11-04', 30],
        ], $this->tradedVolumeRows(self::STRUCTURE_ID));
    }

    public function testTypePresentWithoutDecreaseIsObservedWithZeroAndAbsentTypeHasNoRow(): void
    {
        $this->sync(self::FIRST_SYNC, [
            $this->order(7_300_001, self::TRITANIUM, 1000),
            $this->order(7_300_002, self::MEXALLON, 40),
            $this->order(7_300_003, self::PYERITE, 300),
        ]);
        $this->sync(self::SECOND_SYNC, [
            $this->order(7_300_001, self::TRITANIUM, 920),
            $this->order(7_300_002, self::MEXALLON, 40),
        ]);

        self::assertSame([
            [self::TRITANIUM, '2026-11-03', 80],
            [self::MEXALLON, '2026-11-03', 0],
        ],$this->tradedVolumeRows(self::STRUCTURE_ID));
    }

    public function testDecreasesAreCountedPerTypeAndNewOrdersAndDisappearedOrdersAreNot(): void
    {
        $this->sync(self::FIRST_SYNC, [
            $this->order(7_400_001, self::TRITANIUM, 1000),
            $this->order(7_400_002, self::TRITANIUM, 50),
            $this->order(7_400_003, self::PYERITE, 10),
            $this->order(7_400_004, self::MEXALLON, 600),
        ]);
        $this->sync(self::SECOND_SYNC, [
            $this->order(7_400_001, self::TRITANIUM, 900),
            $this->order(7_400_002, self::TRITANIUM, 20),
            $this->order(7_400_003, self::PYERITE, 4),
            $this->order(7_400_005, self::MEXALLON, 250),
        ]);

        self::assertSame([
            [self::TRITANIUM, '2026-11-03', 130],
            [self::PYERITE, '2026-11-03', 6],
            [self::MEXALLON, '2026-11-03', 0],
        ],$this->tradedVolumeRows(self::STRUCTURE_ID));
    }

    public function testBaselineOfAStructureIsNotUsedByAnotherStructure(): void
    {
        $this->service->recordFromSync(self::STRUCTURE_ID, [
            $this->order(7_500_001, self::TRITANIUM, 1000),
        ], new \DateTimeImmutable(self::FIRST_SYNC));
        $this->service->recordFromSync(self::OTHER_STRUCTURE_ID, [
            $this->order(7_500_001, self::TRITANIUM, 600),
        ], new \DateTimeImmutable(self::SECOND_SYNC));

        self::assertSame([], $this->tradedVolumeRows(self::STRUCTURE_ID));
        self::assertSame([], $this->tradedVolumeRows(self::OTHER_STRUCTURE_ID));
    }

    public function testBaselineOlderThanThreeHoursIsExpiredSoNothingIsCounted(): void
    {
        $clock = new MockClock();
        $service = $this->createService(new ArrayAdapter(clock: $clock));
        $service->recordFromSync(self::STRUCTURE_ID, [$this->order(7_600_001, self::TRITANIUM, 1000)], new \DateTimeImmutable(self::FIRST_SYNC));

        $clock->sleep(self::BASELINE_LIFETIME_SECONDS + 1);
        $service->recordFromSync(self::STRUCTURE_ID, [$this->order(7_600_001, self::TRITANIUM, 700)], new \DateTimeImmutable(self::SECOND_SYNC));

        self::assertSame([], $this->tradedVolumeRows(self::STRUCTURE_ID));
    }

    public function testBaselineYoungerThanThreeHoursStillGivesADifference(): void
    {
        $clock = new MockClock();
        $service = $this->createService(new ArrayAdapter(clock: $clock));
        $service->recordFromSync(self::STRUCTURE_ID, [$this->order(7_700_001, self::TRITANIUM, 1000)], new \DateTimeImmutable(self::FIRST_SYNC));

        $clock->sleep(self::BASELINE_LIFETIME_SECONDS - 1);
        $service->recordFromSync(self::STRUCTURE_ID, [$this->order(7_700_001, self::TRITANIUM, 700)], new \DateTimeImmutable(self::SECOND_SYNC));

        self::assertSame([[self::TRITANIUM, '2026-11-03', 300]], $this->tradedVolumeRows(self::STRUCTURE_ID));
    }

    public function testUnreadableBaselineIsTreatedAsAbsentWithAWarningAndIsReplaced(): void
    {
        $this->sync(self::FIRST_SYNC, [$this->order(7_800_001, self::TRITANIUM, 1000)]);
        foreach (array_keys($this->cache->getValues()) as $key) {
            $this->cache->save($this->cache->getItem((string) $key)->set('not a baseline'));
        }

        $this->sync(self::SECOND_SYNC, [$this->order(7_800_001, self::TRITANIUM, 700)]);

        self::assertSame([], $this->tradedVolumeRows(self::STRUCTURE_ID));
        self::assertContains('warning', array_column($this->logger->cleanLogs(), 0));

        $this->sync(self::THIRD_SYNC, [$this->order(7_800_001, self::TRITANIUM, 650)]);

        self::assertSame([[self::TRITANIUM, '2026-11-03', 50]], $this->tradedVolumeRows(self::STRUCTURE_ID));
    }

    /** @param array<string, mixed> $malformedOrder */
    #[DataProvider('malformedOrders')]
    public function testMalformedOrderRaisesAnInvalidArgumentExceptionInsteadOfBeingSkipped(array $malformedOrder): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->recordFromSync(self::STRUCTURE_ID, [
            $this->order(7_900_001, self::TRITANIUM, 1000),
            $malformedOrder,
        ], new \DateTimeImmutable(self::FIRST_SYNC));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedOrders(): iterable
    {
        $valid = ['order_id' => 7_900_002, 'type_id' => self::PYERITE, 'volume_remain' => 415];

        foreach (['order_id', 'type_id', 'volume_remain'] as $field) {
            $withoutField = $valid;
            unset($withoutField[$field]);
            yield "{$field} missing" => [$withoutField];
            yield "{$field} as string" => [[...$valid, $field => '415']];
            yield "{$field} as float" => [[...$valid, $field => 415.5]];
            yield "{$field} as null" => [[...$valid, $field => null]];
        }
    }

    public function testAverageIsTheSumOfTheTypeOverTheCoveredDaysOfTheStructure(): void
    {
        $this->givenCoveredDays(self::STRUCTURE_ID, self::TRITANIUM, [1 => 50, 2 => 70, 3 => 90, 4 => 110, 5 => 130, 6 => 150, 7 => 100]);
        $this->givenCoveredDays(self::STRUCTURE_ID, self::PYERITE, [2 => 100, 4 => 30, 6 => 10]);

        $averages = $this->service->getAverageDailyVolumes(self::STRUCTURE_ID, [self::TRITANIUM, self::PYERITE], new \DateTimeImmutable(self::TODAY));

        self::assertSame([self::TRITANIUM => 100.0, self::PYERITE => 20.0], $averages);
    }

    public function testAverageKeepsItsFractionalPart(): void
    {
        $this->givenCoveredDays(self::STRUCTURE_ID, self::MEXALLON, [1 => 4, 2 => 0, 3 => 6, 4 => 0, 5 => 5, 6 => 0, 7 => 5, 8 => 0]);

        $averages = $this->service->getAverageDailyVolumes(self::STRUCTURE_ID, [self::MEXALLON], new \DateTimeImmutable(self::TODAY));

        self::assertSame([self::MEXALLON => 2.5], $averages);
    }

    public function testAverageIsUnknownBelowSevenCoveredDays(): void
    {
        $this->givenCoveredDays(self::STRUCTURE_ID, self::TRITANIUM, [1 => 120, 2 => 80, 3 => 100, 4 => 90, 5 => 110, 6 => 100]);

        $averages = $this->service->getAverageDailyVolumes(self::STRUCTURE_ID, [self::TRITANIUM], new \DateTimeImmutable(self::TODAY));

        self::assertSame([self::TRITANIUM => null], $averages);
    }

    public function testAverageIsUnknownForATypeWithoutRowOrWithZeroEverywhereButKnownForOtherTypes(): void
    {
        $this->givenCoveredDays(self::STRUCTURE_ID, self::PYERITE, [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0]);
        $this->givenCoveredDays(self::STRUCTURE_ID, self::MEXALLON, [1 => 14, 7 => 0]);

        $averages = $this->service->getAverageDailyVolumes(
            self::STRUCTURE_ID,
            [self::TRITANIUM, self::PYERITE, self::MEXALLON],
            new \DateTimeImmutable(self::TODAY),
        );

        self::assertSame([self::TRITANIUM => null, self::PYERITE => null, self::MEXALLON => 2.0], $averages);
    }

    public function testTodayIsNotACoveredDayNorPartOfTheSum(): void
    {
        $this->givenCoveredDays(self::STRUCTURE_ID, self::TRITANIUM, [0 => 400, 1 => 90, 2 => 90, 3 => 90, 4 => 90, 5 => 90, 6 => 90]);

        $averages = $this->service->getAverageDailyVolumes(self::STRUCTURE_ID, [self::TRITANIUM], new \DateTimeImmutable(self::TODAY));

        self::assertSame([self::TRITANIUM => null], $averages);
    }

    public function testRowsOfTodayDoNotChangeTheAverageOfSevenPastDays(): void
    {
        $this->givenCoveredDays(self::STRUCTURE_ID, self::TRITANIUM, [0 => 9999, 1 => 50, 2 => 70, 3 => 90, 4 => 110, 5 => 130, 6 => 150, 7 => 100]);

        $averages = $this->service->getAverageDailyVolumes(self::STRUCTURE_ID, [self::TRITANIUM], new \DateTimeImmutable(self::TODAY));

        self::assertSame([self::TRITANIUM => 100.0], $averages);
    }

    public function testRowOlderThanThirtyDaysIsNeitherSummedNorCountedAsACoveredDay(): void
    {
        $this->givenCoveredDays(self::STRUCTURE_ID, self::TRITANIUM, [1 => 100, 2 => 100, 3 => 100, 4 => 100, 5 => 100, 6 => 100, 7 => 100, 8 => 100, 9 => 100, 10 => 100, 31 => 5000]);

        $averages = $this->service->getAverageDailyVolumes(self::STRUCTURE_ID, [self::TRITANIUM], new \DateTimeImmutable(self::TODAY));

        self::assertSame([self::TRITANIUM => 100.0], $averages);
    }

    public function testRowsOlderThanThirtyDaysCannotMakeUpTheSevenCoveredDays(): void
    {
        $this->givenCoveredDays(self::STRUCTURE_ID, self::TRITANIUM, [1 => 100, 2 => 100, 3 => 100, 4 => 100, 5 => 100, 6 => 100, 31 => 100, 32 => 100]);

        $averages = $this->service->getAverageDailyVolumes(self::STRUCTURE_ID, [self::TRITANIUM], new \DateTimeImmutable(self::TODAY));

        self::assertSame([self::TRITANIUM => null], $averages);
    }

    public function testRowThirtyDaysBeforeTodayIsStillInTheWindow(): void
    {
        $this->givenCoveredDays(self::STRUCTURE_ID, self::TRITANIUM, [1 => 100, 2 => 100, 3 => 100, 4 => 100, 5 => 100, 6 => 100, 30 => 800]);

        $averages = $this->service->getAverageDailyVolumes(self::STRUCTURE_ID, [self::TRITANIUM], new \DateTimeImmutable(self::TODAY));

        self::assertSame([self::TRITANIUM => 200.0], $averages);
    }

    public function testEachStructureAverageOnlyReadsItsOwnRows(): void
    {
        $this->givenCoveredDays(self::STRUCTURE_ID, self::TRITANIUM, [1 => 100, 2 => 100, 3 => 100, 4 => 100, 5 => 100, 6 => 100, 7 => 100]);
        $this->givenCoveredDays(self::STRUCTURE_ID, self::PYERITE, [1 => 14, 2 => 14, 3 => 14, 4 => 14, 5 => 14, 6 => 14, 7 => 14]);
        $this->givenCoveredDays(self::OTHER_STRUCTURE_ID, self::TRITANIUM, [1 => 30, 2 => 30, 3 => 30, 4 => 30, 5 => 30, 6 => 30, 7 => 30, 8 => 30, 9 => 30, 10 => 30]);
        $this->givenCoveredDays(self::OTHER_STRUCTURE_ID, self::PYERITE, [1 => 7, 2 => 7, 3 => 6]);

        $today = new \DateTimeImmutable(self::TODAY);

        self::assertSame(
            [self::TRITANIUM => 100.0, self::PYERITE => 14.0],
            $this->service->getAverageDailyVolumes(self::STRUCTURE_ID, [self::TRITANIUM, self::PYERITE], $today),
        );
        self::assertSame(
            [self::TRITANIUM => 30.0, self::PYERITE => 2.0],
            $this->service->getAverageDailyVolumes(self::OTHER_STRUCTURE_ID, [self::TRITANIUM, self::PYERITE], $today),
        );
    }

    public function testStructureNeverSyncedHasUnknownAverageForEveryRequestedType(): void
    {
        $averages = $this->service->getAverageDailyVolumes(self::STRUCTURE_ID, [self::TRITANIUM, self::PYERITE], new \DateTimeImmutable(self::TODAY));

        self::assertSame([self::TRITANIUM => null, self::PYERITE => null], $averages);
    }

    public function testNoRequestedTypeGivesAnEmptyArray(): void
    {
        $this->givenCoveredDays(self::STRUCTURE_ID, self::TRITANIUM, [1 => 100, 2 => 100, 3 => 100, 4 => 100, 5 => 100, 6 => 100, 7 => 100]);

        self::assertSame([], $this->service->getAverageDailyVolumes(self::STRUCTURE_ID, [], new \DateTimeImmutable(self::TODAY)));
    }

    private function createService(ArrayAdapter $cache): StructureTradedVolumeService
    {
        return new StructureTradedVolumeService($this->em->getConnection(), $cache, $this->logger);
    }

    /** @param list<array<string, mixed>> $orders */
    private function sync(string $syncedAt, array $orders): void
    {
        $this->service->recordFromSync(self::STRUCTURE_ID, $orders, new \DateTimeImmutable($syncedAt));
    }

    /** @return array<string, mixed> */
    private function order(int $orderId, int $typeId, int $volumeRemain): array
    {
        return [
            'order_id' => $orderId,
            'type_id' => $typeId,
            'volume_remain' => $volumeRemain,
            'price' => 4.5,
            'is_buy_order' => false,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function hundredOrders(): array
    {
        $orders = [];
        for ($index = 0; $index < 100; $index++) {
            $orders[] = $this->order(8_000_000 + $index, self::TRITANIUM + ($index % 3), 1000 + $index);
        }
        $orders[0] = $this->order(7_000_001, self::TRITANIUM, 1000);

        return $orders;
    }

    /** @param array<int, int> $tradedVolumeByDaysBeforeToday */
    private function givenCoveredDays(int $structureId, int $typeId, array $tradedVolumeByDaysBeforeToday): void
    {
        foreach ($tradedVolumeByDaysBeforeToday as $daysBeforeToday => $tradedVolume) {
            $this->em->getConnection()->executeStatement(
                'INSERT INTO structure_traded_volumes (id, structure_id, type_id, date, traded_volume) VALUES (:id, :structureId, :typeId, :date, :tradedVolume)',
                [
                    'id' => Uuid::v4()->toRfc4122(),
                    'structureId' => $structureId,
                    'typeId' => $typeId,
                    'date' => (new \DateTimeImmutable(self::TODAY))->modify("-{$daysBeforeToday} days")->format('Y-m-d'),
                    'tradedVolume' => $tradedVolume,
                ],
            );
        }
    }

    /** @return list<array{int, string, int}> */
    private function tradedVolumeRows(int $structureId): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT type_id, date, traded_volume FROM structure_traded_volumes WHERE structure_id = :structureId ORDER BY date, type_id',
            ['structureId' => $structureId],
        );

        return array_map(
            static fn (array $row): array => [(int) $row['type_id'], (string) $row['date'], (int) $row['traded_volume']],
            $rows,
        );
    }
}
