<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Application\ProductionCostIntermediateBuildCosts;
use App\Industry\Application\ProductionStructure;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Runs;
use App\Industry\Domain\SecurityClass;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryInventionCatalog;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionCostIntermediateBuildCosts::class)]
#[Group('issue-1345')]
final class ProductionCostIntermediateBuildCostsTest extends TestCase
{
    private const int HAIL_L = 12779;
    private const int FERNITE_CARBIDE = 16673;
    private const int FULLERIDES = 16679;
    private const int MORPHITE = 11399;
    private const int RAM_AMMUNITION_TECH = 11476;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;
    private const int NOCXIUM = 38;
    private const int RAITARU_SYSTEM_ID = 30002187;
    private const float RAITARU_FACILITY_TAX_RATE = 0.01;
    private const float ISK_TOLERANCE = 0.01;
    private const float HAIL_L_TEN_RUNS_PLAN_TOTAL = 5025715.56;

    private const array MARKET_PRICES = [
        self::FERNITE_CARBIDE => 60.0,
        self::FULLERIDES => 150.0,
        self::MORPHITE => 9000.0,
        self::TRITANIUM => 4.0,
        self::PYERITE => 8.0,
        self::MEXALLON => 70.0,
        self::ISOGEN => 130.0,
        self::NOCXIUM => 500.0,
    ];

    public function testTotalCostIsThePlanModeCostOfTheRunsWithTheBoughtTypesAsBlacklist(): void
    {
        $cost = $this->buildCostsWith(self::MARKET_PRICES)->totalCostOf(
            self::HAIL_L,
            new Runs(10),
            new BlueprintEfficiency(new MaterialEfficiency(2), new TimeEfficiency(4)),
            [self::FERNITE_CARBIDE, self::FULLERIDES],
            [$this->nullsecRaitaru()],
            FavoriteSystems::none(),
        );

        self::assertTrue($cost->isKnown());
        self::assertEqualsWithDelta(self::HAIL_L_TEN_RUNS_PLAN_TOTAL, $cost->amount()->amount, self::ISK_TOLERANCE);
    }

    public function testMissingMarketPriceOfABoughtMaterialKeepsTheCostUnknownInsteadOfZero(): void
    {
        $pricesWithoutMorphite = self::MARKET_PRICES;
        unset($pricesWithoutMorphite[self::MORPHITE]);

        $cost = $this->buildCostsWith($pricesWithoutMorphite)->totalCostOf(
            self::HAIL_L,
            new Runs(10),
            new BlueprintEfficiency(new MaterialEfficiency(2), new TimeEfficiency(4)),
            [self::FERNITE_CARBIDE, self::FULLERIDES],
            [$this->nullsecRaitaru()],
            FavoriteSystems::none(),
        );

        self::assertFalse($cost->isKnown());
        self::assertEquals([MissingData::marketPrice(self::MORPHITE)], $cost->missingData);
    }

    /**
     * @param array<int, float> $marketPrices
     */
    private function buildCostsWith(array $marketPrices): ProductionCostIntermediateBuildCosts
    {
        return new ProductionCostIntermediateBuildCosts(new ProductionCostCalculator(
            InMemoryBlueprintCatalog::fromSdeFixture(),
            new InMemoryInventionCatalog(),
            new InMemoryMarketPrices($marketPrices),
            new InMemoryAdjustedPrices([
                self::FERNITE_CARBIDE => 50.0,
                self::FULLERIDES => 120.0,
                self::MORPHITE => 8000.0,
                self::RAM_AMMUNITION_TECH => 1000.0,
                self::TRITANIUM => 3.0,
                self::PYERITE => 7.0,
                self::MEXALLON => 60.0,
                self::ISOGEN => 100.0,
                self::NOCXIUM => 400.0,
            ]),
            new InMemorySystemCostIndices([self::RAITARU_SYSTEM_ID => [ActivityKind::Manufacturing->name => 0.05]]),
            new InMemoryRigCategories(manufacturing: [self::HAIL_L => 'ammunition']),
        ));
    }

    private function nullsecRaitaru(): ProductionStructure
    {
        return ProductionStructures::raitaruWithFacilityTax(
            'raitaru-nullsec',
            SecurityClass::NullSec,
            self::RAITARU_SYSTEM_ID,
            self::RAITARU_FACILITY_TAX_RATE,
            ProductionStructures::manufacturingRig(2.4, 'ammunition'),
        );
    }
}
