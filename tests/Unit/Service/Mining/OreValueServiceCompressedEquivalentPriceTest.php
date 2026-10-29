<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Service\OreValueService;
use App\Service\StructureMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(OreValueService::class)]
#[Group('issue-259')]
final class OreValueServiceCompressedEquivalentPriceTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;

    private const float PRICE_DELTA = 1e-9;
    private const float DEFAULT_REPROCESS_YIELD = 0.78;
    private const float EXPORT_TAX_PER_CUBIC_METRE = 500.0;
    private const int MARKET_STRUCTURE_ID = 1_022_734_985_679;
    private const float JITA_COMPRESSED_VELDSPAR_PRICE = 1000.0;
    private const float STRUCTURE_COMPRESSED_VELDSPAR_PRICE = 1100.0;

    public function testCompressedEquivalentPriceOfVeldsparIsTheJitaCompressedUnitPrice(): void
    {
        $oreValueService = $this->createOreValueService($this->jitaSellPricesWithCompressedVeldsparAtOneThousand());

        $veldspar = $oreValueService->getBatchOrePrices([self::VELDSPAR])[self::VELDSPAR];

        $this->assertEqualsWithDelta(1000.0, $veldspar['compressedUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(1000.0, $veldspar['compressedEquivalentPrice'], self::PRICE_DELTA);
    }

    public function testStructureCompressedEquivalentPriceOfVeldsparIsTheStructureCompressedUnitPrice(): void
    {
        $oreValueService = $this->createOreValueService(
            $this->jitaSellPricesWithCompressedVeldsparAtOneThousand(),
            $this->structureSellingCompressedVeldspar(self::STRUCTURE_COMPRESSED_VELDSPAR_PRICE),
        );

        $veldspar = $oreValueService->getBatchOrePrices([self::VELDSPAR], self::MARKET_STRUCTURE_ID)[self::VELDSPAR];

        $this->assertEqualsWithDelta(1100.0, $veldspar['structureCompressedUnitPrice'], self::PRICE_DELTA);
        $this->assertArrayHasKey('structureCompressedEquivalentPrice', $veldspar);
        $this->assertEqualsWithDelta(1100.0, $veldspar['structureCompressedEquivalentPrice'], self::PRICE_DELTA);
    }

    public function testCompressedEquivalentPriceOfIceIsTheJitaCompressedUnitPrice(): void
    {
        $oreValueService = $this->createOreValueService($this->jitaSellPrices());

        $clearIcicle = $oreValueService->getBatchOrePrices([self::CLEAR_ICICLE])[self::CLEAR_ICICLE];

        $this->assertEqualsWithDelta(158700.0, $clearIcicle['compressedEquivalentPrice'], self::PRICE_DELTA);
    }

    public function testCompressedEquivalentPriceIsTheCompressedUnitPriceNetOfExportTax(): void
    {
        $oreValueService = $this->createOreValueService($this->jitaSellPricesWithCompressedVeldsparAtOneThousand());

        $veldspar = $oreValueService->getBatchOrePrices(
            [self::VELDSPAR],
            null,
            self::DEFAULT_REPROCESS_YIELD,
            self::EXPORT_TAX_PER_CUBIC_METRE,
        )[self::VELDSPAR];

        $this->assertEqualsWithDelta(999.5, $veldspar['compressedUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(999.5, $veldspar['compressedEquivalentPrice'], self::PRICE_DELTA);
    }

    public function testCompressedEquivalentPricesAreUnknownWhenTheCompressedPortionSizeIsMissing(): void
    {
        $sdeTypes = $this->sdeTypes();
        $sdeTypes[self::COMPRESSED_VELDSPAR]['portionSize'] = null;
        $oreValueService = $this->createOreValueService(
            $this->jitaSellPricesWithCompressedVeldsparAtOneThousand(),
            $this->structureSellingCompressedVeldspar(self::STRUCTURE_COMPRESSED_VELDSPAR_PRICE),
            $sdeTypes,
        );

        $veldspar = $oreValueService->getBatchOrePrices([self::VELDSPAR], self::MARKET_STRUCTURE_ID)[self::VELDSPAR];

        $this->assertNull($veldspar['compressedEquivalentPrice']);
        $this->assertEqualsWithDelta(1000.0, $veldspar['compressedUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(1100.0, $veldspar['structureCompressedUnitPrice'], self::PRICE_DELTA);
        $this->assertArrayHasKey('structureCompressedEquivalentPrice', $veldspar);
        $this->assertNull($veldspar['structureCompressedEquivalentPrice']);
    }

    public function testCompressedEquivalentPricesAreUnknownWhenTheRawPortionSizeIsMissing(): void
    {
        $sdeTypes = $this->sdeTypes();
        $sdeTypes[self::VELDSPAR]['portionSize'] = null;
        $oreValueService = $this->createOreValueService(
            $this->jitaSellPricesWithCompressedVeldsparAtOneThousand(),
            $this->structureSellingCompressedVeldspar(self::STRUCTURE_COMPRESSED_VELDSPAR_PRICE),
            $sdeTypes,
        );

        $veldspar = $oreValueService->getBatchOrePrices([self::VELDSPAR], self::MARKET_STRUCTURE_ID)[self::VELDSPAR];

        $this->assertNull($veldspar['compressedEquivalentPrice']);
        $this->assertEqualsWithDelta(1000.0, $veldspar['compressedUnitPrice'], self::PRICE_DELTA);
        $this->assertArrayHasKey('structureCompressedEquivalentPrice', $veldspar);
        $this->assertNull($veldspar['structureCompressedEquivalentPrice']);
    }

    public function testCompressedEquivalentPriceStaysUnknownWithoutJitaPriceForTheCompressedOre(): void
    {
        $jitaSellPrices = $this->jitaSellPrices();
        unset($jitaSellPrices[self::COMPRESSED_BITUMENS]);
        $oreValueService = $this->createOreValueService($jitaSellPrices);

        $bitumens = $oreValueService->getBatchOrePrices([self::BITUMENS])[self::BITUMENS];

        $this->assertNull($bitumens['compressedUnitPrice']);
        $this->assertNull($bitumens['compressedEquivalentPrice']);
    }

    public function testCompressedEquivalentPriceReadsTheRawUnitsPerCompressedUnitFromTheSdePortionSizes(): void
    {
        $sdeTypes = $this->sdeTypes();
        $sdeTypes[self::COMPRESSED_VELDSPAR]['portionSize'] = 1;
        $jitaSellPrices = $this->jitaSellPrices();
        $jitaSellPrices[self::COMPRESSED_VELDSPAR] = 2500.0;
        $oreValueService = $this->createOreValueService(
            $jitaSellPrices,
            $this->structureSellingCompressedVeldspar(3200.0),
            $sdeTypes,
        );

        $veldspar = $oreValueService->getBatchOrePrices([self::VELDSPAR], self::MARKET_STRUCTURE_ID)[self::VELDSPAR];

        $this->assertEqualsWithDelta(2500.0, $veldspar['compressedUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(25.0, $veldspar['compressedEquivalentPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(3200.0, $veldspar['structureCompressedUnitPrice'], self::PRICE_DELTA);
        $this->assertArrayHasKey('structureCompressedEquivalentPrice', $veldspar);
        $this->assertEqualsWithDelta(32.0, $veldspar['structureCompressedEquivalentPrice'], self::PRICE_DELTA);
    }

    public function testCompressedEquivalentPricesAreUnknownWhenTheCompressedPortionSizeIsZero(): void
    {
        $sdeTypes = $this->sdeTypes();
        $sdeTypes[self::COMPRESSED_VELDSPAR]['portionSize'] = 0;
        $oreValueService = $this->createOreValueService(
            $this->jitaSellPricesWithCompressedVeldsparAtOneThousand(),
            $this->structureSellingCompressedVeldspar(self::STRUCTURE_COMPRESSED_VELDSPAR_PRICE),
            $sdeTypes,
        );

        $veldspar = $oreValueService->getBatchOrePrices([self::VELDSPAR], self::MARKET_STRUCTURE_ID)[self::VELDSPAR];

        $this->assertNull($veldspar['compressedEquivalentPrice']);
        $this->assertEqualsWithDelta(1000.0, $veldspar['compressedUnitPrice'], self::PRICE_DELTA);
        $this->assertArrayHasKey('structureCompressedEquivalentPrice', $veldspar);
        $this->assertNull($veldspar['structureCompressedEquivalentPrice']);
    }

    #[TestWith([-1])]
    public function testCompressedEquivalentPricesAreUnknownWhenTheRawPortionSizeIsNotPositive(int $rawPortionSize): void
    {
        $sdeTypes = $this->sdeTypes();
        $sdeTypes[self::VELDSPAR]['portionSize'] = $rawPortionSize;
        $sdeTypes[self::COMPRESSED_VELDSPAR]['portionSize'] = 100;
        $oreValueService = $this->createOreValueService(
            $this->jitaSellPricesWithCompressedVeldsparAtOneThousand(),
            $this->structureSellingCompressedVeldspar(self::STRUCTURE_COMPRESSED_VELDSPAR_PRICE),
            $sdeTypes,
        );

        $veldspar = $oreValueService->getBatchOrePrices([self::VELDSPAR], self::MARKET_STRUCTURE_ID)[self::VELDSPAR];

        $this->assertNull($veldspar['compressedEquivalentPrice']);
        $this->assertNull($veldspar['structureCompressedEquivalentPrice']);
        $this->assertSame(1000.0, $veldspar['compressedUnitPrice']);
        $this->assertSame(1100.0, $veldspar['structureCompressedUnitPrice']);
    }

    private function jitaSellPricesWithCompressedVeldsparAtOneThousand(): array
    {
        $jitaSellPrices = $this->jitaSellPrices();
        $jitaSellPrices[self::COMPRESSED_VELDSPAR] = self::JITA_COMPRESSED_VELDSPAR_PRICE;

        return $jitaSellPrices;
    }

    private function structureSellingCompressedVeldspar(float $compressedVeldsparPrice): StructureMarketService
    {
        return $this->structureMarketSelling(self::MARKET_STRUCTURE_ID, [
            self::VELDSPAR => 17.94,
            self::COMPRESSED_VELDSPAR => $compressedVeldsparPrice,
            self::TRITANIUM => 4.61,
        ]);
    }
}
