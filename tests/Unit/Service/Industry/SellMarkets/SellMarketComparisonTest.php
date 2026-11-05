<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\SellMarkets;

use App\Entity\User;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use App\Service\Industry\SellMarkets\ImmediateSale;
use App\Service\Industry\SellMarkets\SellMarketComparison;
use App\Service\Industry\SellMarkets\SellMarketComparisonResult;
use App\Service\Industry\SellMarkets\SellMarketRow;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\StructureTradedVolumeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-348')]
#[CoversClass(SellMarketComparison::class)]
#[CoversClass(SellMarketComparisonResult::class)]
#[CoversClass(SellMarketRow::class)]
#[CoversClass(ImmediateSale::class)]
final class SellMarketComparisonTest extends TestCase
{
    private const int HAIL_L_TYPE_ID = 12779;
    private const int ROBOTICS_TYPE_ID = 9848;
    private const int MORPHITE_TYPE_ID = 11399;
    private const int PREFERRED_MARKET_STRUCTURE_ID = 1035466617946;
    private const string PREFERRED_MARKET_STRUCTURE_NAME = 'Perimeter - Tranquility Trade Hub';
    private const float BROKER_FEE_RATE = 0.0138;
    private const float SALES_TAX_RATE = 0.03375;
    private const float DELTA = 0.001;

    public function testJitaRowIsPricedNetOfFeesAndSoldInDaysFromTheRegionVolume(): void
    {
        $result = $this->compare(50, jitaSell: $this->sellBook(760000.0), jitaVolumes: [self::HAIL_L_TYPE_ID => 20.0]);

        $jita = $this->row($result, 'jita');
        $this->assertSame(SellMarketComparison::JITA_MARKET_NAME, $jita->name);
        $this->assertSame(760000.0, $jita->unitPrice);
        $this->assertNull($jita->coverage);
        $this->assertSame(20.0, $jita->dailyVolume);
        $this->assertSame('region_history', $jita->volumeKind);
        $this->assertEqualsWithDelta(0.04755, $jita->feeRate, 0.0000001);
        $this->assertEqualsWithDelta(723862.0, $jita->netUnitPrice, 0.01);
        $this->assertEqualsWithDelta(25.0, $jita->daysToSell, self::DELTA);
    }

    public function testCapitalRowOfAStructureWithoutNameIsLabelledWithItsId(): void
    {
        $result = $this->compare(50, capitalPrice: 800000.0, capitalVolume: 2.0, capitalName: null);

        $this->assertSame('Structure ' . self::PREFERRED_MARKET_STRUCTURE_ID, $this->row($result, 'capital')->name);
    }

    public function testCapitalRowIsNamedAfterThePreferredStructureAndSoldInDaysFromTheEstimatedVolume(): void
    {
        $result = $this->compare(50, capitalPrice: 800000.0, capitalVolume: 2.0);

        $capital = $this->row($result, 'capital');
        $this->assertSame(self::PREFERRED_MARKET_STRUCTURE_NAME, $capital->name);
        $this->assertSame(800000.0, $capital->unitPrice);
        $this->assertNull($capital->coverage);
        $this->assertSame(2.0, $capital->dailyVolume);
        $this->assertSame('structure_estimate', $capital->volumeKind);
        $this->assertEqualsWithDelta(0.04755, $capital->feeRate, 0.0000001);
        $this->assertEqualsWithDelta(761960.0, $capital->netUnitPrice, 0.01);
        $this->assertEqualsWithDelta(250.0, $capital->daysToSell, self::DELTA);
    }

    public function testCapitalWithoutEstimatedVolumeKeepsItsPriceAndLeavesDaysUnknown(): void
    {
        $result = $this->compare(50, capitalPrice: 800000.0, capitalVolume: null);

        $capital = $this->row($result, 'capital');
        $this->assertSame(800000.0, $capital->unitPrice);
        $this->assertEqualsWithDelta(761960.0, $capital->netUnitPrice, 0.01);
        $this->assertNull($capital->dailyVolume);
        $this->assertNull($capital->daysToSell);
    }

    public function testJitaRegionVolumeOfZeroIsUnknownNotZero(): void
    {
        $result = $this->compare(50, jitaSell: $this->sellBook(760000.0), jitaVolumes: [self::HAIL_L_TYPE_ID => 0.0]);

        $jita = $this->row($result, 'jita');
        $this->assertSame(760000.0, $jita->unitPrice);
        $this->assertNull($jita->dailyVolume);
        $this->assertNull($jita->daysToSell);
    }

    public function testJitaRegionVolumeAbsentForTheTypeIsUnknownNotZero(): void
    {
        $result = $this->compare(50, jitaSell: $this->sellBook(760000.0), jitaVolumes: [self::ROBOTICS_TYPE_ID => 14.0]);

        $jita = $this->row($result, 'jita');
        $this->assertSame(760000.0, $jita->unitPrice);
        $this->assertNull($jita->dailyVolume);
        $this->assertNull($jita->daysToSell);
    }

    public function testUnresolvedSalesFeesLeaveFeesAndNetsUnknownButKeepPricesAndDays(): void
    {
        $result = $this->compare(
            50,
            jitaSell: $this->sellBook(760000.0),
            jitaBuy: $this->buyBook(694000.0),
            jitaVolumes: [self::HAIL_L_TYPE_ID => 20.0],
            capitalPrice: 800000.0,
            capitalVolume: 2.0,
            salesFees: null,
        );

        $jita = $this->row($result, 'jita');
        $capital = $this->row($result, 'capital');
        $this->assertSame(760000.0, $jita->unitPrice);
        $this->assertNull($jita->feeRate);
        $this->assertNull($jita->netUnitPrice);
        $this->assertEqualsWithDelta(25.0, $jita->daysToSell, self::DELTA);
        $this->assertSame(800000.0, $capital->unitPrice);
        $this->assertNull($capital->feeRate);
        $this->assertNull($capital->netUnitPrice);
        $this->assertEqualsWithDelta(250.0, $capital->daysToSell, self::DELTA);
        $this->assertSame(694000.0, $result->immediateSale->averageUnitPrice);
        $this->assertNull($result->immediateSale->netUnitPrice);
    }

    public function testPartialJitaSellBookKeepsTheWeightedPriceAndReportsItsCoverage(): void
    {
        $result = $this->compare(50, jitaSell: $this->sellBook(752500.0, 0.6));

        $jita = $this->row($result, 'jita');
        $this->assertSame(752500.0, $jita->unitPrice);
        $this->assertSame(0.6, $jita->coverage);
    }

    public function testJitaWithoutSellOrdersHasNoPriceNoNetButKeepsItsDays(): void
    {
        $result = $this->compare(50, jitaSell: null, jitaVolumes: [self::HAIL_L_TYPE_ID => 20.0]);

        $jita = $this->row($result, 'jita');
        $this->assertNull($jita->unitPrice);
        $this->assertNull($jita->coverage);
        $this->assertNull($jita->netUnitPrice);
        $this->assertEqualsWithDelta(0.04755, $jita->feeRate, 0.0000001);
        $this->assertEqualsWithDelta(25.0, $jita->daysToSell, self::DELTA);
    }

    public function testCapitalWithoutSellOrdersHasNoPriceNoNetButKeepsItsDays(): void
    {
        $result = $this->compare(50, capitalPrice: null, capitalVolume: 2.0);

        $capital = $this->row($result, 'capital');
        $this->assertNull($capital->unitPrice);
        $this->assertNull($capital->netUnitPrice);
        $this->assertEqualsWithDelta(250.0, $capital->daysToSell, self::DELTA);
    }

    public function testWithoutPreferredMarketStructureOnlyTheJitaRowIsListed(): void
    {
        $result = $this->compare(50, jitaSell: $this->sellBook(760000.0), withCapital: false);

        $this->assertSame(['jita'], array_map(static fn (SellMarketRow $row): string => $row->key, $result->markets));
        $this->assertSame(760000.0, $this->row($result, 'jita')->unitPrice);
    }

    public function testImmediateSaleAveragesTheBuyOrdersAndPaysTheSalesTaxOnly(): void
    {
        $result = $this->compare(50, jitaBuy: $this->buyBook(694000.0));

        $this->assertSame(694000.0, $result->immediateSale->averageUnitPrice);
        $this->assertNull($result->immediateSale->coverage);
        $this->assertEqualsWithDelta(670577.5, $result->immediateSale->netUnitPrice, 0.01);
    }

    public function testPartialBuyBookAveragesOnlyTheCoveredUnitsAndReportsItsCoverage(): void
    {
        $result = $this->compare(50, jitaBuy: $this->buyBook(705000.0, 0.5));

        $this->assertSame(705000.0, $result->immediateSale->averageUnitPrice);
        $this->assertSame(0.5, $result->immediateSale->coverage);
        $this->assertEqualsWithDelta(681206.25, $result->immediateSale->netUnitPrice, 0.01);
    }

    public function testEmptyBuyBookLeavesTheImmediateSaleUnknownWhileTheMarketsStayPriced(): void
    {
        $result = $this->compare(50, jitaSell: $this->sellBook(760000.0), jitaBuy: null);

        $this->assertSame(760000.0, $this->row($result, 'jita')->unitPrice);
        $this->assertNull($result->immediateSale->averageUnitPrice);
        $this->assertNull($result->immediateSale->coverage);
        $this->assertNull($result->immediateSale->netUnitPrice);
    }

    public function testSingleUnitIsNotRoundedUpWhenComputingDaysToSell(): void
    {
        $result = $this->compare(
            1,
            jitaSell: $this->sellBook(760000.0),
            jitaVolumes: [self::HAIL_L_TYPE_ID => 8.0],
            capitalPrice: 800000.0,
            capitalVolume: 4.0,
        );

        $this->assertEqualsWithDelta(1.25, $this->row($result, 'jita')->daysToSell, self::DELTA);
        $this->assertEqualsWithDelta(2.5, $this->row($result, 'capital')->daysToSell, self::DELTA);
    }

    public function testResultEchoesTheTypeAndTheQuantityNotTheRuns(): void
    {
        $result = $this->compare(
            2400,
            typeId: self::MORPHITE_TYPE_ID,
            jitaSell: $this->sellBook(15000.0),
            jitaVolumes: [self::MORPHITE_TYPE_ID => 600.0],
        );

        $this->assertSame(self::MORPHITE_TYPE_ID, $result->typeId);
        $this->assertSame(2400, $result->quantity);
        $this->assertEqualsWithDelta(40.0, $this->row($result, 'jita')->daysToSell, self::DELTA);
    }

    /**
     * @param array{weightedPrice: float, coverage: float, ordersUsed: int}|null $jitaSell
     * @param array{weightedPrice: float, coverage: float, ordersUsed: int}|null $jitaBuy
     * @param array<int, float> $jitaVolumes
     */
    private function compare(
        int $quantity,
        int $typeId = self::HAIL_L_TYPE_ID,
        ?array $jitaSell = null,
        ?array $jitaBuy = null,
        array $jitaVolumes = [],
        ?float $capitalPrice = null,
        ?float $capitalVolume = null,
        ?SalesFees $salesFees = new SalesFees(self::BROKER_FEE_RATE, self::SALES_TAX_RATE),
        bool $withCapital = true,
        ?string $capitalName = self::PREFERRED_MARKET_STRUCTURE_NAME,
    ): SellMarketComparisonResult {
        $today = new \DateTimeImmutable('2026-11-05');
        $typeQuantities = [$typeId => $quantity];

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getWeightedSellPricesWithFallback')->willReturnCallback(
            static fn (array $requested): array => $requested === $typeQuantities ? [$typeId => $jitaSell] : [],
        );
        $jitaMarketService->method('getWeightedBuyPricesWithFallback')->willReturnCallback(
            static fn (array $requested): array => $requested === $typeQuantities ? [$typeId => $jitaBuy] : [],
        );
        $jitaMarketService->method('getAverageDailyVolumes')->willReturnCallback(
            static fn (array $requested): array => $requested === [$typeId] ? $jitaVolumes : [],
        );

        $structureMarketService = $this->createStub(StructureMarketService::class);
        $structureMarketService->method('getLowestSellPrice')->willReturnCallback(
            static fn (int $structureId, int $requestedTypeId): ?float => $structureId === self::PREFERRED_MARKET_STRUCTURE_ID && $requestedTypeId === $typeId
                ? $capitalPrice
                : self::fail('Capital price read for another structure or type.'),
        );

        $structureTradedVolumeService = $this->createStub(StructureTradedVolumeService::class);
        $structureTradedVolumeService->method('getAverageDailyVolumes')->willReturnCallback(
            static fn (int $structureId, array $requested, \DateTimeImmutable $requestedToday): array => $structureId === self::PREFERRED_MARKET_STRUCTURE_ID && $requested === [$typeId] && $requestedToday == $today
                ? [$typeId => $capitalVolume]
                : self::fail('Capital volume read for another structure, type or day.'),
        );

        $user = new User();
        if ($withCapital) {
            $user->setPreferredMarketStructureId(self::PREFERRED_MARKET_STRUCTURE_ID);
            $user->setPreferredMarketStructureName($capitalName);
        }

        $salesFeesResolver = $this->createStub(SellerSalesFeesResolver::class);
        $salesFeesResolver->method('forUser')->willReturnCallback(
            static fn (User $seller): ?SalesFees => $seller === $user ? $salesFees : self::fail('Fees resolved for another user.'),
        );

        return (new SellMarketComparison($jitaMarketService, $structureMarketService, $structureTradedVolumeService, $salesFeesResolver))
            ->compare($user, $typeId, $quantity, $today);
    }

    /**
     * @return array{weightedPrice: float, coverage: float, ordersUsed: int}
     */
    private function sellBook(float $weightedPrice, float $coverage = 1.0): array
    {
        return ['weightedPrice' => $weightedPrice, 'coverage' => $coverage, 'ordersUsed' => 2];
    }

    /**
     * @return array{weightedPrice: float, coverage: float, ordersUsed: int}
     */
    private function buyBook(float $weightedPrice, float $coverage = 1.0): array
    {
        return ['weightedPrice' => $weightedPrice, 'coverage' => $coverage, 'ordersUsed' => 2];
    }

    private function row(SellMarketComparisonResult $result, string $key): SellMarketRow
    {
        foreach ($result->markets as $row) {
            if ($row->key === $key) {
                return $row;
            }
        }

        self::fail(sprintf('No "%s" row among the sell markets.', $key));
    }
}
