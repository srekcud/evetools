<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningSite;

use App\Service\MiningSite\SiteDepletionEstimator;
use App\Service\MiningSite\SiteEstimate;
use App\Service\MiningSite\SiteScanSample;
use App\Service\MiningSite\SiteTypeEstimate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SiteDepletionEstimator::class)]
#[Group('issue-267')]
final class SiteDepletionEstimatorTest extends TestCase
{
    private const int VELDSPAR = 1230;
    private const int SCORDITE = 1228;
    private const float VELDSPAR_UNIT_PRICE = 15.0;
    private const float SCORDITE_UNIT_PRICE = 25.0;
    private const float VELDSPAR_VOLUME = 0.1;
    private const float SCORDITE_VOLUME = 0.15;

    public function testTwoScansGiveTheSameRecentAndTrendRateAndEtaAndTheRemainingValue(): void
    {
        $estimate = $this->estimate([
            $this->scan('20:00:00', [self::VELDSPAR => 60_000]),
            $this->scan('20:30:00', [self::VELDSPAR => 42_000]),
        ]);

        $veldspar = $estimate->types[self::VELDSPAR];
        self::assertSame(
            [[600.0, '21:40:00', 600.0, '21:40:00', null], [null, null], 42_000, 630_000.0, ['21:40:00', '21:40:00', 630_000.0, [], []]],
            [$this->ratesAndEtasOf($veldspar), $this->deviationOf($veldspar), $veldspar->units, $this->rounded($veldspar->remainingValue), $this->siteOf($estimate)],
        );
    }

    public function testThreeScansWithARegularRateGiveRecentAndTrendEtasAndAMaximalDeviationBelowTenPercent(): void
    {
        $estimate = $this->estimate([
            $this->scan('20:00:00', [self::VELDSPAR => 30_000]),
            $this->scan('20:10:00', [self::VELDSPAR => 25_000]),
            $this->scan('20:20:00', [self::VELDSPAR => 18_000]),
        ]);

        $veldspar = $estimate->types[self::VELDSPAR];
        self::assertSame(
            [[700.0, '20:45:43', 600.0, '20:50:33', null], [666.67, 2.22], false],
            [$this->ratesAndEtasOf($veldspar), $this->deviationOf($veldspar), $veldspar->irregular],
        );
    }

    public function testThreeScansWithAnIrregularRateFlagTheDeviationAboveTenPercentAndKeepBothEtas(): void
    {
        $estimate = $this->estimate([
            $this->scan('20:00:00', [self::VELDSPAR => 30_000]),
            $this->scan('20:10:00', [self::VELDSPAR => 29_500]),
            $this->scan('20:20:00', [self::VELDSPAR => 18_000]),
        ]);

        $veldspar = $estimate->types[self::VELDSPAR];
        self::assertSame(
            [[1_150.0, '20:35:39', 600.0, '20:53:03', null], [3_666.67, 12.22], true],
            [$this->ratesAndEtasOf($veldspar), $this->deviationOf($veldspar), $veldspar->irregular],
        );
    }

    public function testStableQuantityHasUnknownEtasBecauseItIsNotDepletingAndIsListedApart(): void
    {
        $estimate = $this->estimate([
            $this->scan('20:00:00', [self::SCORDITE => 5_000]),
            $this->scan('20:30:00', [self::SCORDITE => 5_000]),
        ]);

        self::assertSame(
            [[0.0, null, 0.0, null, 'not_depleting'], [null, null, 125_000.0, [self::SCORDITE], []]],
            [$this->ratesAndEtasOf($estimate->types[self::SCORDITE]), $this->siteOf($estimate)],
        );
    }

    public function testQuantityThatIncreasesHasANegativeRateAndUnknownEtasBecauseItIsNotDepleting(): void
    {
        $estimate = $this->estimate([
            $this->scan('20:00:00', [self::SCORDITE => 5_000]),
            $this->scan('20:30:00', [self::SCORDITE => 6_000]),
        ]);

        self::assertSame(
            [-33.33, null, -33.33, null, 'not_depleting'],
            $this->ratesAndEtasOf($estimate->types[self::SCORDITE]),
        );
    }

    public function testRecentRateAtZeroWithAPositiveTrendHasAnUnknownRecentEtaAndAKnownTrendEta(): void
    {
        $estimate = $this->estimate([
            $this->scan('20:00:00', [self::SCORDITE => 9_000]),
            $this->scan('20:10:00', [self::SCORDITE => 6_000]),
            $this->scan('20:20:00', [self::SCORDITE => 6_000]),
        ]);

        self::assertSame(
            [[0.0, null, 150.0, '20:56:40', 'not_depleting'], [null, '20:56:40']],
            [$this->ratesAndEtasOf($estimate->types[self::SCORDITE]), array_slice($this->siteOf($estimate), 0, 2)],
        );
    }

    public function testTypeAbsentFromTheLastScanIsDepletedBetweenTheTwoScansWithNoRateNorEta(): void
    {
        $estimate = $this->estimate([
            $this->scan('20:00:00', [self::SCORDITE => 2_000]),
            $this->scan('20:30:00', []),
        ]);

        $scordite = $estimate->types[self::SCORDITE];
        self::assertSame(
            [[null, null, null, null, null], 0, 0.0, ['20:00:00', '20:30:00'], [null, null, 0.0, [], []], true],
            [
                $this->ratesAndEtasOf($scordite),
                $scordite->units,
                $this->rounded($scordite->remainingValue),
                [$this->timeOf($scordite->lastPresentAt), $this->timeOf($scordite->firstAbsentAt)],
                $this->siteOf($estimate),
                $estimate->allDepleted,
            ],
        );
    }

    public function testDepletedTypeDoesNotHoldBackTheSiteEtaOfTheTypesStillMined(): void
    {
        $estimate = $this->estimate([
            $this->scan('20:00:00', [self::VELDSPAR => 60_000, self::SCORDITE => 2_000]),
            $this->scan('20:30:00', [self::VELDSPAR => 42_000]),
        ]);

        self::assertSame(
            [['21:40:00', '21:40:00', 630_000.0, [], []], false, '20:30:00'],
            [$this->siteOf($estimate), $estimate->allDepleted, $this->timeOf($estimate->types[self::SCORDITE]->firstAbsentAt)],
        );
    }

    public function testSiteEtaIsTheLatestEtaOfTheTypesBeingMined(): void
    {
        $estimate = $this->estimate([
            $this->scan('20:00:00', [self::VELDSPAR => 60_000, self::SCORDITE => 40_000]),
            $this->scan('20:30:00', [self::VELDSPAR => 42_000, self::SCORDITE => 31_000]),
        ]);

        self::assertSame(
            [[300.0, '22:13:20', 300.0, '22:13:20', null], ['22:13:20', '22:13:20', 1_405_000.0, [], []]],
            [$this->ratesAndEtasOf($estimate->types[self::SCORDITE]), $this->siteOf($estimate)],
        );
    }

    public function testNotDepletingTypeIsListedApartAndDoesNotPushBackTheSiteEta(): void
    {
        $estimate = $this->estimate([
            $this->scan('20:00:00', [self::VELDSPAR => 60_000, self::SCORDITE => 5_000]),
            $this->scan('20:30:00', [self::VELDSPAR => 42_000, self::SCORDITE => 5_000]),
        ]);

        self::assertSame(['21:40:00', '21:40:00', 755_000.0, [self::SCORDITE], []], $this->siteOf($estimate));
    }

    public function testTypeWithoutPriceKeepsItsEtaButMakesTheSiteValueUnknownAndListsItAsMissingPrice(): void
    {
        $estimate = $this->estimate(
            [
                $this->scan('20:00:00', [self::VELDSPAR => 60_000]),
                $this->scan('20:30:00', [self::VELDSPAR => 42_000]),
            ],
            [self::VELDSPAR => null],
        );

        self::assertSame(
            [['21:40:00', '21:40:00', null, [], [self::VELDSPAR]], null, null],
            [$this->siteOf($estimate), $estimate->types[self::VELDSPAR]->remainingValue, $estimate->mostProfitableTypeId],
        );
    }

    public function testSingleScanHasUnknownEtasForASingleScanButShowsTheRemainingUnitsAndValue(): void
    {
        $estimate = $this->estimate([$this->scan('20:00:00', [self::VELDSPAR => 60_000])]);

        $veldspar = $estimate->types[self::VELDSPAR];
        self::assertSame(
            [[null, null, null, null, 'single_scan'], 60_000, 900_000.0, [null, null, 900_000.0, [], []]],
            [$this->ratesAndEtasOf($veldspar), $veldspar->units, $this->rounded($veldspar->remainingValue), $this->siteOf($estimate)],
        );
    }

    public function testTypeThatAppearsAtTheSecondScanHasUnknownEtasForASingleScan(): void
    {
        $estimate = $this->estimate([
            $this->scan('20:00:00', [self::VELDSPAR => 60_000]),
            $this->scan('20:30:00', [self::VELDSPAR => 42_000, self::SCORDITE => 8_000]),
        ]);

        self::assertSame(
            [[null, null, null, null, 'single_scan'], 8_000, ['21:40:00', '21:40:00', 830_000.0, [], []]],
            [$this->ratesAndEtasOf($estimate->types[self::SCORDITE]), $estimate->types[self::SCORDITE]->units, $this->siteOf($estimate)],
        );
    }

    public function testTypeAbsentFromAMiddleScanButPresentAtTheLastOneUsesOnlyTheScansWhereItIsPresent(): void
    {
        $estimate = $this->estimate([
            $this->scan('20:00:00', [self::VELDSPAR => 30_000, self::SCORDITE => 5_000]),
            $this->scan('20:10:00', [self::SCORDITE => 4_000]),
            $this->scan('20:20:00', [self::VELDSPAR => 24_000, self::SCORDITE => 3_000]),
        ]);

        $veldspar = $estimate->types[self::VELDSPAR];
        self::assertSame(
            [[300.0, '21:40:00', 300.0, '21:40:00', null], null],
            [$this->ratesAndEtasOf($veldspar), $veldspar->firstAbsentAt],
        );
    }

    public function testEstimateGrowsWithEachScanFromSingleScanToTheRegularRateEtas(): void
    {
        $scans = [
            $this->scan('20:00:00', [self::VELDSPAR => 30_000]),
            $this->scan('20:10:00', [self::VELDSPAR => 25_000]),
            $this->scan('20:20:00', [self::VELDSPAR => 18_000]),
        ];

        self::assertSame(
            [
                [null, null, null, null, 'single_scan'],
                [500.0, '21:00:00', 500.0, '21:00:00', null],
                [700.0, '20:45:43', 600.0, '20:50:33', null],
            ],
            array_map(fn (int $scanCount): array => $this->ratesAndEtasOf($this->estimate(array_slice($scans, 0, $scanCount))->types[self::VELDSPAR]), [1, 2, 3]),
        );
    }

    public function testMostProfitableTypeIsTheOneWithTheHighestValuePerCubicMeter(): void
    {
        $estimate = $this->estimate([
            $this->scan('20:00:00', [self::VELDSPAR => 60_000, self::SCORDITE => 40_000]),
            $this->scan('20:30:00', [self::VELDSPAR => 42_000, self::SCORDITE => 31_000]),
        ]);

        self::assertSame(self::SCORDITE, $estimate->mostProfitableTypeId);
    }

    public function testTypeWithAnUnknownVolumeIsLeftOutOfTheMostProfitableType(): void
    {
        $estimate = $this->estimate(
            [
                $this->scan('20:00:00', [self::VELDSPAR => 60_000, self::SCORDITE => 40_000]),
                $this->scan('20:30:00', [self::VELDSPAR => 42_000, self::SCORDITE => 31_000]),
            ],
            volumes: [self::VELDSPAR => self::VELDSPAR_VOLUME, self::SCORDITE => null],
        );

        self::assertSame([self::VELDSPAR, null], [$estimate->mostProfitableTypeId, $estimate->types[self::SCORDITE]->volumeM3]);
    }

    private function estimate(array $scans, ?array $unitPrices = null, ?array $volumes = null): SiteEstimate
    {
        return (new SiteDepletionEstimator())->estimate(
            $scans,
            $unitPrices ?? [self::VELDSPAR => self::VELDSPAR_UNIT_PRICE, self::SCORDITE => self::SCORDITE_UNIT_PRICE],
            $volumes ?? [self::VELDSPAR => self::VELDSPAR_VOLUME, self::SCORDITE => self::SCORDITE_VOLUME],
        );
    }

    private function scan(string $time, array $unitsByTypeId): SiteScanSample
    {
        return new SiteScanSample(new \DateTimeImmutable('2026-11-05T'.$time.'+00:00'), $unitsByTypeId);
    }

    private function ratesAndEtasOf(SiteTypeEstimate $type): array
    {
        return [
            $this->rounded($type->recentRate),
            $this->timeOf($type->recentEta),
            $this->rounded($type->trendRate),
            $this->timeOf($type->trendEta),
            $type->etaUnknownReason?->value,
        ];
    }

    private function deviationOf(SiteTypeEstimate $type): array
    {
        return [$this->rounded($type->maxDeviationUnits), $this->rounded($type->maxDeviationPercent)];
    }

    private function siteOf(SiteEstimate $estimate): array
    {
        return [
            $this->timeOf($estimate->recentEta),
            $this->timeOf($estimate->trendEta),
            $this->rounded($estimate->remainingValue),
            $estimate->notDepletingTypeIds,
            $estimate->missingPriceTypeIds,
        ];
    }

    private function rounded(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }

    private function timeOf(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new \DateTimeZone('UTC'))->format('H:i:s');
    }
}
