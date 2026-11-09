<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\SellMarkets;

use App\Entity\User;
use App\Service\Industry\Project\SellStructure;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use App\Service\Industry\SellMarkets\SellMarketComparison;
use App\Service\Industry\SellMarkets\SellMarketComparisonResult;
use App\Service\Industry\SellMarkets\SellMarketRow;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\StructureTradedVolumeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('issue-50')]
#[CoversClass(SellMarketComparison::class)]
final class SellMarketComparisonAtTest extends TestCase
{
    private const int SABRE_TYPE_ID = 22456;
    private const int PREFERRED_STRUCTURE_ID = 1_035_466_617_946;
    private const string PREFERRED_STRUCTURE_NAME = 'Perimeter - Tranquility Trade Hub';
    private const int PROJECT_STRUCTURE_ID = 1_040_000_000_001;
    private const string PROJECT_STRUCTURE_NAME = 'Ishtar Citadel';
    private const float PROJECT_STRUCTURE_PRICE = 1_300_000.0;
    private const float PREFERRED_STRUCTURE_PRICE = 1_280_000.0;
    private const float JITA_PRICE = 1_250_000.0;
    private const float BROKER_FEE_RATE = 0.0138;
    private const float SALES_TAX_RATE = 0.03375;

    public function testCapitalRowIsTheGivenStructureWhateverTheSellerPrefers(): void
    {
        $result = $this->compareAt(new SellStructure(self::PROJECT_STRUCTURE_ID, self::PROJECT_STRUCTURE_NAME), 3);

        $capital = $this->row($result, 'capital');
        self::assertSame(self::PROJECT_STRUCTURE_NAME, $capital->name);
        self::assertSame(self::PROJECT_STRUCTURE_PRICE, $capital->unitPrice);
        self::assertNull($capital->coverage);
        self::assertSame(4.0, $capital->dailyVolume);
        self::assertSame('structure_estimate', $capital->volumeKind);
        self::assertEqualsWithDelta(0.04755, $capital->feeRate, 0.0000001);
        self::assertEqualsWithDelta(1_238_185.0, $capital->netUnitPrice, 0.01);
        self::assertEqualsWithDelta(7.5, $capital->daysToSell, 0.001);
    }

    public function testJitaRowStaysListedBesideTheGivenStructure(): void
    {
        $result = $this->compareAt(new SellStructure(self::PROJECT_STRUCTURE_ID, self::PROJECT_STRUCTURE_NAME), 3);

        self::assertSame(['jita', 'capital'], array_map(static fn (SellMarketRow $row): string => $row->key, $result->markets));
        self::assertSame(self::JITA_PRICE, $this->row($result, 'jita')->unitPrice);
        self::assertSame(self::SABRE_TYPE_ID, $result->typeId);
        self::assertSame(3, $result->quantity);
    }

    public function testWithoutStructureOnlyTheJitaRowIsListedEvenWhenTheSellerHasAPreferredStructure(): void
    {
        $result = $this->compareAt(null, 3);

        self::assertSame(['jita'], array_map(static fn (SellMarketRow $row): string => $row->key, $result->markets));
        self::assertSame(self::JITA_PRICE, $this->row($result, 'jita')->unitPrice);
    }

    public function testFeesAreThoseOfTheSellerPassed(): void
    {
        $result = $this->compareAt(new SellStructure(self::PROJECT_STRUCTURE_ID, self::PROJECT_STRUCTURE_NAME), 3, new SalesFees(0.02, 0.05));

        self::assertEqualsWithDelta(0.07, $this->row($result, 'capital')->feeRate, 0.0000001);
        self::assertEqualsWithDelta(1_209_000.0, $this->row($result, 'capital')->netUnitPrice, 0.01);
        self::assertEqualsWithDelta(0.07, $this->row($result, 'jita')->feeRate, 0.0000001);
        self::assertEqualsWithDelta(1_162_500.0, $this->row($result, 'jita')->netUnitPrice, 0.01);
    }

    public function testUnresolvedFeesOfTheSellerLeaveTheNetsUnknownButKeepThePrices(): void
    {
        $result = $this->compareAt(new SellStructure(self::PROJECT_STRUCTURE_ID, self::PROJECT_STRUCTURE_NAME), 3, null);

        self::assertSame(self::PROJECT_STRUCTURE_PRICE, $this->row($result, 'capital')->unitPrice);
        self::assertNull($this->row($result, 'capital')->feeRate);
        self::assertNull($this->row($result, 'capital')->netUnitPrice);
        self::assertSame(self::JITA_PRICE, $this->row($result, 'jita')->unitPrice);
        self::assertNull($this->row($result, 'jita')->netUnitPrice);
    }

    public function testGivenStructureWithoutSellOrdersHasNoPrice(): void
    {
        $result = $this->compareAt(new SellStructure(self::PROJECT_STRUCTURE_ID, self::PROJECT_STRUCTURE_NAME), 3, projectStructurePrice: null);

        self::assertNull($this->row($result, 'capital')->unitPrice);
        self::assertNull($this->row($result, 'capital')->netUnitPrice);
        self::assertEqualsWithDelta(7.5, $this->row($result, 'capital')->daysToSell, 0.001);
    }

    public function testCompareStillListsThePreferredStructureNamedAfterTheUser(): void
    {
        $result = $this->compare(3, self::PREFERRED_STRUCTURE_NAME);

        $capital = $this->row($result, 'capital');
        self::assertSame(self::PREFERRED_STRUCTURE_NAME, $capital->name);
        self::assertSame(self::PREFERRED_STRUCTURE_PRICE, $capital->unitPrice);
        self::assertEqualsWithDelta(1_219_136.0, $capital->netUnitPrice, 0.01);
        self::assertEqualsWithDelta(15.0, $capital->daysToSell, 0.001);
    }

    public function testCompareLabelsAPreferredStructureWithoutNameWithItsId(): void
    {
        $result = $this->compare(3, null);

        self::assertSame('Structure '.self::PREFERRED_STRUCTURE_ID, $this->row($result, 'capital')->name);
    }

    public function testCompareEqualsCompareAtTheDefaultStructureOfTheUser(): void
    {
        $viaCompare = $this->compare(3, self::PREFERRED_STRUCTURE_NAME);
        $viaCompareAt = $this->compareAt(new SellStructure(self::PREFERRED_STRUCTURE_ID, self::PREFERRED_STRUCTURE_NAME), 3);

        self::assertEquals($viaCompare, $viaCompareAt);
    }

    private function compareAt(
        ?SellStructure $structure,
        int $quantity,
        ?SalesFees $salesFees = new SalesFees(self::BROKER_FEE_RATE, self::SALES_TAX_RATE),
        ?float $projectStructurePrice = self::PROJECT_STRUCTURE_PRICE,
    ): SellMarketComparisonResult {
        $seller = $this->sellerPreferring(self::PREFERRED_STRUCTURE_NAME);

        return $this->comparison($seller, $quantity, $salesFees, $projectStructurePrice)->compareAt($seller, $structure, self::SABRE_TYPE_ID, $quantity, new \DateTimeImmutable('2026-11-09'));
    }

    private function compare(int $quantity, ?string $preferredStructureName): SellMarketComparisonResult
    {
        $seller = $this->sellerPreferring($preferredStructureName);

        return $this->comparison($seller, $quantity, new SalesFees(self::BROKER_FEE_RATE, self::SALES_TAX_RATE), self::PROJECT_STRUCTURE_PRICE)->compare($seller, self::SABRE_TYPE_ID, $quantity, new \DateTimeImmutable('2026-11-09'));
    }

    private function sellerPreferring(?string $preferredStructureName): User
    {
        $seller = new User();
        $seller->setPreferredMarketStructureId(self::PREFERRED_STRUCTURE_ID);
        $seller->setPreferredMarketStructureName($preferredStructureName);

        return $seller;
    }

    private function comparison(User $seller, int $quantity, ?SalesFees $salesFees, ?float $projectStructurePrice): SellMarketComparison
    {
        $typeQuantities = [self::SABRE_TYPE_ID => $quantity];

        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getWeightedSellPricesWithFallback')->willReturnCallback(
            static fn (array $requested): array => $requested === $typeQuantities
                ? [self::SABRE_TYPE_ID => ['weightedPrice' => self::JITA_PRICE, 'coverage' => 1.0, 'ordersUsed' => 2]]
                : [],
        );
        $jitaMarketService->method('getWeightedBuyPricesWithFallback')->willReturn([self::SABRE_TYPE_ID => null]);
        $jitaMarketService->method('getAverageDailyVolumes')->willReturn([]);

        $structureMarketService = $this->createStub(StructureMarketService::class);
        $structureMarketService->method('getLowestSellPrice')->willReturnCallback(
            static fn (int $structureId, int $typeId): ?float => match (true) {
                self::SABRE_TYPE_ID !== $typeId => self::fail('Sell price read for another type.'),
                self::PROJECT_STRUCTURE_ID === $structureId => $projectStructurePrice,
                self::PREFERRED_STRUCTURE_ID === $structureId => self::PREFERRED_STRUCTURE_PRICE,
                default => self::fail('Sell price read for an unexpected structure.'),
            },
        );

        $structureTradedVolumeService = $this->createStub(StructureTradedVolumeService::class);
        $structureTradedVolumeService->method('getAverageDailyVolumes')->willReturnCallback(
            static fn (int $structureId, array $typeIds): array => match ($structureId) {
                self::PROJECT_STRUCTURE_ID => [self::SABRE_TYPE_ID => 4.0],
                self::PREFERRED_STRUCTURE_ID => [self::SABRE_TYPE_ID => 2.0],
                default => self::fail('Traded volume read for an unexpected structure.'),
            },
        );

        $salesFeesResolver = $this->createStub(SellerSalesFeesResolver::class);
        $salesFeesResolver->method('forUser')->willReturnCallback(
            static fn (User $user): ?SalesFees => $user === $seller ? $salesFees : self::fail('Fees resolved for another user.'),
        );

        return new SellMarketComparison($jitaMarketService, $structureMarketService, $structureTradedVolumeService, $salesFeesResolver);
    }

    private function row(SellMarketComparisonResult $result, string $key): SellMarketRow
    {
        foreach ($result->markets as $row) {
            if ($row->key === $key) {
                return $row;
            }
        }

        self::fail(\sprintf('No "%s" row among the sell markets.', $key));
    }
}
