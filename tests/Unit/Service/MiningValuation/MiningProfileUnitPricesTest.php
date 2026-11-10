<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MiningValuation;

use App\Entity\MiningValuationProfile;
use App\Entity\User;
use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\ReprocessingProfileRepository;
use App\Service\MiningValuation\MiningProfileUnitPriceReading;
use App\Service\MiningValuation\MiningProfileUnitPrices;
use App\Service\MiningValuation\ValuationSource;
use App\Tests\Unit\Service\Mining\BuildsMiningProfileUnitPrices;
use App\Tests\Unit\Service\Mining\BuildsOreValueServiceOnSdeOres;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningProfileUnitPrices::class)]
#[CoversClass(MiningProfileUnitPriceReading::class)]
#[Group('issue-532')]
final class MiningProfileUnitPricesTest extends TestCase
{
    use BuildsOreValueServiceOnSdeOres;
    use BuildsMiningProfileUnitPrices;

    private const float PRICE_DELTA = 1e-9;
    private const int TYPE_WITHOUT_ANY_PRICE = 81143;

    public function testAUserWithoutProfileReadsTheCompressedJitaBuyPriceOfTheSpecExample(): void
    {
        $reading = $this->readOreAOnTheSpecPrices(null, [self::VELDSPAR]);

        $this->assertSame([self::VELDSPAR], array_keys($reading->unitPricesByTypeId));
        $this->assertEqualsWithDelta(500.0, $reading->unitPricesByTypeId[self::VELDSPAR], self::PRICE_DELTA);
        $this->assertEquals(
            new ValuationSource(MiningValuationMarket::Jita, MarketSide::Buy, MiningValuationForm::Compressed, null),
            $reading->valuationSource,
        );
    }

    public function testTheExportTaxOfTheProfileIsSubtractedPerCubicMetreOfTheCompressedOre(): void
    {
        $profile = $this->miningValuationProfileOf(exportTax: 1000.0);

        $reading = $this->readOreAOnTheSpecPrices($profile, [self::VELDSPAR]);

        $this->assertEqualsWithDelta(400.0, $reading->unitPricesByTypeId[self::VELDSPAR], self::PRICE_DELTA);
    }

    public function testAnExportTaxAboveTheNetPriceLeavesAKnownZeroAndOtherOresKeepTheirPrice(): void
    {
        $profile = $this->miningValuationProfileOf(exportTax: 6000.0);
        $sdeTypes = $this->sdeTypesWithOreAOfTheSpec();
        $jitaBuyPrices = $this->jitaBuyPricesOfOreAOfTheSpec() + [self::COMPRESSED_FULLERITE_C50 => 2650.0];

        $reading = $this->createMiningProfileUnitPrices($jitaBuyPrices, $this->jitaSellPricesOfOreAOfTheSpec(), $profile, null, null, $sdeTypes)
            ->read(new User(), [self::VELDSPAR, self::FULLERITE_C50]);

        $this->assertSame(0.0, $reading->unitPricesByTypeId[self::VELDSPAR]);
        $this->assertEqualsWithDelta(2050.0, $reading->unitPricesByTypeId[self::FULLERITE_C50], self::PRICE_DELTA);
    }

    public function testTheSellSideReadsTheCompressedJitaSellPrice(): void
    {
        $profile = $this->miningValuationProfileOf(side: MarketSide::Sell);

        $reading = $this->readOreAOnTheSpecPrices($profile, [self::VELDSPAR]);

        $this->assertEqualsWithDelta(560.0, $reading->unitPricesByTypeId[self::VELDSPAR], self::PRICE_DELTA);
        $this->assertEquals(
            new ValuationSource(MiningValuationMarket::Jita, MarketSide::Sell, MiningValuationForm::Compressed, null),
            $reading->valuationSource,
        );
    }

    public function testTheReprocessedFormReadsTheReprocessValueAtTheYieldOfTheReprocessingProfile(): void
    {
        $profile = $this->miningValuationProfileOf(form: MiningValuationForm::Reprocessed);

        $reading = $this->readOreAOnTheSpecPrices($profile, [self::VELDSPAR], $this->profileRepositoryWithYield(0.8));

        $this->assertEqualsWithDelta(520.0, $reading->unitPricesByTypeId[self::VELDSPAR], self::PRICE_DELTA);
        $this->assertEquals(
            new ValuationSource(MiningValuationMarket::Jita, MarketSide::Buy, MiningValuationForm::Reprocessed, null),
            $reading->valuationSource,
        );
    }

    public function testTheReprocessedFormIsUnknownForEveryTypeWithoutAReadableYieldAndNeverZero(): void
    {
        $profile = $this->miningValuationProfileOf(form: MiningValuationForm::Reprocessed);

        $withoutReprocessingProfile = $this->readOreAOnTheSpecPrices($profile, [self::VELDSPAR, self::BITUMENS], $this->profileRepositoryWithNoProfile());
        $withUnreadableYield = $this->readOreAOnTheSpecPrices($profile, [self::VELDSPAR], $this->profileRepositoryWithUnreadableYield());

        $this->assertSame([self::VELDSPAR => null, self::BITUMENS => null], $withoutReprocessingProfile->unitPricesByTypeId);
        $this->assertSame([self::VELDSPAR => null], $withUnreadableYield->unitPricesByTypeId);
    }

    public function testAStructureMarketWithoutPriceForTheOreIsUnknownAndNeverFallsBackOnJita(): void
    {
        $profile = $this->miningValuationProfileOf(
            market: MiningValuationMarket::Structure,
            structureId: self::PROFILE_STRUCTURE_ID,
            structureName: self::PROFILE_STRUCTURE_NAME,
        );
        $structureMarket = $this->structureMarketReadAt(self::PROFILE_STRUCTURE_ID, [], [self::COMPRESSED_BITUMENS => 205.0]);

        $reading = $this->createMiningProfileUnitPrices(
            $this->jitaBuyPricesOfOreAOfTheSpec() + [self::COMPRESSED_BITUMENS => 201.3],
            $this->jitaSellPricesOfOreAOfTheSpec(),
            $profile,
            null,
            $structureMarket,
            $this->sdeTypesWithOreAOfTheSpec(),
        )->read(new User(), [self::VELDSPAR, self::BITUMENS]);

        $this->assertSame(self::VELDSPAR, array_key_first($reading->unitPricesByTypeId));
        $this->assertNull($reading->unitPricesByTypeId[self::VELDSPAR]);
        $this->assertEqualsWithDelta(205.0, $reading->unitPricesByTypeId[self::BITUMENS], self::PRICE_DELTA);
        $this->assertEquals(
            new ValuationSource(MiningValuationMarket::Structure, MarketSide::Buy, MiningValuationForm::Compressed, self::PROFILE_STRUCTURE_NAME),
            $reading->valuationSource,
        );
    }

    public function testAProfileWithAStructureStillReadsTheStructurePriceColumnsUnderTheJitaMarket(): void
    {
        $profile = $this->miningValuationProfileOf(structureId: self::PROFILE_STRUCTURE_ID, structureName: self::PROFILE_STRUCTURE_NAME);
        $structureMarket = $this->structureMarketReadAt(self::PROFILE_STRUCTURE_ID, [], [self::COMPRESSED_VELDSPAR => 480.0]);

        $reading = $this->createMiningProfileUnitPrices(
            $this->jitaBuyPricesOfOreAOfTheSpec(),
            $this->jitaSellPricesOfOreAOfTheSpec(),
            $profile,
            null,
            $structureMarket,
            $this->sdeTypesWithOreAOfTheSpec(),
        )->read(new User(), [self::VELDSPAR]);

        $this->assertEqualsWithDelta(500.0, $reading->unitPricesByTypeId[self::VELDSPAR], self::PRICE_DELTA);
        $this->assertArrayHasKey(self::VELDSPAR, $reading->pricesByTypeId);
        $this->assertEqualsWithDelta(480.0, $reading->pricesByTypeId[self::VELDSPAR]['structureCompressedEquivalentPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(500.0, $reading->pricesByTypeId[self::VELDSPAR]['compressedEquivalentPrice'], self::PRICE_DELTA);
        $this->assertEquals(
            new ValuationSource(MiningValuationMarket::Jita, MarketSide::Buy, MiningValuationForm::Compressed, self::PROFILE_STRUCTURE_NAME),
            $reading->valuationSource,
        );
    }

    public function testAnOreWithoutAnyPriceIsUnknownNextToAPricedOre(): void
    {
        $reading = $this->createMiningProfileUnitPrices()->read(new User(), [self::BITUMENS, self::TYPE_WITHOUT_ANY_PRICE]);

        $this->assertSame([self::BITUMENS, self::TYPE_WITHOUT_ANY_PRICE], array_keys($reading->unitPricesByTypeId));
        $this->assertEqualsWithDelta(201.3, $reading->unitPricesByTypeId[self::BITUMENS], self::PRICE_DELTA);
        $this->assertNull($reading->unitPricesByTypeId[self::TYPE_WITHOUT_ANY_PRICE]);
    }

    public function testTheReadingCarriesTheRawPriceRowsOfEachOreAndTheJitaSyncTime(): void
    {
        $reading = $this->readOreAOnTheSpecPrices(null, [self::VELDSPAR]);

        $this->assertArrayHasKey(self::VELDSPAR, $reading->pricesByTypeId);
        $this->assertEqualsWithDelta(450.0, $reading->pricesByTypeId[self::VELDSPAR]['rawUnitPrice'], self::PRICE_DELTA);
        $this->assertEqualsWithDelta(500.0, $reading->pricesByTypeId[self::VELDSPAR]['compressedEquivalentPrice'], self::PRICE_DELTA);
        $this->assertEquals(new \DateTimeImmutable(self::JITA_PRICES_READ_AT), $reading->pricesSyncedAt);
    }

    public function testTheStructureMarketCarriesTheSyncTimeOfTheStructure(): void
    {
        $profile = $this->miningValuationProfileOf(
            market: MiningValuationMarket::Structure,
            structureId: self::PROFILE_STRUCTURE_ID,
            structureName: self::PROFILE_STRUCTURE_NAME,
        );
        $structureMarket = $this->structureMarketReadAt(self::PROFILE_STRUCTURE_ID, [], [self::COMPRESSED_VELDSPAR => 480.0]);

        $reading = $this->createMiningProfileUnitPrices(
            $this->jitaBuyPricesOfOreAOfTheSpec(),
            $this->jitaSellPricesOfOreAOfTheSpec(),
            $profile,
            null,
            $structureMarket,
            $this->sdeTypesWithOreAOfTheSpec(),
        )->read(new User(), [self::VELDSPAR]);

        $this->assertEquals(new \DateTimeImmutable(self::STRUCTURE_PRICES_READ_AT), $reading->pricesSyncedAt);
    }

    public function testNoTypeIdsReadNothingAndStillCarryTheValuationSourceOfTheProfile(): void
    {
        $profile = $this->miningValuationProfileOf(side: MarketSide::Buy, form: MiningValuationForm::Raw);

        $reading = $this->readOreAOnTheSpecPrices($profile, []);

        $this->assertSame([], $reading->unitPricesByTypeId);
        $this->assertEquals(
            new ValuationSource(MiningValuationMarket::Jita, MarketSide::Buy, MiningValuationForm::Raw, null),
            $reading->valuationSource,
        );
    }

    private function readOreAOnTheSpecPrices(
        ?MiningValuationProfile $profile,
        array $typeIds,
        ?ReprocessingProfileRepository $reprocessingProfileRepository = null,
    ): MiningProfileUnitPriceReading {
        return $this->createMiningProfileUnitPrices(
            $this->jitaBuyPricesOfOreAOfTheSpec(),
            $this->jitaSellPricesOfOreAOfTheSpec(),
            $profile,
            $reprocessingProfileRepository,
            null,
            $this->sdeTypesWithOreAOfTheSpec(),
        )->read(new User(), $typeIds);
    }
}
