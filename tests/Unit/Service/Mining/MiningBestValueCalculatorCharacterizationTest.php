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
    public function testBestUnitPriceCurrentlyWeighsCompressedOreAtOneHundredthOfItsPriceMiningAuditP1_1(): void
    {
        $jitaSellPrices = $this->jitaSellPrices();
        $jitaSellPrices[self::VELDSPAR] = 12.4;
        unset($jitaSellPrices[self::FULLERITE_C50]);
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class), $jitaSellPrices);

        $bestUnitPrices = $calculator->getBestUnitPrices([self::VELDSPAR, self::FULLERITE_C50]);

        $this->assertEqualsWithDelta(13.3224, $bestUnitPrices[self::VELDSPAR], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(28.13, $bestUnitPrices[self::FULLERITE_C50], self::PRICE_DELTA);
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
    public function testCurrentlyValuesAnOreWithoutAnyPriceAtZeroIskMiningAuditP2_5(): void
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

        $this->assertSame([self::TYPE_WITHOUT_ANY_PRICE => 0.0], $calculator->getBestUnitPrices([self::TYPE_WITHOUT_ANY_PRICE]));
        $this->assertEqualsWithDelta(6276890.0, $calculator->getTotalBestValue($this->user, $this->from, $this->to), self::PRICE_DELTA);
        $this->assertSame(['2026-10-09' => 0.0], $calculator->getDailyBestValues($this->user, $this->from, $this->to));
        $this->assertSame(
            [MiningEntry::USAGE_SOLD => ['totalValue' => 0.0, 'totalQuantity' => 3150]],
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

        $this->assertEqualsWithDelta(6892553.0, $totalBestValue, self::PRICE_DELTA);
    }

    public function testTotalBestValueIsZeroWithoutMinedQuantityNorPriceLookup(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([]);
        $calculator = new MiningBestValueCalculator($miningEntryRepository, $this->oreValueServiceThatMustNotLookUpAnything());

        $this->assertSame(0.0, $calculator->getTotalBestValue($this->user, $this->from, $this->to));
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

        $this->assertSame(['2026-10-09', '2026-10-10'], array_keys($dailyBestValues));
        $this->assertEqualsWithDelta(1744299.4, $dailyBestValues['2026-10-09'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(3060621.0, $dailyBestValues['2026-10-10'], self::PRICE_DELTA);
    }

    public function testDailyBestValuesAreEmptyWithoutMinedQuantityNorPriceLookup(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndDate')->willReturn([]);
        $calculator = new MiningBestValueCalculator($miningEntryRepository, $this->oreValueServiceThatMustNotLookUpAnything());

        $this->assertSame([], $calculator->getDailyBestValues($this->user, $this->from, $this->to));
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

        $this->assertSame([MiningEntry::USAGE_SOLD, MiningEntry::USAGE_INDUSTRY], array_keys($totalsByUsage));
        $this->assertEqualsWithDelta(345719.6, $totalsByUsage[MiningEntry::USAGE_SOLD]['totalValue'], self::PRICE_DELTA);
        $this->assertSame(27141, $totalsByUsage[MiningEntry::USAGE_SOLD]['totalQuantity']);
        $this->assertEqualsWithDelta(2424850.7, $totalsByUsage[MiningEntry::USAGE_INDUSTRY]['totalValue'], self::PRICE_DELTA);
        $this->assertSame(10280, $totalsByUsage[MiningEntry::USAGE_INDUSTRY]['totalQuantity']);
    }

    public function testTotalsByUsageAreEmptyWithoutMinedQuantityNorPriceLookup(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([]);
        $calculator = new MiningBestValueCalculator($miningEntryRepository, $this->oreValueServiceThatMustNotLookUpAnything());

        $this->assertSame([], $calculator->getTotalsByUsageBestValue($this->user, $this->from, $this->to));
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

    private function jitaSellPricesWithoutCompressedOre(): array
    {
        $jitaSellPrices = $this->jitaSellPrices();
        unset(
            $jitaSellPrices[self::COMPRESSED_VELDSPAR],
            $jitaSellPrices[self::COMPRESSED_BITUMENS],
            $jitaSellPrices[self::COMPRESSED_CLEAR_ICICLE],
            $jitaSellPrices[self::COMPRESSED_FULLERITE_C50],
        );

        return $jitaSellPrices;
    }
}
