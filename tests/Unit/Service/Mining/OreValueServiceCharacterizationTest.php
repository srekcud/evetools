<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Service\OreValueService;
use App\Service\StructureMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OreValueService::class)]
final class OreValueServiceCharacterizationTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;

    private const float PRICE_DELTA = 1e-9;
    private const float DEFAULT_REPROCESS_YIELD = 0.78;
    private const float SKILLED_REPROCESS_YIELD = 0.8655;
    private const float EXPORT_TAX_PER_CUBIC_METRE = 37.5;
    private const float PROHIBITIVE_EXPORT_TAX_PER_CUBIC_METRE = 2500.0;
    private const int MARKET_STRUCTURE_ID = 1_035_466_617_946;

    public function testReturnsNoOrePricesForNoTypeIdsWithoutLookingUpSdeNorMarket(): void
    {
        $oreValueService = $this->oreValueServiceThatMustNotLookUpAnything();

        $this->assertSame([], $oreValueService->getBatchOrePrices([]));
    }

    public function testPricesVeldsparRawCompressedAndReprocessedOnJitaAtDefaultYield(): void
    {
        $oreValueService = $this->createOreValueService($this->jitaSellPrices());

        $orePrices = $oreValueService->getBatchOrePrices([self::VELDSPAR]);

        $this->assertSame([self::VELDSPAR], array_keys($orePrices));
        $this->assertSame(
            [
                'rawUnitPrice',
                'compressedTypeId',
                'compressedTypeName',
                'compressedUnitPrice',
                'compressedEquivalentPrice',
                'structureUnitPrice',
                'structureCompressedUnitPrice',
                'reprocessValue',
                'structureReprocessValue',
                'reprocessYield',
            ],
            array_keys($orePrices[self::VELDSPAR]),
        );
        $veldspar = $orePrices[self::VELDSPAR];
        $this->assertEqualsWithDelta(18.37, $veldspar['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertSame(self::COMPRESSED_VELDSPAR, $veldspar['compressedTypeId']);
        $this->assertSame('Compressed Veldspar', $veldspar['compressedTypeName']);
        $this->assertEqualsWithDelta(19.61, $veldspar['compressedUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(13.3224, $veldspar['reprocessValue'], self::PRICE_DELTA);
        $this->assertSame(self::DEFAULT_REPROCESS_YIELD, $veldspar['reprocessYield']);
        $this->assertNull($veldspar['structureUnitPrice']);
        $this->assertNull($veldspar['structureCompressedUnitPrice']);
        $this->assertNull($veldspar['structureReprocessValue']);
    }

    public function testReprocessesEachOreFromItsOwnSdeRecipeAndPortionSizeAtTheGivenYield(): void
    {
        $oreValueService = $this->createOreValueService($this->jitaSellPrices());

        $orePrices = $oreValueService->getBatchOrePrices(
            [self::VELDSPAR, self::BITUMENS, self::CLEAR_ICICLE],
            null,
            self::SKILLED_REPROCESS_YIELD,
        );

        $this->assertSame([self::VELDSPAR, self::BITUMENS, self::CLEAR_ICICLE], array_keys($orePrices));
        $this->assertEqualsWithDelta(14.78274, $orePrices[self::VELDSPAR]['reprocessValue'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(922.32873, $orePrices[self::BITUMENS]['reprocessValue'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(239513.36355, $orePrices[self::CLEAR_ICICLE]['reprocessValue'], 1e-6);
        foreach ($orePrices as $orePrice) {
            $this->assertSame(self::SKILLED_REPROCESS_YIELD, $orePrice['reprocessYield']);
        }
        $this->assertEqualsWithDelta(213.9, $orePrices[self::BITUMENS]['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(152300.0, $orePrices[self::CLEAR_ICICLE]['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertSame(self::COMPRESSED_BITUMENS, $orePrices[self::BITUMENS]['compressedTypeId']);
        $this->assertSame('Compressed Bitumens', $orePrices[self::BITUMENS]['compressedTypeName']);
        $this->assertEqualsWithDelta(221.45, $orePrices[self::BITUMENS]['compressedUnitPrice'], self::PRICE_DELTA);
        $this->assertSame(self::COMPRESSED_CLEAR_ICICLE, $orePrices[self::CLEAR_ICICLE]['compressedTypeId']);
        $this->assertSame('Compressed Clear Icicle', $orePrices[self::CLEAR_ICICLE]['compressedTypeName']);
        $this->assertEqualsWithDelta(158700.0, $orePrices[self::CLEAR_ICICLE]['compressedUnitPrice'], self::PRICE_DELTA);
    }

    #[Group('mining-audit-P1-1')]
    public function testCompressedEquivalentPriceCurrentlyDividesTheCompressedPriceByOneHundredMiningAuditP1_1(): void
    {
        $oreValueService = $this->createOreValueService($this->jitaSellPrices());

        $orePrices = $oreValueService->getBatchOrePrices(
            [self::VELDSPAR, self::BITUMENS, self::CLEAR_ICICLE, self::FULLERITE_C50],
        );

        $this->assertEqualsWithDelta(0.1961, $orePrices[self::VELDSPAR]['compressedEquivalentPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(2.2145, $orePrices[self::BITUMENS]['compressedEquivalentPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(1587.0, $orePrices[self::CLEAR_ICICLE]['compressedEquivalentPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(28.13, $orePrices[self::FULLERITE_C50]['compressedEquivalentPrice'], self::PRICE_DELTA);

        $taxedVeldspar = $oreValueService->getBatchOrePrices(
            [self::VELDSPAR],
            null,
            self::DEFAULT_REPROCESS_YIELD,
            self::EXPORT_TAX_PER_CUBIC_METRE,
        )[self::VELDSPAR];

        $this->assertEqualsWithDelta(0.195725, $taxedVeldspar['compressedEquivalentPrice'], self::PRICE_DELTA);
    }

    public function testSubtractsExportTaxPerCubicMetreFromRawAndCompressedJitaPricesDownToZero(): void
    {
        $oreValueService = $this->createOreValueService($this->jitaSellPrices());

        $orePrices = $oreValueService->getBatchOrePrices(
            [self::VELDSPAR, self::BITUMENS, self::CLEAR_ICICLE],
            null,
            self::DEFAULT_REPROCESS_YIELD,
            self::EXPORT_TAX_PER_CUBIC_METRE,
        );

        $this->assertEqualsWithDelta(14.62, $orePrices[self::VELDSPAR]['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(19.5725, $orePrices[self::VELDSPAR]['compressedUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(0.0, $orePrices[self::BITUMENS]['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(217.7, $orePrices[self::BITUMENS]['compressedUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(114800.0, $orePrices[self::CLEAR_ICICLE]['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(154950.0, $orePrices[self::CLEAR_ICICLE]['compressedUnitPrice'], self::PRICE_DELTA);
    }

    public function testClampsRawAndCompressedJitaPricesAtZeroWhenExportTaxExceedsThem(): void
    {
        $oreValueService = $this->createOreValueService($this->jitaSellPrices());

        $bitumens = $oreValueService->getBatchOrePrices(
            [self::BITUMENS],
            null,
            self::DEFAULT_REPROCESS_YIELD,
            self::PROHIBITIVE_EXPORT_TAX_PER_CUBIC_METRE,
        )[self::BITUMENS];

        $this->assertEqualsWithDelta(0.0, $bitumens['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(0.0, $bitumens['compressedUnitPrice'], self::PRICE_DELTA);
    }

    #[Group('mining-sde-fallbacks')]
    public function testCurrentlyAppliesNoExportTaxWhenSdeVolumeIsMissing(): void
    {
        $sdeTypes = $this->sdeTypes();
        $sdeTypes[self::VELDSPAR]['volume'] = null;
        $sdeTypes[self::COMPRESSED_VELDSPAR]['volume'] = null;
        $oreValueService = $this->createOreValueService($this->jitaSellPrices(), null, $sdeTypes);

        $veldspar = $oreValueService->getBatchOrePrices(
            [self::VELDSPAR],
            null,
            self::DEFAULT_REPROCESS_YIELD,
            self::EXPORT_TAX_PER_CUBIC_METRE,
        )[self::VELDSPAR];

        $this->assertEqualsWithDelta(18.37, $veldspar['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(19.61, $veldspar['compressedUnitPrice'], self::PRICE_DELTA);
    }

    #[Group('mining-audit-P3-3')]
    public function testReprocessValueCurrentlyIgnoresExportTaxMiningAuditP3_3(): void
    {
        $oreValueService = $this->createOreValueService($this->jitaSellPrices());

        $orePrices = $oreValueService->getBatchOrePrices(
            [self::VELDSPAR, self::BITUMENS, self::CLEAR_ICICLE],
            null,
            self::DEFAULT_REPROCESS_YIELD,
            self::EXPORT_TAX_PER_CUBIC_METRE,
        );

        $this->assertEqualsWithDelta(13.3224, $orePrices[self::VELDSPAR]['reprocessValue'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(831.2148, $orePrices[self::BITUMENS]['reprocessValue'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(215852.598, $orePrices[self::CLEAR_ICICLE]['reprocessValue'], 1e-6);
    }

    public function testPricesTheStructureMarketWithoutExportTaxAndOnlyWithEveryMineralListed(): void
    {
        $structureMarketService = $this->structureMarketSelling(self::MARKET_STRUCTURE_ID, [
            self::VELDSPAR => 17.94,
            self::COMPRESSED_VELDSPAR => 20.38,
            self::TRITANIUM => 4.61,
            self::BITUMENS => 208.3,
            self::PYERITE => 10.17,
            self::MEXALLON => 58.9,
            self::HYDROCARBONS => 347.9,
            self::CLEAR_ICICLE => 149800.0,
            self::HEAVY_WATER => 91.2,
        ]);
        $oreValueService = $this->createOreValueService($this->jitaSellPrices(), $structureMarketService);

        $orePrices = $oreValueService->getBatchOrePrices(
            [self::VELDSPAR, self::BITUMENS, self::CLEAR_ICICLE],
            self::MARKET_STRUCTURE_ID,
            self::DEFAULT_REPROCESS_YIELD,
            self::EXPORT_TAX_PER_CUBIC_METRE,
        );

        $this->assertEqualsWithDelta(17.94, $orePrices[self::VELDSPAR]['structureUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(20.38, $orePrices[self::VELDSPAR]['structureCompressedUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(14.3832, $orePrices[self::VELDSPAR]['structureReprocessValue'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(14.62, $orePrices[self::VELDSPAR]['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(208.3, $orePrices[self::BITUMENS]['structureUnitPrice'], self::PRICE_DELTA);
        $this->assertNull($orePrices[self::BITUMENS]['structureCompressedUnitPrice']);
        $this->assertEqualsWithDelta(836.1093, $orePrices[self::BITUMENS]['structureReprocessValue'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(149800.0, $orePrices[self::CLEAR_ICICLE]['structureUnitPrice'], self::PRICE_DELTA);
        $this->assertNull($orePrices[self::CLEAR_ICICLE]['structureReprocessValue']);
    }

    public function testIgnoresTheStructureMarketWithoutStructureId(): void
    {
        $structureMarketService = $this->createMock(StructureMarketService::class);
        $structureMarketService->expects($this->never())->method('getLowestSellPrices');
        $oreValueService = $this->createOreValueService($this->jitaSellPrices(), $structureMarketService);

        $veldspar = $oreValueService->getBatchOrePrices([self::VELDSPAR])[self::VELDSPAR];

        $this->assertNull($veldspar['structureUnitPrice']);
        $this->assertNull($veldspar['structureCompressedUnitPrice']);
        $this->assertNull($veldspar['structureReprocessValue']);
    }

    public function testIgnoresTheStructureIdWithoutStructureMarket(): void
    {
        $oreValueService = $this->createOreValueService($this->jitaSellPrices());

        $veldspar = $oreValueService->getBatchOrePrices([self::VELDSPAR], self::MARKET_STRUCTURE_ID)[self::VELDSPAR];

        $this->assertNull($veldspar['structureUnitPrice']);
        $this->assertNull($veldspar['structureCompressedUnitPrice']);
        $this->assertNull($veldspar['structureReprocessValue']);
        $this->assertEqualsWithDelta(13.3224, $veldspar['reprocessValue'], self::PRICE_DELTA);
    }

    public function testLeavesAPriceUnknownWhenJitaHasNoSellOrderForIt(): void
    {
        $jitaSellPrices = $this->jitaSellPrices();
        unset(
            $jitaSellPrices[self::VELDSPAR],
            $jitaSellPrices[self::COMPRESSED_BITUMENS],
            $jitaSellPrices[self::HYDROCARBONS],
        );
        $oreValueService = $this->createOreValueService($jitaSellPrices);

        $orePrices = $oreValueService->getBatchOrePrices([self::VELDSPAR, self::BITUMENS]);

        $this->assertNull($orePrices[self::VELDSPAR]['rawUnitPrice']);
        $this->assertEqualsWithDelta(13.3224, $orePrices[self::VELDSPAR]['reprocessValue'], self::PRICE_DELTA);
        $this->assertSame(self::COMPRESSED_BITUMENS, $orePrices[self::BITUMENS]['compressedTypeId']);
        $this->assertSame('Compressed Bitumens', $orePrices[self::BITUMENS]['compressedTypeName']);
        $this->assertNull($orePrices[self::BITUMENS]['compressedUnitPrice']);
        $this->assertNull($orePrices[self::BITUMENS]['compressedEquivalentPrice']);
        $this->assertNull($orePrices[self::BITUMENS]['reprocessValue']);
        $this->assertEqualsWithDelta(213.9, $orePrices[self::BITUMENS]['rawUnitPrice'], self::PRICE_DELTA);
    }

    public function testLeavesTheReprocessValuesUnknownWhenMineralsAreWorthNothing(): void
    {
        $jitaSellPrices = $this->jitaSellPrices();
        $jitaSellPrices[self::TRITANIUM] = 0.0;
        $structureMarketService = $this->structureMarketSelling(self::MARKET_STRUCTURE_ID, [
            self::VELDSPAR => 17.94,
            self::TRITANIUM => 0.0,
        ]);
        $oreValueService = $this->createOreValueService($jitaSellPrices, $structureMarketService);

        $veldspar = $oreValueService->getBatchOrePrices([self::VELDSPAR], self::MARKET_STRUCTURE_ID)[self::VELDSPAR];

        $this->assertNull($veldspar['reprocessValue']);
        $this->assertNull($veldspar['structureReprocessValue']);
        $this->assertEqualsWithDelta(17.94, $veldspar['structureUnitPrice'], self::PRICE_DELTA);
    }

    public function testGivesACompressedOreNoCompressedVariantButReprocessesIt(): void
    {
        $oreValueService = $this->createOreValueService($this->jitaSellPrices());

        $compressedVeldspar = $oreValueService->getBatchOrePrices([self::COMPRESSED_VELDSPAR])[self::COMPRESSED_VELDSPAR];

        $this->assertEqualsWithDelta(19.61, $compressedVeldspar['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertNull($compressedVeldspar['compressedTypeId']);
        $this->assertNull($compressedVeldspar['compressedTypeName']);
        $this->assertNull($compressedVeldspar['compressedUnitPrice']);
        $this->assertNull($compressedVeldspar['compressedEquivalentPrice']);
        $this->assertEqualsWithDelta(13.3224, $compressedVeldspar['reprocessValue'], self::PRICE_DELTA);
    }

    public function testGivesATypeWithoutCompressedVariantNorRecipeOnlyItsRawPrice(): void
    {
        $oreValueService = $this->createOreValueService($this->jitaSellPrices());

        $tritanium = $oreValueService->getBatchOrePrices([self::TRITANIUM])[self::TRITANIUM];

        $this->assertEqualsWithDelta(4.27, $tritanium['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertNull($tritanium['compressedTypeId']);
        $this->assertNull($tritanium['compressedTypeName']);
        $this->assertNull($tritanium['compressedUnitPrice']);
        $this->assertNull($tritanium['compressedEquivalentPrice']);
        $this->assertNull($tritanium['reprocessValue']);
    }

    #[Group('mining-sde-fallbacks')]
    public function testCurrentlyPricesATypeUnknownToSdeWithoutExportTax(): void
    {
        $oreValueService = $this->createOreValueService($this->jitaSellPrices());

        $orePrices = $oreValueService->getBatchOrePrices(
            [self::TYPE_UNKNOWN_TO_SDE],
            null,
            self::DEFAULT_REPROCESS_YIELD,
            self::EXPORT_TAX_PER_CUBIC_METRE,
        );

        $this->assertEqualsWithDelta(1093.5, $orePrices[self::TYPE_UNKNOWN_TO_SDE]['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertNull($orePrices[self::TYPE_UNKNOWN_TO_SDE]['compressedTypeId']);
        $this->assertNull($orePrices[self::TYPE_UNKNOWN_TO_SDE]['reprocessValue']);
    }

    #[Group('mining-sde-fallbacks')]
    public function testCurrentlyReprocessesPerHundredUnitsWhenSdePortionSizeIsMissing(): void
    {
        $sdeTypes = $this->sdeTypes();
        $sdeTypes[self::CLEAR_ICICLE]['portionSize'] = null;
        $oreValueService = $this->createOreValueService($this->jitaSellPrices(), null, $sdeTypes);

        $clearIcicle = $oreValueService->getBatchOrePrices([self::CLEAR_ICICLE])[self::CLEAR_ICICLE];

        $this->assertEqualsWithDelta(2158.52598, $clearIcicle['reprocessValue'], self::PRICE_DELTA);
    }

    #[Group('mining-sde-fallbacks')]
    public function testCurrentlyReprocessesAnOreWithRecipeButNoSdeTypePerHundredUnits(): void
    {
        $sdeTypes = $this->sdeTypes();
        unset($sdeTypes[self::CLEAR_ICICLE]);
        $oreValueService = $this->createOreValueService($this->jitaSellPrices(), null, $sdeTypes);

        $clearIcicle = $oreValueService->getBatchOrePrices([self::CLEAR_ICICLE])[self::CLEAR_ICICLE];

        $this->assertEqualsWithDelta(2158.52598, $clearIcicle['reprocessValue'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(152300.0, $clearIcicle['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertNull($clearIcicle['compressedTypeId']);
    }
}
