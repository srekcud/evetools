<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Opportunities;

use App\Service\Industry\Opportunities\CapacityLimit;
use App\Service\Industry\Opportunities\ProductionCapacityCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionCapacityCalculator::class)]
#[Group('issue-356')]
final class ProductionCapacityCalculatorTest extends TestCase
{
    /**
     * @return iterable<string, array{float, float, ?int, int, float, int, ?int, int, int, CapacityLimit}>
     */
    public static function weeklyCapacities(): iterable
    {
        yield 'capital limits below the market demand' => [180.0, 659_773.47, 59_500_000, 14, 1_662.0, 126, 90, 5_094, 90, CapacityLimit::Capital];
        yield 'market limits below the capital' => [14.0, 659_773.47, 59_500_000, 14, 1_662.0, 9, 90, 5_094, 9, CapacityLimit::Market];
        yield 'no capital cap leaves the capital out' => [420.0, 412_000.0, null, 1, 831.0, 294, null, 727, 294, CapacityLimit::Market];
        yield 'no free slot means nothing can be produced' => [300.0, 100.0, 1_000_000, 0, 600.0, 210, 10_000, 0, 0, CapacityLimit::Slots];
    }

    #[DataProvider('weeklyCapacities')]
    public function testWeeklyQuantityIsTheSmallestOfTheMarketTheCapitalAndTheSlots(
        float $dailyVolume,
        float $unitCost,
        ?int $capitalCap,
        int $freeSlots,
        float $secondsPerUnit,
        int $expectedMarketLimit,
        ?int $expectedCapitalLimit,
        int $expectedSlotsLimit,
        int $expectedQuantity,
        CapacityLimit $expectedLimit,
    ): void {
        $capacity = (new ProductionCapacityCalculator())->weekly($dailyVolume, $unitCost, $capitalCap, $freeSlots, $secondsPerUnit);

        self::assertSame($expectedMarketLimit, $capacity->marketLimit);
        self::assertSame($expectedCapitalLimit, $capacity->capitalLimit);
        self::assertSame($expectedSlotsLimit, $capacity->slotsLimit);
        self::assertSame($expectedQuantity, $capacity->quantity);
        self::assertSame($expectedLimit, $capacity->limitedBy);
    }

    public function testLimitsAreReportedAsTheMarketWhenTheyTieWithTheCapitalAndTheSlots(): void
    {
        $capacity = (new ProductionCapacityCalculator())->weekly(100.0, 1_000.0, 70_000, 1, 8_640.0);

        self::assertSame(70, $capacity->quantity);
        self::assertSame(CapacityLimit::Market, $capacity->limitedBy);
    }

    public function testLimitsAreReportedAsTheCapitalWhenItTiesWithTheSlots(): void
    {
        $capacity = (new ProductionCapacityCalculator())->weekly(1_000.0, 1_000.0, 70_000, 1, 8_640.0);

        self::assertSame(70, $capacity->quantity);
        self::assertSame(CapacityLimit::Capital, $capacity->limitedBy);
    }

    public function testAProductWithoutDailyVolumeCannotBeSold(): void
    {
        $capacity = (new ProductionCapacityCalculator())->weekly(0.0, 1_000.0, 70_000, 5, 600.0);

        self::assertSame(0, $capacity->quantity);
        self::assertSame(CapacityLimit::Market, $capacity->limitedBy);
    }

    public function testIskPerSlotHourIsTheUnitProfitOverTheHoursOfOneUnit(): void
    {
        $iskPerSlotHour = (new ProductionCapacityCalculator())->iskPerSlotHour(64_088.53, 1_662.0);

        self::assertEqualsWithDelta(138_819.92, $iskPerSlotHour, 0.01);
    }
}
