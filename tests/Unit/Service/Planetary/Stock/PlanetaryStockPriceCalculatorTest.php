<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Planetary\Stock;

use App\Service\Planetary\Stock\PlanetaryStockPriceCalculator;
use App\Service\Planetary\Stock\PlanetaryStockPriceReading;
use App\Service\Planetary\Stock\PlanetaryStockPriceRow;
use App\Service\Planetary\Stock\PlanetaryStockPriceSignal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlanetaryStockPriceCalculator::class)]
#[Group('issue-1397')]
final class PlanetaryStockPriceCalculatorTest extends TestCase
{
    private const int BACTERIA = 2393;
    private const int COOLANT = 9832;
    private const int WATER = 3645;

    public function testRowKeepsTheReadingFiguresAndTheOrderOfTheReadings(): void
    {
        $rows = (new PlanetaryStockPriceCalculator())->calculate([
            $this->reading(self::WATER, typeName: 'Water', paidPrice: 41.5, hubSell: 44.0, jitaSell: 43.0, myBuyOrder: 40.0, hubBestBuy: 40.5),
            $this->reading(self::BACTERIA, typeName: 'Bacteria', paidPrice: 12.5, hubSell: 15.0, jitaSell: 14.0, myBuyOrder: 11.0, hubBestBuy: 11.5),
            $this->reading(self::COOLANT, typeName: 'Coolant'),
        ]);

        self::assertSame([self::WATER, self::BACTERIA, self::COOLANT], array_map(static fn (PlanetaryStockPriceRow $row): int => $row->typeId, $rows));
        self::assertSame('Water', $rows[0]->typeName);
        self::assertSame([41.5, 44.0, 43.0, 40.0, 40.5], [$rows[0]->paidPrice, $rows[0]->hubSell, $rows[0]->jitaSell, $rows[0]->myBuyOrder, $rows[0]->hubBestBuy]);
        self::assertSame([12.5, 15.0, 14.0, 11.0, 11.5], [$rows[1]->paidPrice, $rows[1]->hubSell, $rows[1]->jitaSell, $rows[1]->myBuyOrder, $rows[1]->hubBestBuy]);
        self::assertSame([null, null, null, null, null, null, null, null], [$rows[2]->paidPrice, $rows[2]->hubSell, $rows[2]->jitaSell, $rows[2]->myBuyOrder, $rows[2]->hubBestBuy, $rows[2]->paidVsHubPercent, $rows[2]->buyOrderVsHubPercent, $rows[2]->trend7dPercent]);
    }

    public function testNoReadingGivesNoRow(): void
    {
        self::assertSame([], (new PlanetaryStockPriceCalculator())->calculate([]));
    }

    public function testPaidPriceBelowTheHubSellIsANegativeGap(): void
    {
        $row = $this->rowOf($this->reading(self::BACTERIA, paidPrice: 100.0, hubSell: 125.0));

        self::assertSame(-20.0, $row->paidVsHubPercent);
    }

    public function testGapIsRoundedToOneDecimal(): void
    {
        $row = $this->rowOf($this->reading(self::COOLANT, paidPrice: 97.0, hubSell: 120.0));

        self::assertSame(-19.2, $row->paidVsHubPercent);
    }

    public function testPaidPriceAboveTheHubSellIsAPositiveGap(): void
    {
        $row = $this->rowOf($this->reading(self::WATER, paidPrice: 130.0, hubSell: 100.0));

        self::assertSame(30.0, $row->paidVsHubPercent);
    }

    public function testGapIsUnknownWhenTheHubSellIsZeroOrUnknown(): void
    {
        $zero = $this->rowOf($this->reading(self::BACTERIA, paidPrice: 100.0, hubSell: 0.0));
        $unknown = $this->rowOf($this->reading(self::COOLANT, paidPrice: 100.0, hubSell: null));

        self::assertNull($zero->paidVsHubPercent);
        self::assertNull($unknown->paidVsHubPercent);
    }

    public function testGapIsUnknownWhenNothingWasPaid(): void
    {
        $row = $this->rowOf($this->reading(self::WATER, paidPrice: null, hubSell: 100.0));

        self::assertNull($row->paidVsHubPercent);
    }

    public function testMyBuyOrderBelowTheHubSellIsANegativeGap(): void
    {
        $row = $this->rowOf($this->reading(self::BACTERIA, hubSell: 100.0, myBuyOrder: 90.0));

        self::assertSame(-10.0, $row->buyOrderVsHubPercent);
    }

    public function testBuyOrderGapIsUnknownWithoutOrderOrWithAHubSellOfZeroOrUnknown(): void
    {
        $withoutOrder = $this->rowOf($this->reading(self::BACTERIA, hubSell: 100.0, myBuyOrder: null));
        $zeroHub = $this->rowOf($this->reading(self::COOLANT, hubSell: 0.0, myBuyOrder: 90.0));
        $unknownHub = $this->rowOf($this->reading(self::WATER, hubSell: null, myBuyOrder: 90.0));

        self::assertNull($withoutOrder->buyOrderVsHubPercent);
        self::assertNull($zeroHub->buyOrderVsHubPercent);
        self::assertNull($unknownHub->buyOrderVsHubPercent);
    }

    public function testTrendIsTheChangeBetweenTheFirstAndTheLastSellMin(): void
    {
        $row = $this->rowOf($this->reading(self::BACTERIA, hubSellHistory: [100.0, 110.0]));

        self::assertSame(10.0, $row->trend7dPercent);
    }

    public function testTrendIsNegativeWhenThePriceFell(): void
    {
        $row = $this->rowOf($this->reading(self::COOLANT, hubSellHistory: [125.0, 123.0, 121.0]));

        self::assertSame(-3.2, $row->trend7dPercent);
    }

    public function testTrendIsRoundedToOneDecimal(): void
    {
        $row = $this->rowOf($this->reading(self::WATER, hubSellHistory: [90.0, 100.0]));

        self::assertSame(11.1, $row->trend7dPercent);
    }

    public function testTrendIsUnknownWithASingleSnapshotOrNone(): void
    {
        $single = $this->rowOf($this->reading(self::BACTERIA, hubSellHistory: [100.0]));
        $none = $this->rowOf($this->reading(self::COOLANT, hubSellHistory: []));

        self::assertNull($single->trend7dPercent);
        self::assertNull($none->trend7dPercent);
    }

    public function testTrendIgnoresSnapshotsWithoutSellMin(): void
    {
        $skipped = $this->rowOf($this->reading(self::WATER, hubSellHistory: [100.0, null, 110.0]));
        $onlyOneKnown = $this->rowOf($this->reading(self::BACTERIA, hubSellHistory: [100.0, null]));

        self::assertSame(10.0, $skipped->trend7dPercent);
        self::assertNull($onlyOneKnown->trend7dPercent);
    }

    public function testTrendIsUnknownWhenTheFirstSellMinIsZero(): void
    {
        $row = $this->rowOf($this->reading(self::COOLANT, hubSellHistory: [0.0, 110.0]));

        self::assertNull($row->trend7dPercent);
    }

    public function testBuyJitaWhenJitaSellsBelowThePaidPriceAndWinsOverTheHub(): void
    {
        $row = $this->rowOf($this->reading(self::BACTERIA, paidPrice: 100.0, hubSell: 98.0, jitaSell: 95.0));

        self::assertSame(PlanetaryStockPriceSignal::BuyJita, $row->signal);
    }

    public function testBuyHubWhenOnlyTheHubSellsBelowThePaidPrice(): void
    {
        $row = $this->rowOf($this->reading(self::COOLANT, paidPrice: 100.0, hubSell: 98.0, jitaSell: 105.0));

        self::assertSame(PlanetaryStockPriceSignal::BuyHub, $row->signal);
    }

    public function testNoSignalWhenNothingWasPaidWhateverThePrices(): void
    {
        $row = $this->rowOf($this->reading(self::WATER, paidPrice: null, hubSell: 5.0, jitaSell: 5.0));

        self::assertNull($row->signal);
    }

    public function testNoBuySignalWhenThePaidPriceEqualsTheSellPrices(): void
    {
        $row = $this->rowOf($this->reading(self::BACTERIA, paidPrice: 100.0, hubSell: 100.0, jitaSell: 100.0));

        self::assertNull($row->signal);
    }

    public function testNoBuyJitaSignalWhenTheJitaSellIsUnknown(): void
    {
        $row = $this->rowOf($this->reading(self::COOLANT, paidPrice: 100.0, hubSell: 120.0, jitaSell: null));

        self::assertNull($row->signal);
    }

    public function testOutbidWhenTheBestHubBuyOrderBeatsMine(): void
    {
        $row = $this->rowOf($this->reading(self::WATER, paidPrice: 100.0, hubSell: 120.0, myBuyOrder: 100.0, hubBestBuy: 105.0));

        self::assertSame(PlanetaryStockPriceSignal::Outbid, $row->signal);
    }

    public function testNoOutbidWhenTheBestHubBuyOrderIsMine(): void
    {
        $row = $this->rowOf($this->reading(self::BACTERIA, paidPrice: 100.0, hubSell: 120.0, myBuyOrder: 100.0, hubBestBuy: 100.0));

        self::assertNull($row->signal);
    }

    public function testNoOutbidWhenTheBestHubBuyOrderIsUnknown(): void
    {
        $row = $this->rowOf($this->reading(self::COOLANT, paidPrice: 100.0, hubSell: 120.0, myBuyOrder: 100.0, hubBestBuy: null));

        self::assertNull($row->signal);
    }

    public function testPlaceOrderWhenIHaveNoOrderAndTheHubSellFellOverTheWeek(): void
    {
        $row = $this->rowOf($this->reading(self::WATER, paidPrice: 100.0, hubSell: 120.0, hubSellHistory: [125.0, 121.0]));

        self::assertSame(-3.2, $row->trend7dPercent);
        self::assertSame(PlanetaryStockPriceSignal::PlaceOrder, $row->signal);
    }

    public function testNoPlaceOrderWhenTheTrendIsPositiveOrUnknown(): void
    {
        $rising = $this->rowOf($this->reading(self::BACTERIA, paidPrice: 100.0, hubSell: 120.0, hubSellHistory: [100.0, 101.0]));
        $unknown = $this->rowOf($this->reading(self::COOLANT, paidPrice: 100.0, hubSell: 120.0, hubSellHistory: []));

        self::assertSame(1.0, $rising->trend7dPercent);
        self::assertNull($rising->signal);
        self::assertNull($unknown->signal);
    }

    public function testNoPlaceOrderWhenIAlreadyHaveAnOrder(): void
    {
        $row = $this->rowOf($this->reading(self::WATER, paidPrice: 100.0, hubSell: 120.0, myBuyOrder: 100.0, hubBestBuy: 100.0, hubSellHistory: [125.0, 121.0]));

        self::assertNull($row->signal);
    }

    public function testBuyJitaWinsOverOutbidAndPlaceOrder(): void
    {
        $outbid = $this->rowOf($this->reading(self::BACTERIA, paidPrice: 100.0, hubSell: 120.0, jitaSell: 90.0, myBuyOrder: 80.0, hubBestBuy: 85.0));
        $place = $this->rowOf($this->reading(self::COOLANT, paidPrice: 100.0, hubSell: 120.0, jitaSell: 90.0, hubSellHistory: [125.0, 121.0]));

        self::assertSame(PlanetaryStockPriceSignal::BuyJita, $outbid->signal);
        self::assertSame(PlanetaryStockPriceSignal::BuyJita, $place->signal);
    }

    public function testBuyHubWinsOverOutbid(): void
    {
        $row = $this->rowOf($this->reading(self::WATER, paidPrice: 100.0, hubSell: 98.0, jitaSell: 105.0, myBuyOrder: 80.0, hubBestBuy: 85.0));

        self::assertSame(PlanetaryStockPriceSignal::BuyHub, $row->signal);
    }

    public function testOutbidWinsOverPlaceOrderBecausePlaceOrderNeedsNoOrder(): void
    {
        $row = $this->rowOf($this->reading(self::BACTERIA, paidPrice: 100.0, hubSell: 120.0, myBuyOrder: 100.0, hubBestBuy: 105.0, hubSellHistory: [125.0, 121.0]));

        self::assertSame(PlanetaryStockPriceSignal::Outbid, $row->signal);
    }

    /**
     * @param list<float|null> $hubSellHistory
     */
    private function reading(
        int $typeId,
        ?string $typeName = null,
        ?float $paidPrice = null,
        ?float $hubSell = null,
        ?float $jitaSell = null,
        ?float $myBuyOrder = null,
        ?float $hubBestBuy = null,
        array $hubSellHistory = [],
    ): PlanetaryStockPriceReading {
        return new PlanetaryStockPriceReading($typeId, $typeName, $paidPrice, $hubSell, $jitaSell, $myBuyOrder, $hubBestBuy, $hubSellHistory);
    }

    private function rowOf(PlanetaryStockPriceReading $reading): PlanetaryStockPriceRow
    {
        $rows = (new PlanetaryStockPriceCalculator())->calculate([$reading]);

        self::assertCount(1, $rows);

        return $rows[0];
    }
}
