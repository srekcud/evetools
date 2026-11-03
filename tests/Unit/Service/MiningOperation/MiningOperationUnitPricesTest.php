<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Service\MiningOperation\MiningOperationUnitPrices;
use App\Service\MiningOperation\MiningUnitPriceReading;
use App\Service\OreValueService;
use App\Service\StructureMarketService;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningOperationUnitPrices::class)]
#[CoversClass(MiningUnitPriceReading::class)]
#[Group('issue-264')]
final class MiningOperationUnitPricesTest extends TestCase
{
    use BuildsSpodumainOperation;
    use BuildsLedgerSnapshots;
    use BuildsOreValueServiceOnSdeOres;
    use BuildsValuedSpodumainOperation;

    private const int REFINERY = 1_000_000_000_001;
    private const string REFINERY_SYNCED_AT = '2026-11-05T21:20:00+00:00';

    private MiningOperation $operation;

    protected function setUp(): void
    {
        $this->setUpSpodumainPilots();
        $this->operation = $this->spodumainOperation();
    }

    public function testCompressedJitaSellBasisReadsTheCompressedEquivalentPricesInASingleCall(): void
    {
        $this->operation->updateValuationSettings(valuationForm: MiningValuationForm::Compressed, valuationSide: MarketSide::Sell);
        $jitaMarket = $this->jitaMarketSyncedAt($this->jitaSellPrices());
        $realOreValueService = $this->oreValueServiceOn($jitaMarket);
        $oreValueService = $this->createMock(OreValueService::class);
        $oreValueService->expects($this->once())
            ->method('getBatchOrePrices')
            ->with([self::VELDSPAR, self::BITUMENS], null, 1.0, 0.0, MarketSide::Sell)
            ->willReturnCallback(static fn (array $typeIds, ?int $structureId, float $reprocessYield, float $exportTax, MarketSide $side): array => $realOreValueService->getBatchOrePrices($typeIds, $structureId, $reprocessYield, $exportTax, $side));
        $unitPrices = new MiningOperationUnitPrices($oreValueService, $jitaMarket, $this->createStub(StructureMarketService::class));

        self::assertSame(
            [self::VELDSPAR => 19.61, self::BITUMENS => 221.45],
            $unitPrices->read($this->operation, [self::VELDSPAR, self::BITUMENS])->unitPricesByTypeId,
        );
    }

    public function testRawJitaSellBasisReadsTheRawUnitPrices(): void
    {
        $this->operation->updateValuationSettings(valuationForm: MiningValuationForm::Raw, valuationSide: MarketSide::Sell);

        self::assertSame(
            [self::VELDSPAR => 18.37, self::BITUMENS => 213.9],
            $this->readOnJita($this->jitaSellPrices(), [self::VELDSPAR, self::BITUMENS])->unitPricesByTypeId,
        );
    }

    public function testReprocessedJitaSellBasisReadsTheMineralValueAtTheOperationYield(): void
    {
        $this->operation->updateValuationSettings(valuationForm: MiningValuationForm::Reprocessed, valuationSide: MarketSide::Sell, reprocessYield: 0.78);

        $unitPrices = $this->readOnJita($this->jitaSellPrices(), [self::VELDSPAR, self::BITUMENS])->unitPricesByTypeId;

        self::assertSame([self::VELDSPAR, self::BITUMENS], array_keys($unitPrices));
        self::assertEqualsWithDelta(13.3224, $unitPrices[self::VELDSPAR], 1e-9);
        self::assertEqualsWithDelta(831.2148, $unitPrices[self::BITUMENS], 1e-9);
    }

    public function testCompressedBitumensWithoutJitaPriceIsWithoutPriceRatherThanItsRawPrice(): void
    {
        $this->operation->updateValuationSettings(valuationForm: MiningValuationForm::Compressed, valuationSide: MarketSide::Sell);
        $jitaSellPrices = $this->jitaSellPrices();
        unset($jitaSellPrices[self::COMPRESSED_BITUMENS]);

        self::assertSame(
            [self::BITUMENS => null],
            $this->readOnJita($jitaSellPrices, [self::BITUMENS])->unitPricesByTypeId,
        );
    }

    public function testGasValuedAsReprocessedIsWithoutPrice(): void
    {
        $this->operation->updateValuationSettings(valuationForm: MiningValuationForm::Reprocessed, valuationSide: MarketSide::Sell, reprocessYield: 0.78);

        self::assertSame(
            [self::FULLERITE_C50 => null],
            $this->readOnJita($this->jitaSellPrices(), [self::FULLERITE_C50])->unitPricesByTypeId,
        );
    }

    public function testCompressedStructureSellBasisReadsTheCompressedPriceOfTheOperationStructure(): void
    {
        $this->operation->updateValuationSettings(
            valuationForm: MiningValuationForm::Compressed,
            valuationMarket: MiningValuationMarket::Structure,
            valuationSide: MarketSide::Sell,
            structureId: self::REFINERY,
            structureName: 'Refinery B-1',
        );
        $structureMarket = $this->structureMarketSelling(self::REFINERY, [self::COMPRESSED_VELDSPAR => 1_100.0]);
        $structureMarket->method('getLastSyncTime')->willReturn(new \DateTimeImmutable(self::REFINERY_SYNCED_AT));
        $jitaMarket = $this->jitaMarketSyncedAt($this->jitaSellPrices());

        self::assertSame(
            [self::VELDSPAR => 1_100.0],
            $this->unitPricesOn($jitaMarket, $structureMarket)->read($this->operation, [self::VELDSPAR])->unitPricesByTypeId,
        );
    }

    public function testCompressedJitaBuyBasisReadsTheBuyOrders(): void
    {
        $this->operation->updateValuationSettings(valuationForm: MiningValuationForm::Compressed, valuationSide: MarketSide::Buy);

        self::assertSame(
            [self::VELDSPAR => 18.0],
            $this->readOnJita($this->jitaSellPrices(), [self::VELDSPAR], [self::COMPRESSED_VELDSPAR => 18.0])->unitPricesByTypeId,
        );
    }

    public function testJitaPricesCarryTheLastJitaSyncTime(): void
    {
        $this->operation->updateValuationSettings(valuationSide: MarketSide::Sell);

        self::assertEquals(
            new \DateTimeImmutable(self::JITA_SYNCED_AT),
            $this->readOnJita($this->jitaSellPrices(), [self::VELDSPAR])->pricesSyncedAt,
        );
    }

    public function testStructurePricesCarryTheLastSyncTimeOfTheOperationStructure(): void
    {
        $this->operation->updateValuationSettings(
            valuationMarket: MiningValuationMarket::Structure,
            valuationSide: MarketSide::Sell,
            structureId: self::REFINERY,
            structureName: 'Refinery B-1',
        );
        $structureMarket = $this->structureMarketSelling(self::REFINERY, [self::COMPRESSED_VELDSPAR => 1_100.0]);
        $structureMarket->method('getLastSyncTime')->willReturnCallback(
            static fn (int $structureId): ?\DateTimeImmutable => $structureId === self::REFINERY ? new \DateTimeImmutable(self::REFINERY_SYNCED_AT) : null,
        );

        self::assertEquals(
            new \DateTimeImmutable(self::REFINERY_SYNCED_AT),
            $this->unitPricesOn($this->jitaMarketSyncedAt($this->jitaSellPrices()), $structureMarket)->read($this->operation, [self::VELDSPAR])->pricesSyncedAt,
        );
    }

    public function testUnknownJitaSyncTimeStaysUnknown(): void
    {
        $this->operation->updateValuationSettings(valuationSide: MarketSide::Sell);

        $reading = $this->unitPricesOn($this->jitaMarketSyncedAt($this->jitaSellPrices(), [], null))->read($this->operation, [self::VELDSPAR]);

        self::assertSame([[self::VELDSPAR => 19.61], null], [$reading->unitPricesByTypeId, $reading->pricesSyncedAt]);
    }

    private function readOnJita(array $jitaSellPrices, array $typeIds, array $jitaBuyPrices = []): MiningUnitPriceReading
    {
        return $this->unitPricesOn($this->jitaMarketSyncedAt($jitaSellPrices, $jitaBuyPrices))->read($this->operation, $typeIds);
    }
}
