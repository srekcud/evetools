<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningSite;

use App\Entity\MiningSiteScan;
use App\Service\MiningSite\MiningSiteHistorySummarizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningSiteHistorySummarizer::class)]
#[Group('issue-267')]
final class MiningSiteHistorySummarizerTest extends TestCase
{
    private const float VELDSPAR_VOLUME = 0.1;
    private const float VELDSPAR_UNIT_PRICE = 15.0;

    public function testSiteOfThreeVeldsparScansGivesObservedDurationFirstScanVolumeAndValueAndMeanTrendRate(): void
    {
        $first = $this->scanAt('20:00:00', 30_000);
        $last = $this->scanAt('20:20:00', 18_000);

        $summary = (new MiningSiteHistorySummarizer())->summarize($first, $last);

        self::assertSame(
            [1_200, 3_000.0, 450_000.0, 600.0],
            [$summary->observedSeconds, $summary->firstVolumeM3, $summary->firstValue, $summary->averageTrendRate],
        );
    }

    public function testSiteWithASingleScanHasNoObservedDurationAndNoMeanTrendRate(): void
    {
        $only = $this->scanAt('20:00:00', 30_000);

        $summary = (new MiningSiteHistorySummarizer())->summarize($only, $only);

        self::assertSame(
            [0, 3_000.0, 450_000.0, null],
            [$summary->observedSeconds, $summary->firstVolumeM3, $summary->firstValue, $summary->averageTrendRate],
        );
    }

    public function testFirstScanWithUnknownVolumeAndValueKeepsThemUnknownInsteadOfZero(): void
    {
        $first = $this->scanAt('20:00:00', 30_000)->setTotalVolumeM3(null)->setRemainingValue(null);
        $last = $this->scanAt('20:20:00', 18_000);

        $summary = (new MiningSiteHistorySummarizer())->summarize($first, $last);

        self::assertSame(
            [1_200, null, null, 600.0],
            [$summary->observedSeconds, $summary->firstVolumeM3, $summary->firstValue, $summary->averageTrendRate],
        );
    }

    #[Group('issue-305')]
    public function testSiteOfTwoScansExtractedValueIsTheFirstValueMinusTheRemainingValue(): void
    {
        $first = $this->scanAt('20:00:00', 30_000)->setRemainingValue(1_000_000.0);
        $last = $this->scanAt('20:20:00', 18_000)->setRemainingValue(400_000.0);

        $summary = (new MiningSiteHistorySummarizer())->summarize($first, $last);

        self::assertSame(600_000.0, $summary->extractedValue);
    }

    #[Group('issue-305')]
    public function testSiteWithASingleScanHasAnUnknownExtractedValueInsteadOfZero(): void
    {
        $only = $this->scanAt('20:00:00', 30_000);

        $summary = (new MiningSiteHistorySummarizer())->summarize($only, $only);

        self::assertNull($summary->extractedValue);
    }

    #[Group('issue-305')]
    public function testUnknownRemainingValueOnTheLastScanLeavesTheExtractedValueUnknown(): void
    {
        $first = $this->scanAt('20:00:00', 30_000);
        $last = $this->scanAt('20:20:00', 18_000)->setRemainingValue(null);

        $summary = (new MiningSiteHistorySummarizer())->summarize($first, $last);

        self::assertNull($summary->extractedValue);
    }

    #[Group('issue-305')]
    public function testUnknownValueOnTheFirstScanLeavesTheExtractedValueUnknown(): void
    {
        $first = $this->scanAt('20:00:00', 30_000)->setRemainingValue(null);
        $last = $this->scanAt('20:20:00', 18_000);

        $summary = (new MiningSiteHistorySummarizer())->summarize($first, $last);

        self::assertNull($summary->extractedValue);
    }

    #[Group('issue-305')]
    public function testExtractedValueIsNegativeWhenPricesRiseBetweenTheTwoScans(): void
    {
        $first = $this->scanAt('20:00:00', 30_000)->setRemainingValue(450_000.0);
        $last = $this->scanAt('20:20:00', 29_000)->setRemainingValue(500_000.0);

        $summary = (new MiningSiteHistorySummarizer())->summarize($first, $last);

        self::assertSame(-50_000.0, $summary->extractedValue);
    }

    private function scanAt(string $time, int $units): MiningSiteScan
    {
        return (new MiningSiteScan())
            ->setScannedAt(new \DateTimeImmutable('2026-11-05T'.$time.'+00:00'))
            ->setTotalUnits($units)
            ->setTotalVolumeM3($units * self::VELDSPAR_VOLUME)
            ->setRemainingValue($units * self::VELDSPAR_UNIT_PRICE);
    }
}
