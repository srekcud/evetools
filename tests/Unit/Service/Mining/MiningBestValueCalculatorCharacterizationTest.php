<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Entity\MiningEntry;
use App\Entity\MiningValuationProfile;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\MiningEntryRepository;
use App\Repository\ReprocessingProfileRepository;
use App\Service\MiningBestValueCalculator;
use App\Service\MiningValuation\MiningProfileUnitPrices;
use App\Service\MiningValuation\MiningValuationProfileReader;
use App\Service\MiningValuation\ValuationSource;
use App\Service\StructureMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningBestValueCalculator::class)]
#[Group('issue-532')]
final class MiningBestValueCalculatorCharacterizationTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;
    use BuildsMiningProfileUnitPrices;

    private const float PRICE_DELTA = 1e-6;
    private const int TYPE_WITHOUT_ANY_PRICE = 81143;
    private const int OTHER_TYPE_WITHOUT_ANY_PRICE = 81144;
    private const float BITUMENS_REPROCESS_VALUE_AT_YIELD_7236 = 692.506908;

    private User $user;
    private \DateTimeImmutable $from;
    private \DateTimeImmutable $to;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->from = new \DateTimeImmutable('2026-10-01');
        $this->to = new \DateTimeImmutable('2026-10-10');
    }

    public function testReturnsNoUnitValuesForNoTypeIdsWithoutLookingUpPrices(): void
    {
        $unitPrices = new MiningProfileUnitPrices(
            new MiningValuationProfileReader($this->miningValuationProfileRepositoryReturning(null)),
            $this->profileRepositoryWithYield(0.78),
            $this->oreValueServiceThatMustNotLookUpAnything(),
            $this->jitaMarketReadAt([], []),
            $this->createStub(StructureMarketService::class),
        );
        $calculator = new MiningBestValueCalculator($this->createStub(MiningEntryRepository::class), $unitPrices);

        $this->assertSame([], $calculator->getBestUnitPrices($this->user, []));
    }

    #[Group('issue-38')]
    public function testUnitValueIsTheCompressedJitaBuyPriceOfTheDefaultProfileAndNoLongerTheBestOfSeveralSources(): void
    {
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class));

        $unitValues = $calculator->getBestUnitPrices($this->user, [self::BITUMENS, self::CLEAR_ICICLE, self::FULLERITE_C50, self::VELDSPAR]);

        $this->assertSame([self::BITUMENS, self::CLEAR_ICICLE, self::FULLERITE_C50, self::VELDSPAR], array_keys($unitValues));
        $this->assertEqualsWithDelta(201.3, $unitValues[self::BITUMENS], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(143800.0, $unitValues[self::CLEAR_ICICLE], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(2650.0, $unitValues[self::FULLERITE_C50], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(17.5, $unitValues[self::VELDSPAR], self::PRICE_DELTA);
    }

    #[Group('mining-audit-P2-5')]
    public function testAHigherRawPriceOrReprocessValueDoesNotChangeTheCompressedUnitValue(): void
    {
        $jitaBuyPrices = $this->jitaBuyPrices();
        $jitaBuyPrices[self::VELDSPAR] = 40.0;
        $jitaBuyPrices[self::TRITANIUM] = 90.0;
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class), $jitaBuyPrices);

        $unitValues = $calculator->getBestUnitPrices($this->user, [self::VELDSPAR]);

        $this->assertEqualsWithDelta(17.5, $unitValues[self::VELDSPAR], self::PRICE_DELTA);
    }

    #[Group('mining-audit-P1-1')]
    #[Group('issue-259')]
    public function testUnitValueWeighsCompressedOreAtItsFullCompressedEquivalentPrice(): void
    {
        $sdeTypes = $this->sdeTypes();
        $sdeTypes[self::COMPRESSED_VELDSPAR]['portionSize'] = 1;
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class), null, null, null, null, $sdeTypes);

        $unitValues = $calculator->getBestUnitPrices($this->user, [self::VELDSPAR, self::FULLERITE_C50]);

        $this->assertEqualsWithDelta(0.175, $unitValues[self::VELDSPAR], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(2650.0, $unitValues[self::FULLERITE_C50], self::PRICE_DELTA);
    }

    #[Group('issue-260')]
    public function testUnitValueIsUnknownWhenTheFormOfTheProfileHasNoPriceEvenIfAnotherFormDoes(): void
    {
        $jitaBuyPrices = $this->jitaBuyPrices();
        unset($jitaBuyPrices[self::COMPRESSED_BITUMENS]);
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class), $jitaBuyPrices);

        $unitValues = $calculator->getBestUnitPrices($this->user, [self::BITUMENS, self::VELDSPAR]);

        $this->assertNull($unitValues[self::BITUMENS]);
        $this->assertEqualsWithDelta(17.5, $unitValues[self::VELDSPAR], self::PRICE_DELTA);
    }

    #[Group('issue-260')]
    public function testUnitValueIsAKnownZeroForACompressedPriceAtZeroAndStaysUnknownWhereThePriceIsMissing(): void
    {
        $jitaBuyPrices = $this->jitaBuyPrices();
        $jitaBuyPrices[self::COMPRESSED_FULLERITE_C50] = 0.0;
        unset($jitaBuyPrices[self::COMPRESSED_BITUMENS]);
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class), $jitaBuyPrices);

        $unitValues = $calculator->getBestUnitPrices($this->user, [self::FULLERITE_C50, self::BITUMENS]);

        $this->assertSame([self::FULLERITE_C50 => 0.0, self::BITUMENS => null], $unitValues);
    }

    public function testUnitValueFollowsTheSideOfTheProfile(): void
    {
        $calculator = $this->createCalculator(
            $this->createStub(MiningEntryRepository::class),
            null,
            $this->miningValuationProfileOf(side: MarketSide::Sell),
        );

        $unitValues = $calculator->getBestUnitPrices($this->user, [self::BITUMENS, self::VELDSPAR]);

        $this->assertEqualsWithDelta(221.45, $unitValues[self::BITUMENS], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(19.61, $unitValues[self::VELDSPAR], self::PRICE_DELTA);
    }

    #[Group('issue-38')]
    public function testUnitValueIsReadOnTheStructureMarketOfTheProfileInsteadOfBeingIgnored(): void
    {
        $structureMarket = $this->structureMarketReadAt(self::PROFILE_STRUCTURE_ID, [], [self::COMPRESSED_BITUMENS => 205.0]);
        $calculator = $this->createCalculator(
            $this->createStub(MiningEntryRepository::class),
            null,
            $this->miningValuationProfileOf(market: MiningValuationMarket::Structure, structureId: self::PROFILE_STRUCTURE_ID, structureName: self::PROFILE_STRUCTURE_NAME),
            null,
            $structureMarket,
        );

        $unitValues = $calculator->getBestUnitPrices($this->user, [self::BITUMENS, self::VELDSPAR]);

        $this->assertEqualsWithDelta(205.0, $unitValues[self::BITUMENS], self::PRICE_DELTA);
        $this->assertNull($unitValues[self::VELDSPAR]);
    }

    #[Group('issue-269')]
    public function testReprocessedUnitValueUsesTheYieldOfTheReprocessingProfile(): void
    {
        $calculator = $this->createCalculator(
            $this->createStub(MiningEntryRepository::class),
            null,
            $this->miningValuationProfileOf(form: MiningValuationForm::Reprocessed),
            $this->profileRepositoryWithYield(0.7236),
        );

        $unitValues = $calculator->getBestUnitPrices($this->user, [self::BITUMENS]);

        $this->assertEqualsWithDelta(self::BITUMENS_REPROCESS_VALUE_AT_YIELD_7236, $unitValues[self::BITUMENS], self::PRICE_DELTA);
    }

    #[Group('issue-269')]
    public function testReprocessedUnitValueIsUnknownWithoutAReprocessingProfileOrWithAnUnreadableYield(): void
    {
        $reprocessedProfile = $this->miningValuationProfileOf(form: MiningValuationForm::Reprocessed);
        $withoutProfile = $this->createCalculator($this->createStub(MiningEntryRepository::class), null, $reprocessedProfile, $this->profileRepositoryWithNoProfile());
        $withUnreadableYield = $this->createCalculator($this->createStub(MiningEntryRepository::class), null, $reprocessedProfile, $this->profileRepositoryWithUnreadableYield());

        $this->assertSame([self::BITUMENS => null], $withoutProfile->getBestUnitPrices($this->user, [self::BITUMENS]));
        $this->assertSame([self::BITUMENS => null], $withUnreadableYield->getBestUnitPrices($this->user, [self::BITUMENS]));
    }

    #[Group('issue-269')]
    public function testTotalsOfTheReprocessedFormUseTheProfileYieldAndAreUnknownWithoutIt(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::BITUMENS => ['typeId' => self::BITUMENS, 'quantity' => 1000],
        ]);
        $reprocessedProfile = $this->miningValuationProfileOf(form: MiningValuationForm::Reprocessed);
        $withoutProfile = $this->createCalculator($miningEntryRepository, null, $reprocessedProfile, $this->profileRepositoryWithNoProfile());
        $withProfile = $this->createCalculator($miningEntryRepository, null, $reprocessedProfile, $this->profileRepositoryWithYield(0.7236));

        $this->assertMiningValue(null, [self::BITUMENS], $withoutProfile->getTotalBestValue($this->user, $this->from, $this->to));
        $this->assertMiningValue(692506.908, [], $withProfile->getTotalBestValue($this->user, $this->from, $this->to));
    }

    public function testValuationSourceIsJitaBuyCompressedWithoutStructureForAUserWithoutProfile(): void
    {
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class));

        $this->assertEquals(
            new ValuationSource(MiningValuationMarket::Jita, MarketSide::Buy, MiningValuationForm::Compressed, null),
            $calculator->valuationSourceOf($this->user),
        );
    }

    public function testValuationSourceCarriesTheStructureNameOfTheProfile(): void
    {
        $calculator = $this->createCalculator(
            $this->createStub(MiningEntryRepository::class),
            null,
            $this->miningValuationProfileOf(MiningValuationMarket::Structure, MarketSide::Sell, MiningValuationForm::Raw, self::PROFILE_STRUCTURE_ID, self::PROFILE_STRUCTURE_NAME),
        );

        $this->assertEquals(
            new ValuationSource(MiningValuationMarket::Structure, MarketSide::Sell, MiningValuationForm::Raw, self::PROFILE_STRUCTURE_NAME),
            $calculator->valuationSourceOf($this->user),
        );
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

        $this->assertSame([self::TYPE_WITHOUT_ANY_PRICE => null], $calculator->getBestUnitPrices($this->user, [self::TYPE_WITHOUT_ANY_PRICE]));
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

    public function testTotalBestValueSumsUnitValueTimesQuantityOverEveryType(): void
    {
        $excludedUsages = [MiningEntry::USAGE_CORP_PROJECT];
        $miningEntryRepository = $this->createMock(MiningEntryRepository::class);
        $miningEntryRepository->expects($this->once())
            ->method('getQuantitiesByType')
            ->with($this->user, $this->from, $this->to, $excludedUsages)
            ->willReturn([
                self::FULLERITE_C50 => ['typeId' => self::FULLERITE_C50, 'quantity' => 2290],
                self::VELDSPAR => ['typeId' => self::VELDSPAR, 'quantity' => 48150],
                self::BITUMENS => ['typeId' => self::BITUMENS, 'quantity' => 375],
            ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $totalBestValue = $calculator->getTotalBestValue($this->user, $this->from, $this->to, $excludedUsages);

        $this->assertMiningValue(6986612.5, [], $totalBestValue);
    }

    public function testTotalBestValueIsZeroWithoutMinedQuantityNorPriceLookup(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([]);
        $calculator = new MiningBestValueCalculator($miningEntryRepository, $this->createMiningProfileUnitPricesThatMustNotLookUpAnything());

        $this->assertSame(
            ['totalValue' => 0.0, 'missingPriceTypeIds' => []],
            $calculator->getTotalBestValue($this->user, $this->from, $this->to),
        );
    }

    public function testDailyBestValuesSumUnitValueTimesQuantityPerDay(): void
    {
        $excludedUsages = [MiningEntry::USAGE_INDUSTRY];
        $miningEntryRepository = $this->createMock(MiningEntryRepository::class);
        $miningEntryRepository->expects($this->once())
            ->method('getQuantitiesByTypeAndDate')
            ->with($this->user, $this->from, $this->to, $excludedUsages)
            ->willReturn([
                '2026-10-09' => [self::FULLERITE_C50 => 615, self::VELDSPAR => 13720],
                '2026-10-10' => [self::BITUMENS => 182, self::FULLERITE_C50 => 1044],
            ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $dailyBestValues = $calculator->getDailyBestValues($this->user, $this->from, $this->to, $excludedUsages);

        $this->assertDailyMiningValues(['2026-10-09' => 1869850.0, '2026-10-10' => 2803236.6], [], $dailyBestValues);
    }

    public function testDailyBestValuesAreEmptyWithoutMinedQuantityNorPriceLookup(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndDate')->willReturn([]);
        $calculator = new MiningBestValueCalculator($miningEntryRepository, $this->createMiningProfileUnitPricesThatMustNotLookUpAnything());

        $this->assertSame(
            ['byDate' => [], 'missingPriceTypeIds' => []],
            $calculator->getDailyBestValues($this->user, $this->from, $this->to),
        );
    }

    public function testTotalsByUsageSumUnitValueAndQuantityPerUsage(): void
    {
        $miningEntryRepository = $this->createMock(MiningEntryRepository::class);
        $miningEntryRepository->expects($this->once())
            ->method('getQuantitiesByTypeAndUsage')
            ->with($this->user, $this->from, $this->to)
            ->willReturn([
                MiningEntry::USAGE_SOLD => [self::VELDSPAR => 26930, self::BITUMENS => 211],
                MiningEntry::USAGE_INDUSTRY => [self::FULLERITE_C50 => 870, self::VELDSPAR => 9410],
            ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $totalsByUsage = $calculator->getTotalsByUsageBestValue($this->user, $this->from, $this->to);

        $this->assertTotalsByUsage(
            [
                MiningEntry::USAGE_SOLD => [513749.3, 27141, []],
                MiningEntry::USAGE_INDUSTRY => [2470175.0, 10280, []],
            ],
            $totalsByUsage,
        );
    }

    public function testTotalsByUsageAreEmptyWithoutMinedQuantityNorPriceLookup(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([]);
        $calculator = new MiningBestValueCalculator($miningEntryRepository, $this->createMiningProfileUnitPricesThatMustNotLookUpAnything());

        $this->assertSame([], $calculator->getTotalsByUsageBestValue($this->user, $this->from, $this->to));
    }

    #[Group('issue-260')]
    public function testUnitValueIsUnknownForATypeWithoutAnyPriceSource(): void
    {
        $calculator = $this->createCalculator($this->createStub(MiningEntryRepository::class));

        $unitValues = $calculator->getBestUnitPrices($this->user, [self::BITUMENS, self::TYPE_WITHOUT_ANY_PRICE]);

        $this->assertSame([self::BITUMENS, self::TYPE_WITHOUT_ANY_PRICE], array_keys($unitValues));
        $this->assertEqualsWithDelta(201.3, $unitValues[self::BITUMENS], self::PRICE_DELTA);
        $this->assertNull($unitValues[self::TYPE_WITHOUT_ANY_PRICE]);
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
            self::VELDSPAR => ['typeId' => self::VELDSPAR, 'quantity' => 2500],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $totalBestValue = $calculator->getTotalBestValue($this->user, $this->from, $this->to);

        $this->assertMiningValue(245050.0, [], $totalBestValue);
    }

    #[Group('issue-260')]
    public function testDailyMiningValueIsUnknownOnlyOnTheDayHoldingATypeWithoutPrice(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndDate')->willReturn([
            '2026-10-09' => [self::BITUMENS => 1000],
            '2026-10-10' => [self::VELDSPAR => 2500, self::TYPE_WITHOUT_ANY_PRICE => 3150],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $dailyBestValues = $calculator->getDailyBestValues($this->user, $this->from, $this->to);

        $this->assertDailyMiningValues(
            ['2026-10-09' => 201300.0, '2026-10-10' => null],
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
            MiningEntry::USAGE_INDUSTRY => [self::VELDSPAR => 2500, self::TYPE_WITHOUT_ANY_PRICE => 3150],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $totalsByUsage = $calculator->getTotalsByUsageBestValue($this->user, $this->from, $this->to);

        $this->assertTotalsByUsage(
            [
                MiningEntry::USAGE_SOLD => [201300.0, 1000, []],
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
            self::VELDSPAR => ['typeId' => self::VELDSPAR, 'quantity' => 2500],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $totalBestValue = $calculator->getTotalBestValue($this->user, $this->from, $this->to);

        $this->assertMiningValue(43750.0, [], $totalBestValue);
    }

    #[Group('issue-260')]
    public function testATypeWithoutPriceMinedInZeroQuantityLeavesItsDayAndUsageKnown(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByTypeAndDate')->willReturn([
            '2026-10-07' => [self::TYPE_WITHOUT_ANY_PRICE => 0, self::FULLERITE_C50 => 615],
        ]);
        $miningEntryRepository->method('getQuantitiesByTypeAndUsage')->willReturn([
            MiningEntry::USAGE_UNKNOWN => [self::TYPE_WITHOUT_ANY_PRICE => 0, self::VELDSPAR => 1840],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository);

        $this->assertDailyMiningValues(
            ['2026-10-07' => 1629750.0],
            [],
            $calculator->getDailyBestValues($this->user, $this->from, $this->to),
        );
        $this->assertTotalsByUsage(
            [MiningEntry::USAGE_UNKNOWN => [32200.0, 1840, []]],
            $calculator->getTotalsByUsageBestValue($this->user, $this->from, $this->to),
        );
    }

    #[Group('issue-260')]
    public function testAMinedTypeAtZeroIskIsAKnownZeroValueThatLeavesTheOtherTypesCounted(): void
    {
        $jitaBuyPrices = $this->jitaBuyPrices();
        $jitaBuyPrices[self::COMPRESSED_VELDSPAR] = 0.0;
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::VELDSPAR => ['typeId' => self::VELDSPAR, 'quantity' => 2500],
            self::BITUMENS => ['typeId' => self::BITUMENS, 'quantity' => 1000],
        ]);
        $calculator = $this->createCalculator($miningEntryRepository, $jitaBuyPrices);

        $totalBestValue = $calculator->getTotalBestValue($this->user, $this->from, $this->to);

        $this->assertMiningValue(201300.0, [], $totalBestValue);
    }

    #[Group('issue-260')]
    public function testMissingPriceTypeIdsAreSortedWithoutDuplicates(): void
    {
        $miningEntryRepository = $this->createStub(MiningEntryRepository::class);
        $miningEntryRepository->method('getQuantitiesByType')->willReturn([
            self::OTHER_TYPE_WITHOUT_ANY_PRICE => ['typeId' => self::OTHER_TYPE_WITHOUT_ANY_PRICE, 'quantity' => 1200],
            self::TYPE_WITHOUT_ANY_PRICE => ['typeId' => self::TYPE_WITHOUT_ANY_PRICE, 'quantity' => 3150],
            self::VELDSPAR => ['typeId' => self::VELDSPAR, 'quantity' => 2500],
        ]);
        $miningEntryRepository->method('getQuantitiesByTypeAndDate')->willReturn([
            '2026-10-08' => [self::OTHER_TYPE_WITHOUT_ANY_PRICE => 1200, self::TYPE_WITHOUT_ANY_PRICE => 1900],
            '2026-10-09' => [self::TYPE_WITHOUT_ANY_PRICE => 1250, self::VELDSPAR => 2500],
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
        ?array $jitaBuyPrices = null,
        ?MiningValuationProfile $profile = null,
        ?ReprocessingProfileRepository $reprocessingProfileRepository = null,
        ?StructureMarketService $structureMarketService = null,
        ?array $sdeTypes = null,
    ): MiningBestValueCalculator {
        return new MiningBestValueCalculator(
            $miningEntryRepository,
            $this->createMiningProfileUnitPrices($jitaBuyPrices, null, $profile, $reprocessingProfileRepository, $structureMarketService, $sdeTypes),
        );
    }

    private function createMiningProfileUnitPricesThatMustNotLookUpAnything(): MiningProfileUnitPrices
    {
        return new MiningProfileUnitPrices(
            new MiningValuationProfileReader($this->miningValuationProfileRepositoryReturning(null)),
            $this->profileRepositoryWithYield(0.78),
            $this->oreValueServiceThatMustNotLookUpAnything(),
            $this->jitaMarketReadAt([], []),
            $this->createStub(StructureMarketService::class),
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
