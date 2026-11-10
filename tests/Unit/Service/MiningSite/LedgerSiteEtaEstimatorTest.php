<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningSite;

use App\Enum\MiningSiteLedgerEtaUnknownReason;
use App\Service\MiningSite\LedgerSiteEta;
use App\Service\MiningSite\LedgerSiteEtaEstimator;
use App\Service\MiningSite\SiteScanSample;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerSiteEtaEstimator::class)]
#[CoversClass(LedgerSiteEta::class)]
#[Group('issue-267')]
final class LedgerSiteEtaEstimatorTest extends TestCase
{
    private const int VELDSPAR = 1230;
    private const int SCORDITE = 1228;
    private const int TRITANIUM = 34;
    private const string STARTED_AT = '2026-11-10T20:00:00+00:00';
    private const string SCANNED_AT = '2026-11-10T20:10:00+00:00';
    private const string FRESH_AT = '2026-11-10T20:30:00+00:00';

    public function testOneTypeMinedByTheFleetGivesTheScanTimePlusTheRemainingUnitsOverTheLedgerRate(): void
    {
        $eta = $this->estimate([self::VELDSPAR => 60_000], [self::VELDSPAR => 12_000], self::FRESH_AT);

        self::assertSame(['2026-11-10T22:40:00+00:00', null], [$eta->eta?->format(\DateTimeInterface::ATOM), $eta->unknownReason]);
    }

    public function testTheEtaOfTheSiteIsTheLatestEtaOfTheTypesThatHaveARate(): void
    {
        $eta = $this->estimate(
            [self::VELDSPAR => 60_000, self::SCORDITE => 20_000],
            [self::VELDSPAR => 12_000, self::SCORDITE => 1_000],
            self::FRESH_AT,
        );

        self::assertSame('2026-11-11T06:10:00+00:00', $eta->eta?->format(\DateTimeInterface::ATOM));
    }

    public function testATypeOfTheScanThatNobodyMinedDoesNotPushTheEtaOfTheSite(): void
    {
        $eta = $this->estimate(
            [self::VELDSPAR => 60_000, self::SCORDITE => 20_000],
            [self::VELDSPAR => 12_000],
            self::FRESH_AT,
        );

        self::assertSame('2026-11-10T22:40:00+00:00', $eta->eta?->format(\DateTimeInterface::ATOM));
    }

    public function testAMinedTypeAbsentFromTheScanIsIgnored(): void
    {
        $eta = $this->estimate([self::VELDSPAR => 60_000], [self::VELDSPAR => 12_000, self::TRITANIUM => 90_000], self::FRESH_AT);

        self::assertSame('2026-11-10T22:40:00+00:00', $eta->eta?->format(\DateTimeInterface::ATOM));
    }

    public function testNothingMinedGivesNoEtaAndTheNothingMinedReason(): void
    {
        $eta = $this->estimate([self::VELDSPAR => 60_000], [], null);

        self::assertSame([null, MiningSiteLedgerEtaUnknownReason::NothingMined], [$eta->eta, $eta->unknownReason]);
    }

    public function testALedgerNotFresherThanTheStartGivesNoRateAndTheNothingMinedReason(): void
    {
        $eta = $this->estimate([self::VELDSPAR => 60_000], [self::VELDSPAR => 12_000], self::STARTED_AT);

        self::assertSame([null, MiningSiteLedgerEtaUnknownReason::NothingMined], [$eta->eta, $eta->unknownReason]);
    }

    /**
     * @param array<int, int> $units
     * @param array<int, int> $mined
     */
    private function estimate(array $units, array $mined, ?string $freshAt): LedgerSiteEta
    {
        return (new LedgerSiteEtaEstimator())->estimate(
            new SiteScanSample(new \DateTimeImmutable(self::SCANNED_AT), $units),
            $mined,
            new \DateTimeImmutable(self::STARTED_AT),
            null === $freshAt ? null : new \DateTimeImmutable($freshAt),
        );
    }
}
