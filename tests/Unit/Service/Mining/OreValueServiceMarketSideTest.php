<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Enum\MarketSide;
use App\Service\OreValueService;
use App\Service\StructureMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OreValueService::class)]
#[Group('issue-268')]
final class OreValueServiceMarketSideTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;

    private const int MARKET_STRUCTURE_ID = 1_000_000_000_001;
    private const float PRICE_DELTA = 1e-9;
    private const float REPROCESS_YIELD = 0.78;

    public function testBuySidePricesVeldsparFromTheBestJitaBuyOrders(): void
    {
        $oreValueService = $this->oreValueServiceOnBothMarketSides(
            jitaBuyPrices: [self::VELDSPAR => 17.5, self::COMPRESSED_VELDSPAR => 18.0, self::TRITANIUM => 4.0],
        );

        $veldspar = $oreValueService->getBatchOrePrices([self::VELDSPAR], null, self::REPROCESS_YIELD, 0.0, MarketSide::Buy)[self::VELDSPAR];

        $this->assertEqualsWithDelta(17.5, $veldspar['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(18.0, $veldspar['compressedUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(18.0, $veldspar['compressedEquivalentPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(12.48, $veldspar['reprocessValue'], self::PRICE_DELTA);
    }

    public function testBuySidePricesVeldsparFromTheHighestStructureBuyOrders(): void
    {
        $oreValueService = $this->oreValueServiceOnBothMarketSides(
            structureMarketService: $this->structureMarketTrading(
                self::MARKET_STRUCTURE_ID,
                [self::VELDSPAR => 19.2, self::COMPRESSED_VELDSPAR => 20.4, self::TRITANIUM => 4.9],
                [self::VELDSPAR => 16.9, self::COMPRESSED_VELDSPAR => 17.2, self::TRITANIUM => 3.85],
            ),
        );

        $veldspar = $oreValueService->getBatchOrePrices([self::VELDSPAR], self::MARKET_STRUCTURE_ID, self::REPROCESS_YIELD, 0.0, MarketSide::Buy)[self::VELDSPAR];

        $this->assertEqualsWithDelta(16.9, $veldspar['structureUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(17.2, $veldspar['structureCompressedUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(17.2, $veldspar['structureCompressedEquivalentPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(12.012, $veldspar['structureReprocessValue'], self::PRICE_DELTA);
    }

    public function testSellSideIsTheDefaultSideAndKeepsReadingTheLowestSellOrders(): void
    {
        $oreValueService = $this->oreValueServiceOnBothMarketSides(
            jitaBuyPrices: [self::VELDSPAR => 17.5, self::COMPRESSED_VELDSPAR => 18.0, self::TRITANIUM => 4.0],
            structureMarketService: $this->structureMarketTrading(
                self::MARKET_STRUCTURE_ID,
                [self::VELDSPAR => 19.2, self::COMPRESSED_VELDSPAR => 20.4, self::TRITANIUM => 4.9],
                [self::VELDSPAR => 16.9, self::COMPRESSED_VELDSPAR => 17.2, self::TRITANIUM => 3.85],
            ),
        );

        $byDefault = $oreValueService->getBatchOrePrices([self::VELDSPAR], self::MARKET_STRUCTURE_ID, self::REPROCESS_YIELD)[self::VELDSPAR];
        $sellSide = $oreValueService->getBatchOrePrices([self::VELDSPAR], self::MARKET_STRUCTURE_ID, self::REPROCESS_YIELD, 0.0, MarketSide::Sell)[self::VELDSPAR];

        $this->assertSame($byDefault, $sellSide);
        $this->assertEqualsWithDelta(18.37, $sellSide['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(19.61, $sellSide['compressedEquivalentPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(13.3224, $sellSide['reprocessValue'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(19.2, $sellSide['structureUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(20.4, $sellSide['structureCompressedEquivalentPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(15.288, $sellSide['structureReprocessValue'], self::PRICE_DELTA);
    }

    private function oreValueServiceOnBothMarketSides(
        array $jitaBuyPrices = [],
        ?StructureMarketService $structureMarketService = null,
    ): OreValueService {
        return new OreValueService(
            $this->sdeInvTypeRepository($this->sdeTypes(), []),
            $this->sdeInvTypeMaterialRepository($this->sdeReprocessMaterials()),
            $this->jitaMarket($this->jitaSellPrices(), $jitaBuyPrices),
            $structureMarketService,
        );
    }
}
