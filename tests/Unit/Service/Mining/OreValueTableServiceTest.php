<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Enum\MarketSide;
use App\Service\OreValueTableService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(OreValueTableService::class)]
#[Group('issue-268')]
final class OreValueTableServiceTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;

    private const int BATCH_COMPRESSED_VELDSPAR = 28432;
    private const int CONCENTRATED_VELDSPAR = 17470;
    private const int DENSE_VELDSPAR = 17471;
    private const int MARKET_STRUCTURE_ID = 1_000_000_000_001;
    private const int UNSYNCED_STRUCTURE_ID = 1_000_000_000_002;
    private const float REPROCESS_YIELD = 0.78;
    private const float VALUE_DELTA = 1e-9;
    private const array ROW_KEYS = [
        'typeId', 'typeName', 'kind', 'groupName', 'volume',
        'rawIskPerM3', 'compressedIskPerM3', 'reprocessedIskPerM3',
        'bestOption', 'bestIskPerM3', 'gapToBestPercent', 'reprocessable', 'minerals',
    ];

    public function testVeldsparOnJitaSellIsWorthMostCompressedPerMinedCubicMetre(): void
    {
        $rows = $this->tableOnJitaSell([self::VELDSPAR], self::REPROCESS_YIELD);

        $this->assertRowEquals([
            'typeId' => self::VELDSPAR,
            'typeName' => 'Veldspar',
            'kind' => 'ore',
            'groupName' => 'Veldspar',
            'volume' => 0.1,
            'rawIskPerM3' => 183.7,
            'compressedIskPerM3' => 196.1,
            'reprocessedIskPerM3' => 133.224,
            'bestOption' => 'compressed',
            'bestIskPerM3' => 196.1,
            'gapToBestPercent' => 0.0,
            'reprocessable' => true,
            'minerals' => [['typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'quantityPerUnit' => 4.0]],
        ], $this->onlyRow($rows));
    }

    public function testClearIcicleIsValuedPerMinedCubicMetreAndItsReprocessedValueIsMissingWithoutIceProductPrices(): void
    {
        $rows = $this->tableOnJitaSell([self::CLEAR_ICICLE], self::REPROCESS_YIELD);

        $this->assertRowEquals([
            'typeId' => self::CLEAR_ICICLE,
            'typeName' => 'Clear Icicle',
            'kind' => 'ice',
            'groupName' => 'Ice',
            'volume' => 1000.0,
            'rawIskPerM3' => 152.3,
            'compressedIskPerM3' => 158.7,
            'reprocessedIskPerM3' => null,
            'bestOption' => 'compressed',
            'bestIskPerM3' => 158.7,
            'gapToBestPercent' => 0.0,
            'reprocessable' => true,
            'minerals' => [
                ['typeId' => self::HEAVY_WATER, 'typeName' => 'Heavy Water', 'quantityPerUnit' => 69.0],
                ['typeId' => self::LIQUID_OZONE, 'typeName' => 'Liquid Ozone', 'quantityPerUnit' => 35.0],
                ['typeId' => self::HELIUM_ISOTOPES, 'typeName' => 'Helium Isotopes', 'quantityPerUnit' => 414.0],
                ['typeId' => self::STRONTIUM_CLATHRATES, 'typeName' => 'Strontium Clathrates', 'quantityPerUnit' => 1.0],
            ],
        ], $this->onlyRow($rows));
    }

    public function testFulleriteC50IsAGasWorthMostCompressedAndCannotBeReprocessed(): void
    {
        $rows = $this->tableOnJitaSell([self::FULLERITE_C50], self::REPROCESS_YIELD);

        $this->assertRowEquals([
            'typeId' => self::FULLERITE_C50,
            'typeName' => 'Fullerite-C50',
            'kind' => 'gas',
            'groupName' => 'Harvestable Cloud',
            'volume' => 1.0,
            'rawIskPerM3' => 2741.0,
            'compressedIskPerM3' => 2813.0,
            'reprocessedIskPerM3' => null,
            'bestOption' => 'compressed',
            'bestIskPerM3' => 2813.0,
            'gapToBestPercent' => 0.0,
            'reprocessable' => false,
            'minerals' => [],
        ], $this->onlyRow($rows));
    }

    public function testFulleriteC50WithoutACompressedPriceIsWorthItsRawPrice(): void
    {
        $jitaSellPrices = $this->jitaSellPricesOfTheSpecExamples();
        unset($jitaSellPrices[self::COMPRESSED_FULLERITE_C50]);

        $fullerite = $this->onlyRow(
            $this->createOreValueTableService($jitaSellPrices, [self::FULLERITE_C50])
                ->build('jita', MarketSide::Sell, null, self::REPROCESS_YIELD),
        );

        $this->assertEqualsWithDelta(2741.0, $fullerite['rawIskPerM3'], self::VALUE_DELTA);
        $this->assertNull($fullerite['compressedIskPerM3']);
        $this->assertNull($fullerite['reprocessedIskPerM3']);
        $this->assertFalse($fullerite['reprocessable']);
        $this->assertSame('raw', $fullerite['bestOption']);
        $this->assertEqualsWithDelta(2741.0, $fullerite['bestIskPerM3'], self::VALUE_DELTA);
    }

    public function testReprocessedValueIsUnknownWithoutReprocessYieldAndCompressedStaysTheBestOption(): void
    {
        $veldspar = $this->onlyRow($this->tableOnJitaSell([self::VELDSPAR], null));

        $this->assertNull($veldspar['reprocessedIskPerM3']);
        $this->assertTrue($veldspar['reprocessable']);
        $this->assertEqualsWithDelta(183.7, $veldspar['rawIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(196.1, $veldspar['compressedIskPerM3'], self::VALUE_DELTA);
        $this->assertSame('compressed', $veldspar['bestOption']);
        $this->assertEqualsWithDelta(196.1, $veldspar['bestIskPerM3'], self::VALUE_DELTA);
    }

    public function testJitaBuySideValuesTheOreAndItsMineralsAtTheBestJitaBuyOrders(): void
    {
        $oreValueTableService = $this->createOreValueTableService(
            $this->jitaSellPricesOfTheSpecExamples(),
            [self::VELDSPAR],
            [self::VELDSPAR => 17.5, self::COMPRESSED_VELDSPAR => 18.0, self::TRITANIUM => 4.0],
        );

        $veldspar = $this->onlyRow($oreValueTableService->build('jita', MarketSide::Buy, null, self::REPROCESS_YIELD));

        $this->assertEqualsWithDelta(175.0, $veldspar['rawIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(180.0, $veldspar['compressedIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(124.8, $veldspar['reprocessedIskPerM3'], self::VALUE_DELTA);
        $this->assertSame('compressed', $veldspar['bestOption']);
        $this->assertEqualsWithDelta(180.0, $veldspar['bestIskPerM3'], self::VALUE_DELTA);
    }

    public function testStructureSellSideIgnoresJitaPricesAndValuesTheCompressedOreAtTheStructure(): void
    {
        $oreValueTableService = $this->createOreValueTableService(
            $this->jitaSellPricesOfTheSpecExamples(),
            [self::VELDSPAR],
            structureMarketService: $this->structureMarketTrading(
                self::MARKET_STRUCTURE_ID,
                [self::COMPRESSED_VELDSPAR => 1100.0, self::TRITANIUM => 4.6],
                [self::VELDSPAR => 15.8, self::COMPRESSED_VELDSPAR => 1050.0, self::TRITANIUM => 3.7],
            ),
        );

        $veldspar = $this->onlyRow($oreValueTableService->build('structure', MarketSide::Sell, self::MARKET_STRUCTURE_ID, self::REPROCESS_YIELD));

        $this->assertNull($veldspar['rawIskPerM3']);
        $this->assertEqualsWithDelta(11000.0, $veldspar['compressedIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(143.52, $veldspar['reprocessedIskPerM3'], self::VALUE_DELTA);
        $this->assertSame('compressed', $veldspar['bestOption']);
        $this->assertEqualsWithDelta(11000.0, $veldspar['bestIskPerM3'], self::VALUE_DELTA);
    }

    public function testStructureBuySideValuesTheOreAndItsMineralsAtTheHighestStructureBuyOrders(): void
    {
        $oreValueTableService = $this->createOreValueTableService(
            $this->jitaSellPricesOfTheSpecExamples(),
            [self::VELDSPAR],
            [self::VELDSPAR => 17.5, self::COMPRESSED_VELDSPAR => 18.0, self::TRITANIUM => 4.0],
            $this->structureMarketTrading(
                self::MARKET_STRUCTURE_ID,
                [self::VELDSPAR => 19.2, self::COMPRESSED_VELDSPAR => 20.4, self::TRITANIUM => 4.9],
                [self::VELDSPAR => 16.9, self::COMPRESSED_VELDSPAR => 17.2, self::TRITANIUM => 3.85],
            ),
        );

        $veldspar = $this->onlyRow($oreValueTableService->build('structure', MarketSide::Buy, self::MARKET_STRUCTURE_ID, self::REPROCESS_YIELD));

        $this->assertEqualsWithDelta(169.0, $veldspar['rawIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(172.0, $veldspar['compressedIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(120.12, $veldspar['reprocessedIskPerM3'], self::VALUE_DELTA);
        $this->assertSame('compressed', $veldspar['bestOption']);
        $this->assertEqualsWithDelta(172.0, $veldspar['bestIskPerM3'], self::VALUE_DELTA);
    }

    public function testEveryValueIsUnknownOnAStructureWhoseMarketIsNotSynchronised(): void
    {
        $oreValueTableService = $this->createOreValueTableService(
            $this->jitaSellPricesOfTheSpecExamples(),
            [self::VELDSPAR, self::FULLERITE_C50],
            structureMarketService: $this->structureMarketTrading(
                self::MARKET_STRUCTURE_ID,
                [self::VELDSPAR => 19.2, self::COMPRESSED_VELDSPAR => 20.4, self::TRITANIUM => 4.9, self::FULLERITE_C50 => 2690.0],
                [],
            ),
        );

        $rows = $oreValueTableService->build('structure', MarketSide::Sell, self::UNSYNCED_STRUCTURE_ID, self::REPROCESS_YIELD);

        $this->assertSame(
            [
                [self::FULLERITE_C50, null, null, null, null, null, null],
                [self::VELDSPAR, null, null, null, null, null, null],
            ],
            $this->unknownValuesOf($rows),
        );
    }

    public function testVeldsparWithoutAnyPriceHasNoValueNoBestOptionAndNoGap(): void
    {
        $oreValueTableService = $this->createOreValueTableService([], [self::VELDSPAR]);

        $veldspar = $this->onlyRow($oreValueTableService->build('jita', MarketSide::Sell, null, self::REPROCESS_YIELD));

        $this->assertSame(
            [self::VELDSPAR, null, null, null, null, null, null],
            $this->unknownValuesOf([$veldspar])[0],
        );
        $this->assertTrue($veldspar['reprocessable']);
    }

    #[DataProvider('volumesThatCannotBeMined')]
    public function testEveryValuePerMinedCubicMetreIsUnknownWithoutAPositiveSdeVolume(?float $volume): void
    {
        $sdeTypes = $this->sdeTypes();
        $sdeTypes[self::VELDSPAR]['volume'] = $volume;
        $oreValueTableService = $this->createOreValueTableService(
            $this->jitaSellPricesOfTheSpecExamples(),
            [self::VELDSPAR],
            sdeTypes: $sdeTypes,
        );

        $veldspar = $this->onlyRow($oreValueTableService->build('jita', MarketSide::Sell, null, self::REPROCESS_YIELD));

        $this->assertSame(
            [self::VELDSPAR, null, null, null, null, null, null],
            $this->unknownValuesOf([$veldspar])[0],
        );
        $this->assertSame($volume, $veldspar['volume']);
    }

    public static function volumesThatCannotBeMined(): iterable
    {
        yield 'volume missing from the SDE' => [null];
        yield 'volume of zero' => [0.0];
    }

    public function testReprocessedValueStaysKnownWhenTheCompressedOreHasNoPrice(): void
    {
        $jitaSellPrices = $this->jitaSellPricesOfTheSpecExamples();
        unset($jitaSellPrices[self::COMPRESSED_VELDSPAR]);

        $veldspar = $this->onlyRow(
            $this->createOreValueTableService($jitaSellPrices, [self::VELDSPAR])
                ->build('jita', MarketSide::Sell, null, self::REPROCESS_YIELD),
        );

        $this->assertNull($veldspar['compressedIskPerM3']);
        $this->assertEqualsWithDelta(183.7, $veldspar['rawIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(133.224, $veldspar['reprocessedIskPerM3'], self::VALUE_DELTA);
        $this->assertSame('raw', $veldspar['bestOption']);
        $this->assertEqualsWithDelta(183.7, $veldspar['bestIskPerM3'], self::VALUE_DELTA);
    }

    public function testGapToBestIsMeasuredAgainstTheBestValueOfTheWholeTable(): void
    {
        $rows = $this->tableOnJitaSell([self::CLEAR_ICICLE, self::VELDSPAR], self::REPROCESS_YIELD);

        $this->assertSame([self::VELDSPAR, self::CLEAR_ICICLE], array_column($rows, 'typeId'));
        $this->assertEqualsWithDelta(0.0, $rows[0]['gapToBestPercent'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(19.071902090770017, $rows[1]['gapToBestPercent'], self::VALUE_DELTA);
    }

    public function testRowsAreSortedByBestValueWithUnknownValuesLastAndEqualValuesByName(): void
    {
        $jitaSellPrices = $this->jitaSellPricesOfTheSpecExamples();
        $reprocessMaterials = $this->sdeReprocessMaterials();
        $reprocessMaterials[self::CONCENTRATED_VELDSPAR] = [self::TRITANIUM => 420];
        $reprocessMaterials[self::DENSE_VELDSPAR] = [self::TRITANIUM => 440];

        $rows = $this->createOreValueTableService(
            $jitaSellPrices,
            [self::DENSE_VELDSPAR, self::CLEAR_ICICLE, self::CONCENTRATED_VELDSPAR, self::VELDSPAR, self::FULLERITE_C50],
            sdeTypes: $this->sdeTypesWithVeldsparVariants(),
            reprocessMaterials: $reprocessMaterials,
        )->build('jita', MarketSide::Sell, null, null);

        $this->assertSame(
            [self::FULLERITE_C50, self::VELDSPAR, self::CLEAR_ICICLE, self::CONCENTRATED_VELDSPAR, self::DENSE_VELDSPAR],
            array_column($rows, 'typeId'),
        );
        $this->assertEqualsWithDelta(2813.0, $rows[0]['bestIskPerM3'], self::VALUE_DELTA);
        $this->assertNull($rows[3]['bestIskPerM3']);
        $this->assertNull($rows[4]['bestIskPerM3']);
    }

    public function testRowsWithTheSameBestValueAreSortedByName(): void
    {
        $rows = $this->createOreValueTableService(
            [self::FULLERITE_C50 => 158.7, self::COMPRESSED_CLEAR_ICICLE => 158700.0],
            [self::FULLERITE_C50, self::CLEAR_ICICLE],
        )->build('jita', MarketSide::Sell, null, self::REPROCESS_YIELD);

        $this->assertSame([self::CLEAR_ICICLE, self::FULLERITE_C50], array_column($rows, 'typeId'));
        $this->assertEqualsWithDelta(158.7, $rows[0]['bestIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(158.7, $rows[1]['bestIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(0.0, $rows[1]['gapToBestPercent'], self::VALUE_DELTA);
    }

    public function testReprocessedIsTheBestOptionWhenItEqualsTheRawValue(): void
    {
        $veldspar = $this->onlyRow(
            $this->createOreValueTableService([self::VELDSPAR => 16.0, self::TRITANIUM => 16.0], [self::VELDSPAR])
                ->build('jita', MarketSide::Sell, null, 0.25),
        );

        $this->assertEqualsWithDelta(160.0, $veldspar['rawIskPerM3'], self::VALUE_DELTA);
        $this->assertEqualsWithDelta(160.0, $veldspar['reprocessedIskPerM3'], self::VALUE_DELTA);
        $this->assertSame('reprocessed', $veldspar['bestOption']);
        $this->assertEqualsWithDelta(160.0, $veldspar['bestIskPerM3'], self::VALUE_DELTA);
    }

    public function testCompressedIsTheBestOptionWhenAllThreeValuesAreEqual(): void
    {
        $veldspar = $this->onlyRow(
            $this->createOreValueTableService(
                [self::VELDSPAR => 16.0, self::COMPRESSED_VELDSPAR => 16.0, self::TRITANIUM => 16.0],
                [self::VELDSPAR],
            )->build('jita', MarketSide::Sell, null, 0.25),
        );

        $this->assertEqualsWithDelta(160.0, $veldspar['compressedIskPerM3'], self::VALUE_DELTA);
        $this->assertSame('compressed', $veldspar['bestOption']);
    }

    public function testARawPriceOfZeroIsAKnownValueOfZeroWithoutGapToBest(): void
    {
        $veldspar = $this->onlyRow(
            $this->createOreValueTableService([self::VELDSPAR => 0.0, self::TRITANIUM => 4.27], [self::VELDSPAR])
                ->build('jita', MarketSide::Sell, null, null),
        );

        $this->assertSame(0.0, $veldspar['rawIskPerM3']);
        $this->assertNull($veldspar['compressedIskPerM3']);
        $this->assertSame('raw', $veldspar['bestOption']);
        $this->assertSame(0.0, $veldspar['bestIskPerM3']);
        $this->assertNull($veldspar['gapToBestPercent']);
    }

    public function testARowWithAnUnknownValueHasNoGapToTheBestValueOfTheTable(): void
    {
        $rows = $this->createOreValueTableService(
            [self::FULLERITE_C50 => 2741.0, self::COMPRESSED_FULLERITE_C50 => 2813.0],
            [self::BITUMENS, self::FULLERITE_C50],
        )->build('jita', MarketSide::Sell, null, null);

        $this->assertSame([self::FULLERITE_C50, self::BITUMENS], array_column($rows, 'typeId'));
        $this->assertSame(0.0, $rows[0]['gapToBestPercent']);
        $this->assertNull($rows[1]['bestIskPerM3']);
        $this->assertNull($rows[1]['gapToBestPercent']);
    }

    public function testARowWorthZeroIsSortedBeforeARowWithAnUnknownValueEvenWhenItsNameComesLater(): void
    {
        $rows = $this->createOreValueTableService(
            [self::VELDSPAR => 0.0],
            [self::BITUMENS, self::VELDSPAR],
        )->build('jita', MarketSide::Sell, null, null);

        $this->assertSame([self::VELDSPAR, self::BITUMENS], array_column($rows, 'typeId'));
        $this->assertSame(0.0, $rows[0]['bestIskPerM3']);
        $this->assertNull($rows[1]['bestIskPerM3']);
    }

    #[DataProvider('portionSizesThatCannotBeReprocessed')]
    public function testMineralQuantitiesPerUnitAreUnknownWithoutAPositiveSdePortionSize(?int $portionSize): void
    {
        $sdeTypes = $this->sdeTypes();
        $sdeTypes[self::VELDSPAR]['portionSize'] = $portionSize;

        $veldspar = $this->onlyRow(
            $this->createOreValueTableService([self::VELDSPAR => 18.37], [self::VELDSPAR], sdeTypes: $sdeTypes)
                ->build('jita', MarketSide::Sell, null, self::REPROCESS_YIELD),
        );

        $this->assertTrue($veldspar['reprocessable']);
        $this->assertSame(
            [['typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'quantityPerUnit' => null]],
            $veldspar['minerals'],
        );
        $this->assertEqualsWithDelta(183.7, $veldspar['rawIskPerM3'], self::VALUE_DELTA);
    }

    public static function portionSizesThatCannotBeReprocessed(): iterable
    {
        yield 'portion size missing from the SDE' => [null];
        yield 'portion size of zero' => [0];
    }

    public function testCompressedOresAreNotRowsButOreVariantsAre(): void
    {
        $jitaSellPrices = $this->jitaSellPricesOfTheSpecExamples() + [self::CONCENTRATED_VELDSPAR => 19.3, self::BATCH_COMPRESSED_VELDSPAR => 1975.0];

        $rows = $this->createOreValueTableService(
            $jitaSellPrices,
            [self::COMPRESSED_VELDSPAR, self::VELDSPAR, self::BATCH_COMPRESSED_VELDSPAR, self::CONCENTRATED_VELDSPAR],
            sdeTypes: $this->sdeTypesWithVeldsparVariants(),
        )->build('jita', MarketSide::Sell, null, null);

        $this->assertSame([self::VELDSPAR, self::CONCENTRATED_VELDSPAR], array_column($rows, 'typeId'));
        $this->assertEqualsWithDelta(193.0, $rows[1]['rawIskPerM3'], self::VALUE_DELTA);
    }

    public function testMineralsAreTheSdeQuantitiesPerRawUnitWithAnUnknownNameForATypeMissingFromTheSde(): void
    {
        $reprocessMaterials = $this->sdeReprocessMaterials();
        $reprocessMaterials[self::VELDSPAR] = [self::TRITANIUM => 400, self::TYPE_UNKNOWN_TO_SDE => 7];

        $veldspar = $this->onlyRow(
            $this->createOreValueTableService(
                $this->jitaSellPricesOfTheSpecExamples(),
                [self::VELDSPAR],
                reprocessMaterials: $reprocessMaterials,
            )->build('jita', MarketSide::Sell, null, self::REPROCESS_YIELD),
        );

        $this->assertMineralsEqual([
            ['typeId' => self::TRITANIUM, 'typeName' => 'Tritanium', 'quantityPerUnit' => 4.0],
            ['typeId' => self::TYPE_UNKNOWN_TO_SDE, 'typeName' => null, 'quantityPerUnit' => 0.07],
        ], $veldspar['minerals']);
    }

    private function jitaSellPricesOfTheSpecExamples(): array
    {
        return [
            self::VELDSPAR => 18.37,
            self::COMPRESSED_VELDSPAR => 19.61,
            self::TRITANIUM => 4.27,
            self::CLEAR_ICICLE => 152300.0,
            self::COMPRESSED_CLEAR_ICICLE => 158700.0,
            self::FULLERITE_C50 => 2741.0,
            self::COMPRESSED_FULLERITE_C50 => 2813.0,
        ];
    }

    private function sdeTypesWithVeldsparVariants(): array
    {
        return $this->sdeTypes() + [
            self::BATCH_COMPRESSED_VELDSPAR => ['name' => 'Batch Compressed Veldspar', 'volume' => 0.15, 'portionSize' => 1, 'group' => [462, 'Veldspar', 25]],
            self::CONCENTRATED_VELDSPAR => ['name' => 'Concentrated Veldspar', 'volume' => 0.1, 'portionSize' => 100, 'group' => [462, 'Veldspar', 25]],
            self::DENSE_VELDSPAR => ['name' => 'Dense Veldspar', 'volume' => 0.1, 'portionSize' => 100, 'group' => [462, 'Veldspar', 25]],
        ];
    }

    private function tableOnJitaSell(array $mineableCandidateIds, ?float $reprocessYield): array
    {
        return $this->createOreValueTableService($this->jitaSellPricesOfTheSpecExamples(), $mineableCandidateIds)
            ->build('jita', MarketSide::Sell, null, $reprocessYield);
    }

    private function onlyRow(array $rows): array
    {
        $this->assertCount(1, $rows);

        return $rows[0];
    }

    private function unknownValuesOf(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            $row['typeId'],
            $row['rawIskPerM3'],
            $row['compressedIskPerM3'],
            $row['reprocessedIskPerM3'],
            $row['bestOption'],
            $row['bestIskPerM3'],
            $row['gapToBestPercent'],
        ], $rows);
    }

    private function assertRowEquals(array $expected, array $row): void
    {
        $this->assertEqualsCanonicalizing(self::ROW_KEYS, array_keys($row));
        foreach ($expected as $key => $expectedValue) {
            match (true) {
                $key === 'minerals' => $this->assertMineralsEqual($expectedValue, $row[$key]),
                is_float($expectedValue) => $this->assertEqualsWithDelta($expectedValue, $row[$key], self::VALUE_DELTA, $key),
                default => $this->assertSame($expectedValue, $row[$key], $key),
            };
        }
    }

    private function assertMineralsEqual(array $expectedMinerals, array $minerals): void
    {
        $this->assertSame(
            array_map(static fn (array $mineral): array => [$mineral['typeId'], $mineral['typeName']], $expectedMinerals),
            array_map(static fn (array $mineral): array => [$mineral['typeId'], $mineral['typeName']], $minerals),
        );
        foreach ($expectedMinerals as $index => $expectedMineral) {
            $this->assertEqualsCanonicalizing(['typeId', 'typeName', 'quantityPerUnit'], array_keys($minerals[$index]));
            $this->assertEqualsWithDelta($expectedMineral['quantityPerUnit'], $minerals[$index]['quantityPerUnit'], self::VALUE_DELTA);
        }
    }
}
