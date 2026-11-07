<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Revenue;

use App\Service\Industry\Revenue\CompletedProjectFigures;
use App\Service\Industry\Revenue\IndustryRevenueAggregator;
use App\Service\Industry\Revenue\IndustryRevenueMonth;
use App\Service\Industry\Revenue\IndustryRevenueProduct;
use App\Service\Industry\Revenue\IndustryRevenueReport;
use App\Service\Industry\Revenue\IndustryRevenueTotals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryRevenueAggregator::class)]
#[Group('issue-360')]
final class IndustryRevenueAggregatorTest extends TestCase
{
    private const int SABRE = 22456;
    private const int ISHTAR = 12005;
    private const int HECATE = 35683;
    private const int MARGIN_PRECISION = 6;

    public function testSingleSoloProjectGivesItsMonthProfitMarginHoursAndIskPerHour(): void
    {
        $report = $this->aggregate([
            $this->project('2026-11-03T14:00:00+00:00', 120_000_000.0, 400_000_000.0, 100.0),
        ]);

        self::assertSame([
            ['2026-11', 1, 0, 120_000_000.0, 400_000_000.0, 30.0, 100.0, 1_200_000.0],
        ], $this->monthSummaries($report));
    }

    public function testTwoKnownProjectsOfTheSameMonthAddUpWithoutAveragingTheirRatios(): void
    {
        $report = $this->aggregate([
            $this->project('2026-11-03T14:00:00+00:00', 120_000_000.0, 400_000_000.0, 100.0),
            $this->project('2026-11-12T09:30:00+00:00', 30_000_000.0, 100_000_000.0, 50.0, self::ISHTAR, 'Ishtar'),
        ]);

        self::assertSame([
            ['2026-11', 2, 0, 150_000_000.0, 500_000_000.0, 30.0, 150.0, 1_000_000.0],
        ], $this->monthSummaries($report));
    }

    public function testUnknownProfitProjectIsCountedButAddsNoProfitCapitalNorHours(): void
    {
        $report = $this->aggregate([
            $this->project('2026-11-03T14:00:00+00:00', 120_000_000.0, 400_000_000.0, 100.0),
            $this->project('2026-11-12T09:30:00+00:00', 30_000_000.0, 100_000_000.0, 50.0, self::ISHTAR, 'Ishtar'),
            $this->project('2026-11-20T21:00:00+00:00', null, null, 40.0, self::HECATE, 'Hecate'),
        ]);

        self::assertSame([
            ['2026-11', 3, 1, 150_000_000.0, 500_000_000.0, 30.0, 150.0, 1_000_000.0],
        ], $this->monthSummaries($report));
        self::assertSame(3, $report->totals->projectCount);
        self::assertSame(1, $report->totals->unknownProjectCount);
    }

    public function testGroupProjectWithoutOwnJobsHasZeroHoursAndNoIskPerHourAlone(): void
    {
        $report = $this->aggregate([
            $this->project('2026-10-08T10:00:00+00:00', 12_000_000.0, 100_000_000.0, 0.0),
        ]);

        self::assertSame([
            ['2026-10', 1, 0, 12_000_000.0, 100_000_000.0, 12.0, 0.0, null],
        ], $this->monthSummaries($report));
    }

    public function testZeroHourProjectKeepsItsProfitButStaysOutOfTheIskPerHourOfItsMonth(): void
    {
        $report = $this->aggregate([
            $this->project('2026-11-03T14:00:00+00:00', 120_000_000.0, 400_000_000.0, 100.0),
            $this->project('2026-11-09T16:00:00+00:00', 12_000_000.0, 100_000_000.0, 0.0, self::HECATE, 'Hecate'),
        ]);

        self::assertSame([
            ['2026-11', 2, 0, 132_000_000.0, 500_000_000.0, 26.4, 100.0, 1_200_000.0],
        ], $this->monthSummaries($report));
    }

    public function testUnknownHoursKeepTheProfitButExcludeTheProjectFromHoursAndIskPerHour(): void
    {
        $report = $this->aggregate([
            $this->project('2026-10-05T10:00:00+00:00', 80_000_000.0, 200_000_000.0, null),
            $this->project('2026-11-05T10:00:00+00:00', 80_000_000.0, 200_000_000.0, null),
            $this->project('2026-11-06T10:00:00+00:00', 60_000_000.0, 100_000_000.0, 30.0, self::ISHTAR, 'Ishtar'),
        ]);

        self::assertSame([
            ['2026-11', 2, 0, 140_000_000.0, 300_000_000.0, 46.666667, 30.0, 2_000_000.0],
            ['2026-10', 1, 0, 80_000_000.0, 200_000_000.0, 40.0, null, null],
        ], $this->monthSummaries($report));
    }

    public function testZeroCapitalLeavesTheMarginUnknown(): void
    {
        $report = $this->aggregate([
            $this->project('2026-11-03T14:00:00+00:00', 5_000_000.0, 0.0, 10.0),
        ]);

        self::assertSame([
            ['2026-11', 1, 0, 5_000_000.0, 0.0, null, 10.0, 500_000.0],
        ], $this->monthSummaries($report));
    }

    public function testLossMakingProjectIsCountedAsIsWithNegativeMarginAndIskPerHour(): void
    {
        $report = $this->aggregate([
            $this->project('2026-11-03T14:00:00+00:00', -20_000_000.0, 100_000_000.0, 10.0),
        ]);

        self::assertSame([
            ['2026-11', 1, 0, -20_000_000.0, 100_000_000.0, -20.0, 10.0, -2_000_000.0],
        ], $this->monthSummaries($report));
        self::assertSame(-20_000_000.0, $report->totals->profit);
    }

    public function testMonthsAreListedMostRecentFirstAndBucketedByUtcMonth(): void
    {
        $report = $this->aggregate([
            $this->project('2026-09-15T12:00:00+00:00', 1_000_000.0, 10_000_000.0, 5.0),
            $this->project('2026-11-02T12:00:00+00:00', 2_000_000.0, 10_000_000.0, 5.0),
            $this->project('2025-12-31T23:30:00+00:00', 3_000_000.0, 10_000_000.0, 5.0),
            $this->project('2026-11-01T01:30:00+02:00', 4_000_000.0, 10_000_000.0, 5.0),
        ]);

        self::assertSame(
            [['2026-11', 1], ['2026-10', 1], ['2026-09', 1], ['2025-12', 1]],
            array_map(static fn (IndustryRevenueMonth $month): array => [$month->month, $month->projectCount], $report->months),
        );
        self::assertSame(
            [2_000_000.0, 4_000_000.0, 1_000_000.0, 3_000_000.0],
            array_map(static fn (IndustryRevenueMonth $month): float => $month->profit, $report->months),
        );
    }

    public function testPeriodTotalsSumEveryMonthAndRecomputeTheRatiosOnTheWholePeriod(): void
    {
        $report = $this->aggregate([
            $this->project('2026-10-03T14:00:00+00:00', 120_000_000.0, 400_000_000.0, 100.0),
            $this->project('2026-11-12T09:30:00+00:00', 30_000_000.0, 100_000_000.0, 50.0, self::ISHTAR, 'Ishtar'),
            $this->project('2026-11-20T21:00:00+00:00', null, null, 40.0, self::HECATE, 'Hecate'),
        ]);

        self::assertSame([150_000_000.0, 3, 1, 500_000_000.0, 150.0, 1_000_000.0], $this->totalsSummary($report->totals));
        self::assertSame(
            [600_000.0, 1_200_000.0],
            array_map(static fn (IndustryRevenueMonth $month): ?float => $month->iskPerHour, $report->months),
        );
    }

    public function testNoCompletedProjectGivesZeroTotalsUnknownRatiosAndEmptyLists(): void
    {
        $report = $this->aggregate([]);

        self::assertSame([0.0, 0, 0, 0.0, null, null], $this->totalsSummary($report->totals));
        self::assertSame([], $report->months);
        self::assertSame([], $report->products);
    }

    public function testEachSingleProductProjectCountsOnItsProductLineOrderedByProfitThenName(): void
    {
        $report = $this->aggregate([
            $this->project('2026-11-03T14:00:00+00:00', 30_000_000.0, 100_000_000.0, 10.0, self::SABRE, 'Sabre'),
            $this->project('2026-11-04T14:00:00+00:00', 20_000_000.0, 100_000_000.0, 10.0, self::SABRE, 'Sabre'),
            $this->project('2026-11-05T14:00:00+00:00', null, null, 10.0, self::SABRE, 'Sabre'),
            $this->project('2026-11-06T14:00:00+00:00', 50_000_000.0, 100_000_000.0, 10.0, self::ISHTAR, 'Ishtar'),
            $this->project('2026-11-07T14:00:00+00:00', 7_000_000.0, 100_000_000.0, 10.0, self::HECATE, 'Hecate'),
        ]);

        self::assertSame(
            [
                [self::ISHTAR, 'Ishtar', 1, 0, 50_000_000.0],
                [self::SABRE, 'Sabre', 3, 1, 50_000_000.0],
                [self::HECATE, 'Hecate', 1, 0, 7_000_000.0],
            ],
            array_map($this->productSummary(...), $report->products),
        );
    }

    public function testProjectsWithSeveralProductsShareOneMultiProductLineWithTheirWholeProfit(): void
    {
        $report = $this->aggregate([
            $this->project('2026-11-03T14:00:00+00:00', 40_000_000.0, 100_000_000.0, 10.0, null, null, true),
            $this->project('2026-11-04T14:00:00+00:00', 30_000_000.0, 100_000_000.0, 10.0, null, null, true),
            $this->project('2026-11-05T14:00:00+00:00', 5_000_000.0, 100_000_000.0, 10.0, self::SABRE, 'Sabre'),
        ]);

        self::assertSame(
            [
                [null, null, 2, 0, 70_000_000.0],
                [self::SABRE, 'Sabre', 1, 0, 5_000_000.0],
            ],
            array_map($this->productSummary(...), $report->products),
        );
    }

    /**
     * @param list<CompletedProjectFigures> $figures
     */
    private function aggregate(array $figures): IndustryRevenueReport
    {
        return (new IndustryRevenueAggregator())->aggregate($figures);
    }

    private function project(
        string $completedAt,
        ?float $profit,
        ?float $capital,
        ?float $slotHours,
        ?int $productTypeId = self::SABRE,
        ?string $productTypeName = 'Sabre',
        bool $hasMultipleProducts = false,
    ): CompletedProjectFigures {
        return new CompletedProjectFigures(
            completedAt: new \DateTimeImmutable($completedAt),
            productTypeId: $productTypeId,
            productTypeName: $productTypeName,
            hasMultipleProducts: $hasMultipleProducts,
            profit: $profit,
            capital: $capital,
            slotHours: $slotHours,
        );
    }

    /**
     * @return array{float, int, int, float, ?float, ?float}
     */
    private function totalsSummary(IndustryRevenueTotals $totals): array
    {
        return [$totals->profit, $totals->projectCount, $totals->unknownProjectCount, $totals->capital, $totals->slotHours, $totals->iskPerHour];
    }

    /**
     * @return list<array{string, int, int, float, float, ?float, ?float, ?float}>
     */
    private function monthSummaries(IndustryRevenueReport $report): array
    {
        return array_map($this->monthSummary(...), $report->months);
    }

    /**
     * @return array{string, int, int, float, float, ?float, ?float, ?float}
     */
    private function monthSummary(IndustryRevenueMonth $month): array
    {
        return [
            $month->month,
            $month->projectCount,
            $month->unknownProjectCount,
            $month->profit,
            $month->capital,
            $month->marginPercent === null ? null : round($month->marginPercent, self::MARGIN_PRECISION),
            $month->slotHours,
            $month->iskPerHour,
        ];
    }

    /**
     * @return array{?int, ?string, int, int, float}
     */
    private function productSummary(IndustryRevenueProduct $product): array
    {
        return [$product->typeId, $product->typeName, $product->projectCount, $product->unknownProjectCount, $product->profit];
    }
}
