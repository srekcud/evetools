<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Enum\MiningHaulerPlanUnknownReason;
use App\Service\MiningOperation\MiningHaulerPlan;
use App\Service\MiningOperation\MiningHaulerPlanner;
use App\Service\MiningOperation\MiningHaulerTypeRate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningHaulerPlanner::class)]
#[CoversClass(MiningHaulerPlan::class)]
#[CoversClass(MiningHaulerTypeRate::class)]
#[Group('issue-312')]
final class MiningHaulerPlannerTest extends TestCase
{
    private const int VELDSPAR = 1230;
    private const int ZEOLITES = 45490;
    private const float ORCA_HANGAR_M3 = 40_000.0;
    private const float ORCA_HOLD_M3 = 150_000.0;
    private const float HALF_SHARE = 0.5;
    private const float DELTA = 0.000001;
    private const string NOW = '2026-11-10T21:00:00+00:00';

    private MiningHaulerPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new MiningHaulerPlanner();
    }

    public function testE1CompressibleTypeFillsTheHangarFromTheEmptyingAndNeedsOneTrip(): void
    {
        $plan = $this->orcaPlan([$this->compressibleVeldspar()], emptiedMinutesAgo: 4, siteEtaInMinutes: 60);

        self::assertSame(
            ['known' => true, 'reason' => null, 'isFull' => false, 'trips' => 1, 'rawTypeIds' => []],
            ['known' => $plan->isKnown(), 'reason' => $plan->unknownReason, 'isFull' => $plan->isFull, 'trips' => $plan->trips, 'rawTypeIds' => $plan->rawTypeIds],
        );
        self::assertEqualsWithDelta(
            ['inflow' => 5_000.0, 'fill' => 20_000.0, 'ratio' => 0.5, 'fullIn' => 4.0, 'transport' => 50.0, 'toMove' => 3_200.0],
            ['inflow' => $plan->inflowM3PerMinute, 'fill' => $plan->fillM3, 'ratio' => $plan->fillRatio, 'fullIn' => $plan->fullInMinutes, 'transport' => $plan->transportM3PerMinute, 'toMove' => $plan->toMoveM3],
            self::DELTA,
        );
    }

    public function testE2RawTypeIsTransportedAtItsRawVolumeAndNeedsThreeTrips(): void
    {
        $plan = $this->orcaPlan([new MiningHaulerTypeRate(self::VELDSPAR, 1_000.0, 10.0, 10.0, false)], emptiedMinutesAgo: 4, siteEtaInMinutes: 60);

        self::assertSame([3, [self::VELDSPAR]], [$plan->trips, $plan->rawTypeIds]);
        self::assertEqualsWithDelta(
            ['transport' => 5_000.0, 'toMove' => 320_000.0, 'fill' => 20_000.0],
            ['transport' => $plan->transportM3PerMinute, 'toMove' => $plan->toMoveM3, 'fill' => $plan->fillM3],
            self::DELTA,
        );
    }

    public function testE3HangarEmptiedLongAgoIsCappedAtItsCapacityAndIsFull(): void
    {
        $plan = $this->orcaPlan([$this->compressibleVeldspar()], emptiedMinutesAgo: 10, siteEtaInMinutes: 60);

        self::assertTrue($plan->isFull);
        self::assertEqualsWithDelta(
            ['fill' => 40_000.0, 'ratio' => 1.0, 'fullIn' => 0.0, 'toMove' => 3_500.0],
            ['fill' => $plan->fillM3, 'ratio' => $plan->fillRatio, 'fullIn' => $plan->fullInMinutes, 'toMove' => $plan->toMoveM3],
            self::DELTA,
        );
    }

    public function testE4UnknownSiteEtaLeavesTheBarKnownButHidesWhatToMoveAndTheTrips(): void
    {
        $plan = $this->orcaPlan([$this->compressibleVeldspar()], emptiedMinutesAgo: 4, siteEtaInMinutes: null);

        self::assertSame([true, null, null], [$plan->isKnown(), $plan->toMoveM3, $plan->trips]);
        self::assertEqualsWithDelta(['fill' => 20_000.0, 'fullIn' => 4.0], ['fill' => $plan->fillM3, 'fullIn' => $plan->fullInMinutes], self::DELTA);
    }

    public function testE5SiteEtaAlreadyPassedMovesOnlyWhatIsAlreadyInTheHangar(): void
    {
        $plan = $this->orcaPlan([$this->compressibleVeldspar()], emptiedMinutesAgo: 4, siteEtaInMinutes: -5);

        self::assertSame(1, $plan->trips);
        self::assertEqualsWithDelta(200.0, $plan->toMoveM3, self::DELTA);
    }

    public function testE6UnknownCompressionVerdictMakesThePlanUnknown(): void
    {
        $plan = $this->orcaPlan([new MiningHaulerTypeRate(self::VELDSPAR, 1_000.0, 10.0, 0.1, null)], emptiedMinutesAgo: 4, siteEtaInMinutes: 60);

        self::assertSame([false, MiningHaulerPlanUnknownReason::CompressionUnknown, null, null], [$plan->isKnown(), $plan->unknownReason, $plan->fillM3, $plan->trips]);
    }

    public function testE7NoTypeWithAFlowMakesTheSiteRateUnknown(): void
    {
        $plan = $this->orcaPlan(
            [new MiningHaulerTypeRate(self::VELDSPAR, 0.0, 10.0, 0.1, true), new MiningHaulerTypeRate(self::ZEOLITES, -3.5, 5.0, 5.0, false)],
            emptiedMinutesAgo: 4,
            siteEtaInMinutes: 60,
        );

        self::assertSame([false, MiningHaulerPlanUnknownReason::SiteRateUnknown, null], [$plan->isKnown(), $plan->unknownReason, $plan->inflowM3PerMinute]);
    }

    public function testTypesWithoutAFlowAreIgnoredWhileTheOthersFeedTheHangar(): void
    {
        $plan = $this->orcaPlan(
            [new MiningHaulerTypeRate(self::VELDSPAR, 200.0, 0.1, 0.001, true), new MiningHaulerTypeRate(self::ZEOLITES, 0.0, 5.0, 5.0, false)],
            emptiedMinutesAgo: 10,
            siteEtaInMinutes: 30,
        );

        self::assertSame([true, []], [$plan->isKnown(), $plan->rawTypeIds]);
        self::assertEqualsWithDelta(['inflow' => 10.0, 'fill' => 100.0, 'transport' => 0.1], ['inflow' => $plan->inflowM3PerMinute, 'fill' => $plan->fillM3, 'transport' => $plan->transportM3PerMinute], self::DELTA);
    }

    public function testEachTypeContributesItsOwnVolumeAndOnlyTheRawOnesAreListed(): void
    {
        $plan = $this->orcaPlan(
            [new MiningHaulerTypeRate(self::VELDSPAR, 200.0, 0.1, 0.001, true), new MiningHaulerTypeRate(self::ZEOLITES, 13.0, 5.0, 5.0, false)],
            emptiedMinutesAgo: 10,
            siteEtaInMinutes: 60,
        );

        self::assertSame([self::ZEOLITES], $plan->rawTypeIds);
        self::assertEqualsWithDelta(
            ['inflow' => 42.5, 'fill' => 425.0, 'transport' => 32.6, 'toMove' => 2_282.0],
            ['inflow' => $plan->inflowM3PerMinute, 'fill' => $plan->fillM3, 'transport' => $plan->transportM3PerMinute, 'toMove' => $plan->toMoveM3],
            self::DELTA,
        );
    }

    public function testOriginInTheFutureCountsNoElapsedTime(): void
    {
        $plan = $this->orcaPlan([$this->compressibleVeldspar()], emptiedMinutesAgo: -3, siteEtaInMinutes: 60);

        self::assertEqualsWithDelta(['fill' => 0.0, 'ratio' => 0.0, 'fullIn' => 8.0, 'toMove' => 3_000.0], ['fill' => $plan->fillM3, 'ratio' => $plan->fillRatio, 'fullIn' => $plan->fullInMinutes, 'toMove' => $plan->toMoveM3], self::DELTA);
    }

    public function testTripsNeverDropBelowOneEvenWhenNothingIsToMove(): void
    {
        $plan = $this->orcaPlan([$this->compressibleVeldspar()], emptiedMinutesAgo: 0, siteEtaInMinutes: 0);

        self::assertSame(1, $plan->trips);
        self::assertEqualsWithDelta(0.0, $plan->toMoveM3, self::DELTA);
    }

    public function testMissingHangarCapacityMakesThePlanUnknownBeforeAnyVolumeIsLookedAt(): void
    {
        $plan = $this->planner->plan(null, self::ORCA_HOLD_M3, self::HALF_SHARE, [new MiningHaulerTypeRate(self::VELDSPAR, 1_000.0, null, null, null)], $this->minutesAgo(4), $this->now(), $this->minutesFromNow(60));

        self::assertSame(MiningHaulerPlanUnknownReason::CapacityMissingFromSde, $plan->unknownReason);
    }

    public function testMissingHoldCapacityMakesThePlanUnknown(): void
    {
        $plan = $this->planner->plan(self::ORCA_HANGAR_M3, null, self::HALF_SHARE, [$this->compressibleVeldspar()], $this->minutesAgo(4), $this->now(), $this->minutesFromNow(60));

        self::assertSame(MiningHaulerPlanUnknownReason::CapacityMissingFromSde, $plan->unknownReason);
    }

    public function testMissingRawVolumeMakesThePlanUnknownBeforeTheCompressionVerdict(): void
    {
        $plan = $this->orcaPlan([new MiningHaulerTypeRate(self::VELDSPAR, 1_000.0, null, 0.1, null)], emptiedMinutesAgo: 4, siteEtaInMinutes: 60);

        self::assertSame(MiningHaulerPlanUnknownReason::VolumeMissing, $plan->unknownReason);
    }

    public function testMissingTransportedVolumeOfACompressibleTypeMakesThePlanUnknown(): void
    {
        $plan = $this->orcaPlan([new MiningHaulerTypeRate(self::VELDSPAR, 1_000.0, 10.0, null, true)], emptiedMinutesAgo: 4, siteEtaInMinutes: 60);

        self::assertSame(MiningHaulerPlanUnknownReason::VolumeMissing, $plan->unknownReason);
    }

    public function testUnknownPlanKeepsNoCalculatedFigure(): void
    {
        $plan = $this->orcaPlan([], emptiedMinutesAgo: 4, siteEtaInMinutes: 60);

        self::assertSame(
            [MiningHaulerPlanUnknownReason::SiteRateUnknown, null, null, null, null, null, null, null, null, []],
            [$plan->unknownReason, $plan->inflowM3PerMinute, $plan->fillM3, $plan->fillRatio, $plan->fullInMinutes, $plan->isFull, $plan->transportM3PerMinute, $plan->toMoveM3, $plan->trips, $plan->rawTypeIds],
        );
    }

    /**
     * @param list<MiningHaulerTypeRate> $rates
     */
    private function orcaPlan(array $rates, int $emptiedMinutesAgo, ?int $siteEtaInMinutes): MiningHaulerPlan
    {
        return $this->planner->plan(
            self::ORCA_HANGAR_M3,
            self::ORCA_HOLD_M3,
            self::HALF_SHARE,
            $rates,
            $this->minutesAgo($emptiedMinutesAgo),
            $this->now(),
            null === $siteEtaInMinutes ? null : $this->minutesFromNow($siteEtaInMinutes),
        );
    }

    private function compressibleVeldspar(): MiningHaulerTypeRate
    {
        return new MiningHaulerTypeRate(self::VELDSPAR, 1_000.0, 10.0, 0.1, true);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function minutesAgo(int $minutes): \DateTimeImmutable
    {
        return $this->now()->modify(sprintf('%+d minutes', -$minutes));
    }

    private function minutesFromNow(int $minutes): \DateTimeImmutable
    {
        return $this->now()->modify(sprintf('%+d minutes', $minutes));
    }
}
