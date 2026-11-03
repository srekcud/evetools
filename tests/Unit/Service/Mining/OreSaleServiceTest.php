<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Entity\User;
use App\Service\Mining\OreSaleService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OreSaleService::class)]
#[Group('issue-314')]
final class OreSaleServiceTest extends TestCase
{
    use BuildsOreSaleServiceOnHeldOres;

    private const float VALUE_DELTA = 1e-9;
    private const array ROW_KEYS = [
        'typeId', 'typeName', 'quantity', 'unitVolume', 'totalVolume',
        'jitaBuyPrice', 'jitaSellPrice', 'structureBuyPrice', 'structureSellPrice',
        'bestPrice', 'bestSource', 'totalValue',
    ];
    private const array TOTALS_KEYS = [
        'totalQuantity', 'totalVolume', 'totalValue', 'missingPriceTypeIds', 'missingVolumeTypeIds',
    ];

    public function testCompressedVeldsparHeldAcrossTheCharactersOfTheUserIsOneRowOfTheirSummedQuantity(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [self::COMPRESSED_VELDSPAR => 20_000],
            [self::COMPRESSED_VELDSPAR => 17.0],
            [self::COMPRESSED_VELDSPAR => 19.0],
        )->build($user, null);

        $this->assertRowEquals([
            'typeId' => self::COMPRESSED_VELDSPAR,
            'typeName' => 'Compressed Veldspar',
            'quantity' => 20_000,
            'unitVolume' => 0.001,
            'totalVolume' => 20.0,
        ], $this->onlyRow($sale['rows']));
    }

    public function testOnlyCompressedMineableTypesKnownToTheSdeAreKeptAmongTheHeldAssets(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [
                self::TRITANIUM => 5_000,
                self::BATCH_COMPRESSED_VELDSPAR => 100,
                self::COMPRESSED_VELDSPAR => 10,
                self::TYPE_UNKNOWN_TO_SDE => 3,
            ],
            [self::TRITANIUM => 3.95, self::BATCH_COMPRESSED_VELDSPAR => 1_710.0, self::COMPRESSED_VELDSPAR => 17.0, self::TYPE_UNKNOWN_TO_SDE => 512.0],
            [self::TRITANIUM => 4.27, self::BATCH_COMPRESSED_VELDSPAR => 1_905.0, self::COMPRESSED_VELDSPAR => 19.0, self::TYPE_UNKNOWN_TO_SDE => 548.0],
        )->build($user, null);

        $this->assertSame([self::COMPRESSED_VELDSPAR], array_column($sale['rows'], 'typeId'));
        $this->assertTotalsEqual([
            'totalQuantity' => 10,
            'totalVolume' => 0.01,
            'totalValue' => 190.0,
            'missingPriceTypeIds' => [],
            'missingVolumeTypeIds' => [],
        ], $sale['totals']);
    }

    public function testHeldQuantitiesOfZeroOrBelowAreLeftOut(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [self::COMPRESSED_BITUMENS => 0, self::COMPRESSED_FULLERITE_C50 => -5, self::COMPRESSED_VELDSPAR => 10],
            [self::COMPRESSED_BITUMENS => 205.0, self::COMPRESSED_FULLERITE_C50 => 2_650.0, self::COMPRESSED_VELDSPAR => 17.0],
            [self::COMPRESSED_BITUMENS => 221.45, self::COMPRESSED_FULLERITE_C50 => 2_813.0, self::COMPRESSED_VELDSPAR => 19.0],
        )->build($user, null);

        $this->assertSame([self::COMPRESSED_VELDSPAR], array_column($sale['rows'], 'typeId'));
        $this->assertSame(10, $sale['totals']['totalQuantity']);
    }

    public function testWithoutStructureCompressedVeldsparIsWorthItsJitaSellPrice(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [self::COMPRESSED_VELDSPAR => 20_000],
            [self::COMPRESSED_VELDSPAR => 17.0],
            [self::COMPRESSED_VELDSPAR => 19.0],
            [self::COMPRESSED_VELDSPAR => 21.0],
            [self::COMPRESSED_VELDSPAR => 20.5],
        )->build($user, null);

        $this->assertRowEquals([
            'typeId' => self::COMPRESSED_VELDSPAR,
            'typeName' => 'Compressed Veldspar',
            'quantity' => 20_000,
            'unitVolume' => 0.001,
            'totalVolume' => 20.0,
            'jitaBuyPrice' => 17.0,
            'jitaSellPrice' => 19.0,
            'structureBuyPrice' => null,
            'structureSellPrice' => null,
            'bestPrice' => 19.0,
            'bestSource' => 'jita_sell',
            'totalValue' => 380_000.0,
        ], $this->onlyRow($sale['rows']));
    }

    public function testWithTheStructureCompressedVeldsparIsWorthTheStructureBuyPriceAboveEveryOtherPrice(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [self::COMPRESSED_VELDSPAR => 20_000],
            [self::COMPRESSED_VELDSPAR => 17.0],
            [self::COMPRESSED_VELDSPAR => 19.0],
            [self::COMPRESSED_VELDSPAR => 21.0],
            [self::COMPRESSED_VELDSPAR => 20.5],
        )->build($user, self::SALE_STRUCTURE_ID);

        $this->assertRowEquals([
            'typeId' => self::COMPRESSED_VELDSPAR,
            'jitaBuyPrice' => 17.0,
            'jitaSellPrice' => 19.0,
            'structureBuyPrice' => 21.0,
            'structureSellPrice' => 20.5,
            'bestPrice' => 21.0,
            'bestSource' => 'structure_buy',
            'totalValue' => 420_000.0,
        ], $this->onlyRow($sale['rows']));
        $this->assertEqualsWithDelta(420_000.0, $sale['totals']['totalValue'], self::VALUE_DELTA);
    }

    public function testEqualJitaBuyAndSellPricesMakeJitaBuyTheBestSource(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [self::COMPRESSED_VELDSPAR => 20_000],
            [self::COMPRESSED_VELDSPAR => 20.0],
            [self::COMPRESSED_VELDSPAR => 20.0],
        )->build($user, null);

        $this->assertRowEquals([
            'bestPrice' => 20.0,
            'bestSource' => 'jita_buy',
            'totalValue' => 400_000.0,
        ], $this->onlyRow($sale['rows']));
    }

    #[DataProvider('bestPriceAcrossTheFourSources')]
    public function testTheBestPriceIsTheHighestKnownPriceAndTiesGoToTheEarlierSource(
        int $typeId,
        int $quantity,
        array $prices,
        float $bestPrice,
        string $bestSource,
        float $totalValue,
    ): void {
        [$jitaBuyPrice, $jitaSellPrice, $structureBuyPrice, $structureSellPrice] = $prices;
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [$typeId => $quantity],
            $jitaBuyPrice === null ? [] : [$typeId => $jitaBuyPrice],
            $jitaSellPrice === null ? [] : [$typeId => $jitaSellPrice],
            $structureBuyPrice === null ? [] : [$typeId => $structureBuyPrice],
            $structureSellPrice === null ? [] : [$typeId => $structureSellPrice],
        )->build($user, self::SALE_STRUCTURE_ID);

        $this->assertRowEquals([
            'typeId' => $typeId,
            'jitaBuyPrice' => $jitaBuyPrice,
            'jitaSellPrice' => $jitaSellPrice,
            'structureBuyPrice' => $structureBuyPrice,
            'structureSellPrice' => $structureSellPrice,
            'bestPrice' => $bestPrice,
            'bestSource' => $bestSource,
            'totalValue' => $totalValue,
        ], $this->onlyRow($sale['rows']));
    }

    public static function bestPriceAcrossTheFourSources(): iterable
    {
        yield 'jita buy above every other price' => [self::COMPRESSED_FULLERITE_C50, 12, [2_900.0, 2_813.0, 2_750.0, 2_880.0], 2_900.0, 'jita_buy', 34_800.0];
        yield 'structure sell above every other price' => [self::COMPRESSED_CLEAR_ICICLE, 5, [175_000.0, 180_000.0, 176_500.0, 182_000.0], 182_000.0, 'structure_sell', 910_000.0];
        yield 'jita sell equal to structure buy' => [self::COMPRESSED_BITUMENS, 350, [205.0, 221.45, 221.45, 219.0], 221.45, 'jita_sell', 77_507.5];
        yield 'structure buy equal to structure sell' => [self::COMPRESSED_VELDSPAR, 20_000, [17.0, 19.0, 21.5, 21.5], 21.5, 'structure_buy', 430_000.0];
        yield 'only the structure sell price known' => [self::COMPRESSED_CLEAR_ICICLE, 7, [null, null, null, 181_250.0], 181_250.0, 'structure_sell', 1_268_750.0];
    }

    public function testPricesOfAnotherStructureAreNeverRead(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [self::COMPRESSED_VELDSPAR => 20_000],
            [self::COMPRESSED_VELDSPAR => 17.0],
            [self::COMPRESSED_VELDSPAR => 19.0],
            [self::COMPRESSED_VELDSPAR => 21.0],
            [self::COMPRESSED_VELDSPAR => 20.5],
        )->build($user, self::OTHER_STRUCTURE_ID);

        $this->assertRowEquals([
            'structureBuyPrice' => null,
            'structureSellPrice' => null,
            'bestPrice' => 19.0,
            'bestSource' => 'jita_sell',
        ], $this->onlyRow($sale['rows']));
    }

    public function testCompressedBitumensWithoutAnyPriceLeavesItsValueAndTheTotalValueUnknown(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [self::COMPRESSED_BITUMENS => 350, self::COMPRESSED_VELDSPAR => 20_000],
            [self::COMPRESSED_VELDSPAR => 17.0],
            [self::COMPRESSED_VELDSPAR => 19.0],
        )->build($user, null);

        $this->assertRowEquals([
            'typeId' => self::COMPRESSED_BITUMENS,
            'typeName' => 'Compressed Bitumens',
            'quantity' => 350,
            'unitVolume' => 0.1,
            'totalVolume' => 35.0,
            'jitaBuyPrice' => null,
            'jitaSellPrice' => null,
            'structureBuyPrice' => null,
            'structureSellPrice' => null,
            'bestPrice' => null,
            'bestSource' => null,
            'totalValue' => null,
        ], $this->rowOf($sale['rows'], self::COMPRESSED_BITUMENS));
        $this->assertTotalsEqual([
            'totalQuantity' => 20_350,
            'totalVolume' => 55.0,
            'totalValue' => null,
            'missingPriceTypeIds' => [self::COMPRESSED_BITUMENS],
            'missingVolumeTypeIds' => [],
        ], $sale['totals']);
    }

    public function testAKnownPriceOfZeroIsAKnownValueAndNotAMissingPrice(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [self::COMPRESSED_BITUMENS => 350],
            [self::COMPRESSED_BITUMENS => 0.0],
            [],
        )->build($user, null);

        $this->assertRowEquals([
            'jitaBuyPrice' => 0.0,
            'jitaSellPrice' => null,
            'bestPrice' => 0.0,
            'bestSource' => 'jita_buy',
            'totalValue' => 0.0,
        ], $this->onlyRow($sale['rows']));
        $this->assertTotalsEqual([
            'totalQuantity' => 350,
            'totalVolume' => 35.0,
            'totalValue' => 0.0,
            'missingPriceTypeIds' => [],
            'missingVolumeTypeIds' => [],
        ], $sale['totals']);
    }

    public function testVolumesOfCompressedClearIcicleAndVeldsparAddUpToTheTotalVolume(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [self::COMPRESSED_CLEAR_ICICLE => 5, self::COMPRESSED_VELDSPAR => 20_000],
            [self::COMPRESSED_CLEAR_ICICLE => 175_000.0, self::COMPRESSED_VELDSPAR => 17.0],
            [self::COMPRESSED_CLEAR_ICICLE => 180_000.0, self::COMPRESSED_VELDSPAR => 19.0],
        )->build($user, null);

        $this->assertEqualsWithDelta(500.0, $this->rowOf($sale['rows'], self::COMPRESSED_CLEAR_ICICLE)['totalVolume'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(20.0, $this->rowOf($sale['rows'], self::COMPRESSED_VELDSPAR)['totalVolume'], self::VALUE_DELTA);
        $this->assertTotalsEqual([
            'totalQuantity' => 20_005,
            'totalVolume' => 520.0,
            'totalValue' => 1_280_000.0,
            'missingPriceTypeIds' => [],
            'missingVolumeTypeIds' => [],
        ], $sale['totals']);
    }

    public function testCompressedFulleriteWithoutSdeVolumeLeavesTheTotalVolumeUnknownButNotItsValue(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [self::COMPRESSED_FULLERITE_C50 => 12, self::COMPRESSED_VELDSPAR => 20_000],
            [self::COMPRESSED_FULLERITE_C50 => 2_650.0, self::COMPRESSED_VELDSPAR => 17.0],
            [self::COMPRESSED_FULLERITE_C50 => 2_813.0, self::COMPRESSED_VELDSPAR => 19.0],
            unitVolumes: [self::COMPRESSED_FULLERITE_C50 => null],
        )->build($user, null);

        $this->assertRowEquals([
            'typeId' => self::COMPRESSED_FULLERITE_C50,
            'quantity' => 12,
            'unitVolume' => null,
            'totalVolume' => null,
            'bestPrice' => 2_813.0,
            'totalValue' => 33_756.0,
        ], $this->rowOf($sale['rows'], self::COMPRESSED_FULLERITE_C50));
        $this->assertTotalsEqual([
            'totalQuantity' => 20_012,
            'totalVolume' => null,
            'totalValue' => 413_756.0,
            'missingPriceTypeIds' => [],
            'missingVolumeTypeIds' => [self::COMPRESSED_FULLERITE_C50],
        ], $sale['totals']);
    }

    public function testRowsAreSortedByDescendingValueWithUnknownValuesLast(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [self::COMPRESSED_BITUMENS => 350, self::COMPRESSED_VELDSPAR => 20_000, self::COMPRESSED_CLEAR_ICICLE => 5],
            [self::COMPRESSED_VELDSPAR => 17.0, self::COMPRESSED_CLEAR_ICICLE => 175_000.0],
            [self::COMPRESSED_VELDSPAR => 19.0, self::COMPRESSED_CLEAR_ICICLE => 180_000.0],
        )->build($user, null);

        $this->assertSame(
            [self::COMPRESSED_CLEAR_ICICLE, self::COMPRESSED_VELDSPAR, self::COMPRESSED_BITUMENS],
            array_column($sale['rows'], 'typeId'),
        );
    }

    public function testRowsOfEqualValueAndUnknownRowsAreSortedByName(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [
                self::COMPRESSED_VELDSPAR => 20_000,
                self::COMPRESSED_CLEAR_ICICLE => 1,
                self::COMPRESSED_FULLERITE_C50 => 12,
                self::COMPRESSED_BITUMENS => 100,
            ],
            [],
            [self::COMPRESSED_CLEAR_ICICLE => 30_000.0, self::COMPRESSED_BITUMENS => 300.0],
        )->build($user, null);

        $this->assertSame(
            [self::COMPRESSED_BITUMENS, self::COMPRESSED_CLEAR_ICICLE, self::COMPRESSED_FULLERITE_C50, self::COMPRESSED_VELDSPAR],
            array_column($sale['rows'], 'typeId'),
        );
        $this->assertEqualsCanonicalizing(
            [self::COMPRESSED_FULLERITE_C50, self::COMPRESSED_VELDSPAR],
            $sale['totals']['missingPriceTypeIds'],
        );
    }

    public function testWithoutAnyHeldAssetTheSaleHasNoRowAndKnownZeroTotals(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService($user, [], [], [])->build($user, self::SALE_STRUCTURE_ID);

        $this->assertSame([], $sale['rows']);
        $this->assertEqualsCanonicalizing(self::TOTALS_KEYS, array_keys($sale['totals']));
        $this->assertSame(0, $sale['totals']['totalQuantity']);
        $this->assertSame(0.0, $sale['totals']['totalVolume']);
        $this->assertSame(0.0, $sale['totals']['totalValue']);
        $this->assertSame([], $sale['totals']['missingPriceTypeIds']);
        $this->assertSame([], $sale['totals']['missingVolumeTypeIds']);
    }

    public function testOnlyThePersonalAssetsOfTheGivenUserAreRead(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [self::COMPRESSED_CLEAR_ICICLE => 7],
            [self::COMPRESSED_CLEAR_ICICLE => 175_000.0],
            [self::COMPRESSED_CLEAR_ICICLE => 180_000.0],
        )->build($user, null);

        $this->assertSame([[self::COMPRESSED_CLEAR_ICICLE, 7]], array_map(
            static fn (array $row): array => [$row['typeId'], $row['quantity']],
            $sale['rows'],
        ));
    }

    public function testVeryLargeHeldQuantitiesAreSummedAsIntegers(): void
    {
        $user = new User();
        $sale = $this->createOreSaleService(
            $user,
            [self::COMPRESSED_VELDSPAR => 9_007_199_254_740_993, self::COMPRESSED_CLEAR_ICICLE => 2],
            [self::COMPRESSED_VELDSPAR => 17.0, self::COMPRESSED_CLEAR_ICICLE => 175_000.0],
            [self::COMPRESSED_VELDSPAR => 19.0, self::COMPRESSED_CLEAR_ICICLE => 180_000.0],
        )->build($user, null);

        $this->assertSame(9_007_199_254_740_993, $this->rowOf($sale['rows'], self::COMPRESSED_VELDSPAR)['quantity']);
        $this->assertSame(9_007_199_254_740_995, $sale['totals']['totalQuantity']);
    }

    private function onlyRow(array $rows): array
    {
        $this->assertCount(1, $rows);

        return $rows[0];
    }

    private function rowOf(array $rows, int $typeId): array
    {
        $matchingRows = array_values(array_filter($rows, static fn (array $row): bool => $row['typeId'] === $typeId));
        $this->assertCount(1, $matchingRows);

        return $matchingRows[0];
    }

    private function assertRowEquals(array $expected, array $row): void
    {
        $this->assertEqualsCanonicalizing(self::ROW_KEYS, array_keys($row));
        $this->assertValuesEqual($expected, $row);
    }

    private function assertTotalsEqual(array $expected, array $totals): void
    {
        $this->assertEqualsCanonicalizing(self::TOTALS_KEYS, array_keys($totals));
        $this->assertValuesEqual($expected, $totals);
    }

    private function assertValuesEqual(array $expected, array $actual): void
    {
        foreach ($expected as $key => $expectedValue) {
            if (is_float($expectedValue)) {
                $this->assertIsFloat($actual[$key], $key);
                $this->assertEqualsWithDelta($expectedValue, $actual[$key], self::VALUE_DELTA, $key);
                continue;
            }
            $this->assertSame($expectedValue, $actual[$key], $key);
        }
    }
}
