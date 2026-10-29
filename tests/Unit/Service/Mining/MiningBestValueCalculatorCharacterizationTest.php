<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Entity\MiningEntry;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use App\Service\MiningBestValueCalculator;
use App\Service\StructureMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningBestValueCalculator::class)]
final class MiningBestValueCalculatorCharacterizationTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;

    private const float PRICE_DELTA = 1e-6;
    private const int TYPE_WITHOUT_ANY_PRICE = 81143;
    private const int OTHER_TYPE_WITHOUT_ANY_PRICE = 81144;
    private const float BITUMENS_REPROCESS_VALUE = 831.2148;
    private const float CLEAR_ICICLE_REPROCESS_VALUE = 215852.598;
    private const float FULLERITE_C50_RAW_PRICE = 2741.0;

    private User $user;
    private \DateTimeImmutable $from;
    private \DateTimeImmutable $to;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->from = new \DateTimeImmutable('2026-10-01');
        $this->to = new \DateTimeImmutable('2026-10-10');
    }

    public function testReturnsNoBestUnitPricesForNoTypeIdsWithoutLookingUpPrices(): void
    {
        $calculator = new MiningBestValueCalculator(
            $this->createStub(MiningEntryRepository::class),
            $this->oreValueServiceThatMustNotLookUpAnything(),
        );

        $this->assertSame([], $calculator->getBestUnitPrices([]));
    }

    #[Group('issue-38')]
    public function testBestUnitPriceIsTheReprocessValueAboveTheRawPriceCurrentlyAtTheDefaultYieldIssue38(): void
    {
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class));

        $bestUnitPrices = $calculator->getBestUnitPrices([self::BITUMENS, self::CLEAR_ICICLE]);

        $this->assertSame([self::BITUMENS, self::CLEAR_ICICLE], array_keys($bestUnitPrices));
        $this->assertEqualsWithDelta(self::BITUMENS_REPROCESS_VALUE, $bestUnitPrices[self::BITUMENS], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(self::CLEAR_ICICLE_REPROCESS_VALUE, $bestUnitPrices[self::CLEAR_ICICLE], self::PRICE_DELTA);
    }

    public function testBestUnitPriceIsTheRawPriceWithoutAnyOtherSource(): void
    {
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class));

        $bestUnitPrices = $calculator->getBestUnitPrices([self::FULLERITE_C50, self::TRITANIUM, self::TYPE_UNKNOWN_TO_SDE]);

        $this->assertSame([self::FULLERITE_C50, self::TRITANIUM, self::TYPE_UNKNOWN_TO_SDE], array_keys($bestUnitPrices));
        $this->assertEqualsWithDelta(self::FULLERITE_C50_RAW_PRICE, $bestUnitPrices[self::FULLERITE_C50], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(4.27, $bestUnitPrices[self::TRITANIUM], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(1093.5, $bestUnitPrices[self::TYPE_UNKNOWN_TO_SDE], self::PRICE_DELTA);
    }

    #[Group('mining-audit-P1-1')]
    #[Group('issue-259')]
    public function testBestUnitPriceWeighsCompressedOreAtItsFullCompressedEquivalentPrice(): void
    {
        $jitaSellPrices = $this->jitaSellPrices();
        $jitaSellPrices[self::VELDSPAR] = 12.4;
        unset($jitaSellPrices[self::FULLERITE_C50]);
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class), $jitaSellPrices);

        $bestUnitPrices = $calculator->getBestUnitPrices([self::VELDSPAR, self::FULLERITE_C50]);

        $this->assertEqualsWithDelta(19.61, $bestUnitPrices[self::VELDSPAR], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(2813.0, $bestUnitPrices[self::FULLERITE_C50], self::PRICE_DELTA);
    }

    #[Group('issue-259')]
    public function testBestUnitPriceKeepsTheReprocessValueWhenTheCompressedOreSellsLower(): void
    {
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class), $this->jitaSellPrices());

        $bestUnitPrices = $calculator->getBestUnitPrices([self::BITUMENS]);

        $this->assertEqualsWithDelta(self::BITUMENS_REPROCESS_VALUE, $bestUnitPrices[self::BITUMENS], self::PRICE_DELTA);
    }

    #[Group('mining-audit-P2-5')]
    public function testBestUnitPriceCurrentlyIgnoresAHigherRawPriceWhenAnotherSourceExistsMiningAuditP2_5(): void
    {
        $jitaSellPrices = $this->jitaSellPricesWithoutCompressedOre();
        $jitaSellPrices[self::CLEAR_ICICLE] = 231400.0;
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class), $jitaSellPrices);

        $bestUnitPrices = $calculator->getBestUnitPrices([self::CLEAR_ICICLE]);

        $this->assertEqualsWithDelta(self::CLEAR_ICICLE_REPROCESS_VALUE, $bestUnitPrices[self::CLEAR_ICICLE], self::PRICE_DELTA);
    }

    #[Group('mining-audit-P2-5')]
    #[Group('issue-260')]
    public function testAnOreWithoutAnyPriceMakesItsMiningValueUnknown(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::FULLERITE_C50 => ['typeId' => self::FULLERITE_C50, 'quantity' => 2290],
            self::TYPE_WITHOUT_ANY_PRICE => ['typeId' => self::TYPE_WITHOUT_ANY_PRICE, 'quantity' => 3150],
        ]);
        $miningEntryRepository->method('getQuantitiesByTypeAndDate')->willReturn([
            '2026-10-09' => [self::TYPE_WITHOUT_ANY_PRICE => 3150],
        ]);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([
            MiningEntry::USAGE_SOLD => [self::TYPE_WITHOUT_ANY_PRICE => 3150],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $this->assertSame([self::TYPE_WITHOUT_ANY_PRICE => null], $calculator->getBestUnitPrices([self::TYPE_WITHOUT_ANY_PRICE]));
        $this->assertMiningValue(null, [self::TYPE_WITHOUT_ANY_PRICE], $calculator->getTotalBestValue($this->user, $this->from, $this->to));
        $this->assertDailyMiningValues(
            ['2026-10-09' => null],
            [self::TYPE_WITHOUT_ANY_PRICE],
            $calculator->getDailyBestValues($this->user, $this->from, $this->to),
        );
        $this->assertTotalsByUsage(
            [MiningEntry::USAGE_SOLD => [null, 3150, [self::TYPE_WITHOUT_ANY_PRICE]]],
            $calculator->getTotalsByUsageBestValue($this->user, $this->from, $this->to),
        );
    }

    #[Group('issue-38')]
    public function testBestUnitPriceCurrentlyIgnoresTheStructureMarketAndUsesTheDefaultYieldIssue38(): void
    {
        $structureMarketService = $this->createMock(StructureMarketService::class);
        $structureMarketService->expects($this->never())->method('getLowestSellPrices');
        $calculator = new MiningBestValueCalculator(
            $this->createStub(MiningEntryRepository::class),
            $this->createOreValueService($this->jitaSellPricesWithoutCompressedOre(), $structureMarketService),
        );

        $bestUnitPrices = $calculator->getBestUnitPrices([self::BITUMENS]);

        $this->assertEqualsWithDelta(self::BITUMENS_REPROCESS_VALUE, $bestUnitPrices[self::BITUMENS], self::PRICE_DELTA);
    }

    public function testTotalBestValueSumsBestUnitPriceTimesQuantityOverEveryType(): void
    {
        $excludedUsages = [MiningEntry::USAGE_CORP_PROJECT];
        $miningEntryRepository = $this->createMock(MiningEntryRepository::class);
        $miningEntryRepository->expects($this->once())
            ->method('getQuantitiesByType')
            ->with($this->user, $this->from, $this->to, $excludedUsages)
            ->willReturn([
                self::FULLERITE_C50 => ['typeId' => self::FULLERITE_C50, 'quantity' => 2290],
                self::TRITANIUM => ['typeId' => self::TRITANIUM, 'quantity' => 48150],
                self::TYPE_UNKNOWN_TO_SDE => ['typeId' => self::TYPE_UNKNOWN_TO_SDE, 'quantity' => 375],
            ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $totalBestValue = $calculator->getTotalBestValue($this->user, $this->from, $this->to, $excludedUsages);

        $this->assertMiningValue(6892553.0, [], $totalBestValue);
    }

    public function testTotalBestValueIsZeroWithoutMinedQuantityNorPriceLookup(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([]);
        $calculator = new MiningBestValueCalculator($miningEntryRepository, $this->oreValueServiceThatMustNotLookUpAnything());

        $this->assertSame(
            ['totalValue' => 0.0, 'missingPriceTypeIds' => []],
            $calculator->getTotalBestValue($this->user, $this->from, $this->to),
        );
    }

    public function testDailyBestValuesSumBestUnitPriceTimesQuantityPerDay(): void
    {
        $excludedUsages = [MiningEntry::USAGE_INDUSTRY];
        $miningEntryRepository = $this->createMock(MiningEntryRepository::class);
        $miningEntryRepository->expects($this->once())
            ->method('getQuantitiesByTypeAndDate')
            ->with($this->user, $this->from, $this->to, $excludedUsages)
            ->willReturn([
                '2026-10-09' => [self::FULLERITE_C50 => 615, self::TRITANIUM => 13720],
                '2026-10-10' => [self::TYPE_UNKNOWN_TO_SDE => 182, self::FULLERITE_C50 => 1044],
            ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $dailyBestValues = $calculator->getDailyBestValues($this->user, $this->from, $this->to, $excludedUsages);

        $this->assertDailyMiningValues(['2026-10-09' => 1744299.4, '2026-10-10' => 3060621.0], [], $dailyBestValues);
    }

    public function testDailyBestValuesAreEmptyWithoutMinedQuantityNorPriceLookup(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndDate')->willReturn([]);
        $calculator = new MiningBestValueCalculator($miningEntryRepository, $this->oreValueServiceThatMustNotLookUpAnything());

        $this->assertSame(
            ['byDate' => [], 'missingPriceTypeIds' => []],
            $calculator->getDailyBestValues($this->user, $this->from, $this->to),
        );
    }

    public function testTotalsByUsageSumBestValueAndQuantityPerUsage(): void
    {
        $miningEntryRepository = $this->createMock(MiningEntryRepository::class);
        $miningEntryRepository->expects($this->once())
            ->method('getQuantitiesByTypeAndUsage')
            ->with($this->user, $this->from, $this->to)
            ->willReturn([
                MiningEntry::USAGE_SOLD => [self::TRITANIUM => 26930, self::TYPE_UNKNOWN_TO_SDE => 211],
                MiningEntry::USAGE_INDUSTRY => [self::FULLERITE_C50 => 870, self::TRITANIUM => 9410],
            ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $totalsByUsage = $calculator->getTotalsByUsageBestValue($this->user, $this->from, $this->to);

        $this->assertTotalsByUsage(
            [
                MiningEntry::USAGE_SOLD => [345719.6, 27141, []],
                MiningEntry::USAGE_INDUSTRY => [2424850.7, 10280, []],
            ],
            $totalsByUsage,
        );
    }

    public function testTotalsByUsageAreEmptyWithoutMinedQuantityNorPriceLookup(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([]);
        $calculator = new MiningBestValueCalculator($miningEntryRepository, $this->oreValueServiceThatMustNotLookUpAnything());

        $this->assertSame([], $calculator->getTotalsByUsageBestValue($this->user, $this->from, $this->to));
    }

    #[Group('issue-260')]
    public function testBestUnitPriceIsUnknownForATypeWithoutAnyPriceSource(): void
    {
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class));

        $bestUnitPrices = $calculator->getBestUnitPrices([self::BITUMENS, self::TYPE_WITHOUT_ANY_PRICE]);

        $this->assertSame([self::BITUMENS, self::TYPE_WITHOUT_ANY_PRICE], array_keys($bestUnitPrices));
        $this->assertEqualsWithDelta(self::BITUMENS_REPROCESS_VALUE, $bestUnitPrices[self::BITUMENS], self::PRICE_DELTA);
        $this->assertNull($bestUnitPrices[self::TYPE_WITHOUT_ANY_PRICE]);
    }

    #[Group('issue-260')]
    public function testTotalMiningValueIsUnknownWhenAMinedTypeHasNoPrice(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::BITUMENS => ['typeId' => self::BITUMENS, 'quantity' => 1000],
            self::TYPE_WITHOUT_ANY_PRICE => ['typeId' => self::TYPE_WITHOUT_ANY_PRICE, 'quantity' => 3150],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $totalBestValue = $calculator->getTotalBestValue($this->user, $this->from, $this->to);

        $this->assertMiningValue(null, [self::TYPE_WITHOUT_ANY_PRICE], $totalBestValue);
    }

    #[Group('issue-260')]
    public function testTotalMiningValueIsKnownWhenEveryMinedTypeHasAPrice(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::BITUMENS => ['typeId' => self::BITUMENS, 'quantity' => 1000],
            self::TRITANIUM => ['typeId' => self::TRITANIUM, 'quantity' => 2500],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $totalBestValue = $calculator->getTotalBestValue($this->user, $this->from, $this->to);

        $this->assertMiningValue(841889.8, [], $totalBestValue);
    }

    #[Group('issue-260')]
    public function testDailyMiningValueIsUnknownOnlyOnTheDayHoldingATypeWithoutPrice(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndDate')->willReturn([
            '2026-10-09' => [self::BITUMENS => 1000],
            '2026-10-10' => [self::TRITANIUM => 2500, self::TYPE_WITHOUT_ANY_PRICE => 3150],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $dailyBestValues = $calculator->getDailyBestValues($this->user, $this->from, $this->to);

        $this->assertDailyMiningValues(
            ['2026-10-09' => 831214.8, '2026-10-10' => null],
            [self::TYPE_WITHOUT_ANY_PRICE],
            $dailyBestValues,
        );
    }

    #[Group('issue-260')]
    public function testUsageMiningValueIsUnknownOnlyForTheUsageHoldingATypeWithoutPrice(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([
            MiningEntry::USAGE_SOLD => [self::BITUMENS => 1000],
            MiningEntry::USAGE_INDUSTRY => [self::TRITANIUM => 2500, self::TYPE_WITHOUT_ANY_PRICE => 3150],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $totalsByUsage = $calculator->getTotalsByUsageBestValue($this->user, $this->from, $this->to);

        $this->assertTotalsByUsage(
            [
                MiningEntry::USAGE_SOLD => [831214.8, 1000, []],
                MiningEntry::USAGE_INDUSTRY => [null, 5650, [self::TYPE_WITHOUT_ANY_PRICE]],
            ],
            $totalsByUsage,
        );
    }

    #[Group('issue-260')]
    public function testATypeWithoutPriceMinedInZeroQuantityLeavesTheTotalKnownAndUnlisted(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::TYPE_WITHOUT_ANY_PRICE => ['typeId' => self::TYPE_WITHOUT_ANY_PRICE, 'quantity' => 0],
            self::TRITANIUM => ['typeId' => self::TRITANIUM, 'quantity' => 2500],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $totalBestValue = $calculator->getTotalBestValue($this->user, $this->from, $this->to);

        $this->assertMiningValue(10675.0, [], $totalBestValue);
    }

    #[Group('issue-260')]
    public function testATypeWithoutPriceMinedInZeroQuantityLeavesItsDayAndUsageKnown(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndDate')->willReturn([
            '2026-10-07' => [self::TYPE_WITHOUT_ANY_PRICE => 0, self::FULLERITE_C50 => 615],
        ]);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([
            MiningEntry::USAGE_UNKNOWN => [self::TYPE_WITHOUT_ANY_PRICE => 0, self::TRITANIUM => 1840],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $this->assertDailyMiningValues(
            ['2026-10-07' => 1685715.0],
            [],
            $calculator->getDailyBestValues($this->user, $this->from, $this->to),
        );
        $this->assertTotalsByUsage(
            [MiningEntry::USAGE_UNKNOWN => [7856.8, 1840, []]],
            $calculator->getTotalsByUsageBestValue($this->user, $this->from, $this->to),
        );
    }

    #[Group('issue-260')]
    public function testAMinedTypeAtZeroIskOnJitaIsAKnownZeroValue(): void
    {
        $jitaSellPrices = $this->jitaSellPricesWithoutCompressedOre();
        $jitaSellPrices[self::TRITANIUM] = 0.0;
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::TRITANIUM => ['typeId' => self::TRITANIUM, 'quantity' => 2500],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository, $jitaSellPrices);

        $totalBestValue = $calculator->getTotalBestValue($this->user, $this->from, $this->to);

        $this->assertMiningValue(0.0, [], $totalBestValue);
    }

    #[Group('issue-260')]
    public function testACompressedPriceAtZeroWithoutRawPriceIsAKnownZeroValue(): void
    {
        $jitaSellPrices = $this->jitaSellPrices();
        unset($jitaSellPrices[self::FULLERITE_C50]);
        $jitaSellPrices[self::COMPRESSED_FULLERITE_C50] = 0.0;
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::FULLERITE_C50 => ['typeId' => self::FULLERITE_C50, 'quantity' => 2290],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository, $jitaSellPrices);

        $this->assertSame([self::FULLERITE_C50 => 0.0], $calculator->getBestUnitPrices([self::FULLERITE_C50]));
        $this->assertMiningValue(0.0, [], $calculator->getTotalBestValue($this->user, $this->from, $this->to));
    }

    #[Group('issue-260')]
    public function testARawPriceOutweighsACompressedPriceAtZero(): void
    {
        $jitaSellPrices = $this->jitaSellPrices();
        $jitaSellPrices[self::COMPRESSED_FULLERITE_C50] = 0.0;
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class), $jitaSellPrices);

        $bestUnitPrices = $calculator->getBestUnitPrices([self::FULLERITE_C50]);

        $this->assertEqualsWithDelta(self::FULLERITE_C50_RAW_PRICE, $bestUnitPrices[self::FULLERITE_C50], self::PRICE_DELTA);
    }

    #[Group('issue-260')]
    public function testMissingPriceTypeIdsAreSortedWithoutDuplicates(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::OTHER_TYPE_WITHOUT_ANY_PRICE => ['typeId' => self::OTHER_TYPE_WITHOUT_ANY_PRICE, 'quantity' => 1200],
            self::TYPE_WITHOUT_ANY_PRICE => ['typeId' => self::TYPE_WITHOUT_ANY_PRICE, 'quantity' => 3150],
            self::TRITANIUM => ['typeId' => self::TRITANIUM, 'quantity' => 2500],
        ]);
        $miningEntryRepository->method('getQuantitiesByTypeAndDate')->willReturn([
            '2026-10-08' => [self::OTHER_TYPE_WITHOUT_ANY_PRICE => 1200, self::TYPE_WITHOUT_ANY_PRICE => 1900],
            '2026-10-09' => [self::TYPE_WITHOUT_ANY_PRICE => 1250, self::TRITANIUM => 2500],
        ]);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([
            MiningEntry::USAGE_SOLD => [self::OTHER_TYPE_WITHOUT_ANY_PRICE => 1200, self::TYPE_WITHOUT_ANY_PRICE => 3150],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $this->assertMiningValue(
            null,
            [self::TYPE_WITHOUT_ANY_PRICE, self::OTHER_TYPE_WITHOUT_ANY_PRICE],
            $calculator->getTotalBestValue($this->user, $this->from, $this->to),
        );
        $this->assertDailyMiningValues(
            ['2026-10-08' => null, '2026-10-09' => null],
            [self::TYPE_WITHOUT_ANY_PRICE, self::OTHER_TYPE_WITHOUT_ANY_PRICE],
            $calculator->getDailyBestValues($this->user, $this->from, $this->to),
        );
        $this->assertTotalsByUsage(
            [MiningEntry::USAGE_SOLD => [null, 4350, [self::TYPE_WITHOUT_ANY_PRICE, self::OTHER_TYPE_WITHOUT_ANY_PRICE]]],
            $calculator->getTotalsByUsageBestValue($this->user, $this->from, $this->to),
        );
    }

    #[Group('issue-260')]
    public function testDailyMissingPriceTypeIdsAreSortedWithoutDuplicatesAcrossDays(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndDate')->willReturn([
            '2026-10-05' => [self::OTHER_TYPE_WITHOUT_ANY_PRICE => 640],
            '2026-10-06' => [self::TYPE_WITHOUT_ANY_PRICE => 1830, self::OTHER_TYPE_WITHOUT_ANY_PRICE => 975],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $dailyBestValues = $calculator->getDailyBestValues($this->user, $this->from, $this->to);

        $this->assertSame([self::TYPE_WITHOUT_ANY_PRICE, self::OTHER_TYPE_WITHOUT_ANY_PRICE], $dailyBestValues['missingPriceTypeIds']);
        $this->assertSame(['2026-10-05' => null, '2026-10-06' => null], $dailyBestValues['byDate']);
    }

    private function createCalculator(
        MiningEntryRepository $miningEntryRepository,
        ?array $jitaSellPrices = null,
    ): MiningBestValueCalculator {
        return new MiningBestValueCalculator(
            $miningEntryRepository,
            $this->createOreValueService($jitaSellPrices ?? $this->jitaSellPricesWithoutCompressedOre()),
        );
    }

    private function assertMiningValue(?float $expectedTotalValue, array $expectedMissingPriceTypeIds, mixed $miningValue): void
    {
        $this->assertIsArray($miningValue);
        $this->assertSame(['totalValue', 'missingPriceTypeIds'], array_keys($miningValue));
        $this->assertKnownOrUnknownValue($expectedTotalValue, $miningValue['totalValue']);
        $this->assertSame($expectedMissingPriceTypeIds, $miningValue['missingPriceTypeIds']);
    }

    private function assertDailyMiningValues(array $expectedValuesByDate, array $expectedMissingPriceTypeIds, mixed $dailyMiningValues): void
    {
        $this->assertIsArray($dailyMiningValues);
        $this->assertSame(['byDate', 'missingPriceTypeIds'], array_keys($dailyMiningValues));
        $this->assertSame(array_keys($expectedValuesByDate), array_keys($dailyMiningValues['byDate']));
        foreach ($expectedValuesByDate as $date => $expectedValue) {
            $this->assertKnownOrUnknownValue($expectedValue, $dailyMiningValues['byDate'][$date]);
        }
        $this->assertSame($expectedMissingPriceTypeIds, $dailyMiningValues['missingPriceTypeIds']);
    }

    private function assertTotalsByUsage(array $expectedTotalsByUsage, array $totalsByUsage): void
    {
        $this->assertSame(array_keys($expectedTotalsByUsage), array_keys($totalsByUsage));
        foreach ($expectedTotalsByUsage as $usage => [$expectedTotalValue, $expectedTotalQuantity, $expectedMissingPriceTypeIds]) {
            $this->assertSame(['totalValue', 'totalQuantity', 'missingPriceTypeIds'], array_keys($totalsByUsage[$usage]));
            $this->assertKnownOrUnknownValue($expectedTotalValue, $totalsByUsage[$usage]['totalValue']);
            $this->assertSame($expectedTotalQuantity, $totalsByUsage[$usage]['totalQuantity']);
            $this->assertSame($expectedMissingPriceTypeIds, $totalsByUsage[$usage]['missingPriceTypeIds']);
        }
    }

    private function assertKnownOrUnknownValue(?float $expectedValue, mixed $actualValue): void
    {
        if ($expectedValue === null) {
            $this->assertNull($actualValue);

            return;
        }

        $this->assertIsFloat($actualValue);
        $this->assertEqualsWithDelta($expectedValue, $actualValue, self::PRICE_DELTA);
    }
}
