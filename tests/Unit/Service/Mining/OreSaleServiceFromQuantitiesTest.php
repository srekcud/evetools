<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Repository\CachedAssetRepository;
use App\Service\Mining\OreSaleService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OreSaleService::class)]
#[Group('issue-314')]
final class OreSaleServiceFromQuantitiesTest extends TestCase
{
    use BuildsOreSaleServiceOnHeldOres;

    private const float VALUE_DELTA = 1e-9;

    public function testTheGivenQuantitiesAreValuedWithoutReadingAnyAsset(): void
    {
        $sale = $this->serviceReadingNoAsset()->buildFromQuantities(
            [self::COMPRESSED_VELDSPAR => 1_000, self::COMPRESSED_CLEAR_ICICLE => 5],
            null,
        );

        self::assertSame([self::COMPRESSED_CLEAR_ICICLE, self::COMPRESSED_VELDSPAR], array_column($sale['rows'], 'typeId'));
        self::assertSame([5, 1_000], array_column($sale['rows'], 'quantity'));
        self::assertSame(1_005, $sale['totals']['totalQuantity']);
        self::assertEqualsWithDelta(919_000.0, $sale['totals']['totalValue'], self::VALUE_DELTA);
    }

    public function testAnEmptyQuantityMapGivesNoRowAndKnownZeroTotals(): void
    {
        $sale = $this->serviceReadingNoAsset()->buildFromQuantities([], null);

        self::assertSame([], $sale['rows']);
        self::assertSame(0, $sale['totals']['totalQuantity']);
        self::assertEqualsWithDelta(0.0, $sale['totals']['totalValue'], self::VALUE_DELTA);
    }

    public function testATypeWithoutAnyPriceKeepsTheTotalValueUnknown(): void
    {
        $sale = $this->serviceReadingNoAsset()->buildFromQuantities([self::COMPRESSED_BITUMENS => 33, self::COMPRESSED_VELDSPAR => 1_000], null);

        self::assertNull($sale['totals']['totalValue']);
        self::assertSame([self::COMPRESSED_BITUMENS], $sale['totals']['missingPriceTypeIds']);
    }

    public function testTheStructureChosenAddsItsPricesToTheGivenQuantities(): void
    {
        $sale = $this->serviceReadingNoAsset()->buildFromQuantities([self::COMPRESSED_VELDSPAR => 1_000], self::SALE_STRUCTURE_ID);

        self::assertSame('structure_buy', $this->onlyRow($sale['rows'])['bestSource']);
        self::assertEqualsWithDelta(21_000.0, $sale['totals']['totalValue'], self::VALUE_DELTA);
    }

    private function serviceReadingNoAsset(): OreSaleService
    {
        $cachedAssetRepository = $this->createMock(CachedAssetRepository::class);
        $cachedAssetRepository->expects(self::never())->method('getAggregatedQuantitiesByUser');

        return new OreSaleService(
            $cachedAssetRepository,
            $this->compressedMineableTypeRepository([]),
            $this->jitaMarketWithFallback(
                [self::COMPRESSED_VELDSPAR => 17.0, self::COMPRESSED_CLEAR_ICICLE => 175_000.0],
                [self::COMPRESSED_VELDSPAR => 19.0, self::COMPRESSED_CLEAR_ICICLE => 180_000.0],
            ),
            $this->saleStructureMarket([self::COMPRESSED_VELDSPAR => 21.0], []),
        );
    }

    private function onlyRow(array $rows): array
    {
        self::assertCount(1, $rows);

        return $rows[0];
    }
}
