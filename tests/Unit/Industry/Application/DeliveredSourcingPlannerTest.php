<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\CourierHub;
use App\Industry\Application\DeliveredSourcing;
use App\Industry\Application\DeliveredSourcingLine;
use App\Industry\Application\DeliveredSourcingOption;
use App\Industry\Application\DeliveredSourcingPlanner;
use App\Industry\Application\DeliveredSourcingTotals;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\PlannedProduction;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\SourcingChoice;
use App\Industry\Application\SourcingSide;
use App\Industry\Application\SourcingUniverse;
use App\Industry\Application\SourcingValuation;
use App\Industry\Application\StructureChoice;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\WorkType;
use App\Industry\Application\WorkTypeClassifier;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Intermediate;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\FixedJobInstallationCosts;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryShipProducts;
use App\Tests\Unit\Industry\Application\Double\InMemoryStructureMarketPrices;
use App\Tests\Unit\Industry\Application\Double\PlannedProductionsByPurchases;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeliveredSourcingPlanner::class)]
#[CoversClass(SourcingValuation::class)]
#[CoversClass(SourcingUniverse::class)]
#[CoversClass(DeliveredSourcingTotals::class)]
#[Group('issue-1288')]
final class DeliveredSourcingPlannerTest extends TestCase
{
    private const int TARGET_PRODUCT = 12005;
    private const int SECOND_TARGET_PRODUCT = 12015;
    private const int FERNITE_COMPOSITE = 16670;
    private const int PHENOLIC_COMPOSITES = 16680;
    private const int CAPACITOR_BATTERY = 11538;
    private const int ARMOR_PLATE = 11540;
    private const int DRONE_COMPONENT = 11542;
    private const int LEAF_ALPHA = 16633;
    private const int LEAF_BETA = 16634;
    private const int LEAF_GAMMA = 16635;

    private const int HUB_SYSTEM_ID = 30_000_772;
    private const int OTHER_SYSTEM_ID = 30_002_187;
    private const int REACTION_SYSTEM_ID = 30_002_659;
    private const int MARKET_SYSTEM_ID = 30_001_000;
    private const int MARKET_STRUCTURE_ID = 1_040_000_000_001;

    private const float HUB_ISK_PER_CUBIC_METER = 800.0;

    public function testExampleAReactionIsBuiltWhenBuildingCostsLessThanBuyingWithTheImport(): void
    {
        $replans = $this->ferniteReplans();

        $result = $this->planFernite(100_000.0, $replans);

        $line = $this->lineOf($result, self::FERNITE_COMPOSITE);
        self::assertSame(
            [
                'build' => [21_940_000.0, 21_440_000.0, 500_000.0, 0.0],
                'jita' => [30_240_000.0, 30_000_000.0, null, 240_000.0],
            ],
            [
                'build' => $this->amountsOf($line->option(SourcingChoice::Build)),
                'jita' => $this->amountsOf($line->option(SourcingChoice::Jita)),
            ],
        );
        self::assertSame(SourcingChoice::Build, $line->recommended);
        self::assertSame(SourcingChoice::Build, $line->chosen);
        self::assertFalse($line->bought);
        self::assertSame(
            [300, 3, 0, WorkType::Reaction, ActivityKind::Reaction, ['S1']],
            [$line->quantity->value, $line->runs, $line->surplus->value, $line->workType, $line->activity, $line->consumerStructureIds],
        );
        self::assertSame([], $result->boughtTypeIds);
        self::assertSame([[]], $replans->sortedCalls());
    }

    public function testTotalsOfAnAllBuiltPlanAddThePurchasesTheInstallationOfEveryJobAndTheTransport(): void
    {
        $result = $this->planFernite(100_000.0, $this->ferniteReplans());

        self::assertSame(
            [21_440_000.0, 1_500_000.0, 0.0, 22_940_000.0],
            $this->totalsOf($result),
        );
    }

    public function testExampleAReactionIsBoughtWhenTheJitaOrderBookIsCheaperAndThePlanDropsItsJobAndMaterials(): void
    {
        $replans = $this->ferniteReplans();

        $result = $this->planFernite(60_000.0, $replans);

        $line = $this->lineOf($result, self::FERNITE_COMPOSITE);
        self::assertSame(
            [18_240_000.0, 21_940_000.0],
            [$this->amountOf($line->option(SourcingChoice::Jita)->total), $this->amountOf($line->option(SourcingChoice::Build)->total)],
        );
        self::assertSame(SourcingChoice::Jita, $line->recommended);
        self::assertSame(SourcingChoice::Jita, $line->chosen);
        self::assertTrue($line->bought);
        self::assertSame(300, $line->quantity->value);
        self::assertSame([self::FERNITE_COMPOSITE], $this->sortedTypeIdsOf($result));
        self::assertSame([self::FERNITE_COMPOSITE], $result->boughtTypeIds);
        self::assertSame([[self::FERNITE_COMPOSITE]], $replans->sortedCalls());
        self::assertSame(
            [18_240_000.0, 1_000_000.0, 0.0, 19_240_000.0],
            $this->totalsOf($result),
        );
    }

    public function testAnExplicitChoiceToBuyIsFollowedWhenBuildingIsRecommended(): void
    {
        $replans = $this->ferniteReplans();

        $result = $this->planFernite(100_000.0, $replans, buys: [self::FERNITE_COMPOSITE => true]);

        $line = $this->lineOf($result, self::FERNITE_COMPOSITE);
        self::assertSame(SourcingChoice::Build, $line->recommended);
        self::assertSame(SourcingChoice::Jita, $line->chosen);
        self::assertTrue($line->bought);
        self::assertSame(30_240_000.0, $this->amountOf($line->chosenTotal()));
        self::assertSame([self::FERNITE_COMPOSITE], $result->boughtTypeIds);
        self::assertSame([[self::FERNITE_COMPOSITE]], $replans->sortedCalls());
    }

    public function testAnExplicitChoiceToBuildIsFollowedWhenBuyingIsRecommended(): void
    {
        $replans = $this->ferniteReplans();

        $result = $this->planFernite(60_000.0, $replans, buys: [self::FERNITE_COMPOSITE => false]);

        $line = $this->lineOf($result, self::FERNITE_COMPOSITE);
        self::assertSame(SourcingChoice::Jita, $line->recommended);
        self::assertSame(SourcingChoice::Build, $line->chosen);
        self::assertFalse($line->bought);
        self::assertSame(21_940_000.0, $this->amountOf($line->chosenTotal()));
        self::assertSame([], $result->boughtTypeIds);
        self::assertSame([[]], $replans->sortedCalls());
    }

    public function testExampleBStructureWithoutEnoughStockIsNotEligibleAndJitaIsRecommended(): void
    {
        $result = $this->planCapitalMarketLeaves();

        $line = $this->lineOf($result, self::LEAF_ALPHA);
        self::assertSame(
            [
                'jita' => [108_000.0, 100_000.0, 8_000.0, true],
                'structure' => [90_100.0, 90_000.0, 100.0, false],
            ],
            [
                'jita' => $this->priceAndTransportOf($line->option(SourcingChoice::Jita)),
                'structure' => $this->priceAndTransportOf($line->option(SourcingChoice::Structure)),
            ],
        );
        self::assertSame(800, $line->option(SourcingChoice::Structure)->availableQuantity);
        self::assertSame(SourcingChoice::Jita, $line->recommended);
        self::assertSame(SourcingChoice::Jita, $line->chosen);
    }

    public function testExampleBAnExplicitCapitalMarketIsFollowedEvenWhenItsStockIsTooLow(): void
    {
        $result = $this->planCapitalMarketLeaves(markets: [self::LEAF_ALPHA => SourcingSide::Structure]);

        $line = $this->lineOf($result, self::LEAF_ALPHA);
        self::assertSame(SourcingChoice::Jita, $line->recommended);
        self::assertSame(SourcingChoice::Structure, $line->chosen);
        self::assertFalse($line->option(SourcingChoice::Structure)->eligible);
        self::assertSame(90_100.0, $this->amountOf($line->chosenTotal()));
        self::assertSame(190_200.0, $this->amountOf($result->totals->purchases));
    }

    public function testTheCapitalMarketIsRecommendedWhenItIsCheaperAndSellsAtLeastTheQuantity(): void
    {
        $result = $this->planCapitalMarketLeaves();

        $line = $this->lineOf($result, self::LEAF_BETA);
        self::assertSame(
            [
                'jita' => [133_000.0, 125_000.0, 8_000.0, true],
                'structure' => [100_100.0, 100_000.0, 100.0, true],
            ],
            [
                'jita' => $this->priceAndTransportOf($line->option(SourcingChoice::Jita)),
                'structure' => $this->priceAndTransportOf($line->option(SourcingChoice::Structure)),
            ],
        );
        self::assertSame(500, $line->option(SourcingChoice::Structure)->availableQuantity);
        self::assertSame(SourcingChoice::Structure, $line->recommended);
        self::assertSame(100_100.0, $this->amountOf($line->chosenTotal()));
    }

    public function testExampleCComponentMaterialsAreCostedAtTheChosenOptionOfTheReactionNotAtTheFlatPrice(): void
    {
        $replans = $this->phenolicReplans();

        $result = $this->planComponentOverReaction($replans);

        $reaction = $this->lineOf($result, self::PHENOLIC_COMPOSITES);
        self::assertSame(
            [
                'build' => [12_800.0, 10_800.0, 2_000.0, 0.0],
                'jita' => [9_600.0, 8_000.0, null, 1_600.0],
            ],
            [
                'build' => $this->amountsOf($reaction->option(SourcingChoice::Build)),
                'jita' => $this->amountsOf($reaction->option(SourcingChoice::Jita)),
            ],
        );
        self::assertSame([SourcingChoice::Jita, SourcingChoice::Jita, true, 20], [$reaction->recommended, $reaction->chosen, $reaction->bought, $reaction->quantity->value]);

        $component = $this->lineOf($result, self::CAPACITOR_BATTERY);
        self::assertSame(
            [
                'build' => [9_900.0, 9_600.0, 300.0, 0.0],
                'jita' => [28_000.0, 20_000.0, null, 8_000.0],
            ],
            [
                'build' => $this->amountsOf($component->option(SourcingChoice::Build)),
                'jita' => $this->amountsOf($component->option(SourcingChoice::Jita)),
            ],
        );
        self::assertSame([SourcingChoice::Build, SourcingChoice::Build, false, 10, 1], [$component->recommended, $component->chosen, $component->bought, $component->quantity->value, $component->runs]);
        self::assertSame([self::PHENOLIC_COMPOSITES], $result->boughtTypeIds);
        self::assertSame([[self::PHENOLIC_COMPOSITES]], $replans->sortedCalls());
        self::assertSame([self::CAPACITOR_BATTERY, self::PHENOLIC_COMPOSITES], $this->sortedTypeIdsOf($result));
    }

    public function testTotalsTakeTheInstallationOfEveryJobOfTheRecalculatedPlanFinalProductsIncluded(): void
    {
        $result = $this->planComponentOverReaction($this->phenolicReplans());

        self::assertSame([9_600.0, 1_300.0, 0.0, 10_900.0], $this->totalsOf($result));
    }

    public function testExampleDComponentConsumedAtTwoStationsHasOneChoiceAndTheBridgeOfEachConsumerStation(): void
    {
        $jobBuildingTheComponent = $this->job(self::CAPACITOR_BATTERY, ActivityKind::Manufacturing, 1, [self::LEAF_ALPHA => 1_000]);
        $jobAtFirstStation = $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::CAPACITOR_BATTERY => 60]);
        $jobAtSecondStation = $this->job(self::SECOND_TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::CAPACITOR_BATTERY => 40]);
        $universe = $this->plannedProduction(
            [$jobAtFirstStation, $jobAtSecondStation, $jobBuildingTheComponent],
            [self::LEAF_ALPHA => 1_000],
            [self::CAPACITOR_BATTERY => $this->intermediate(100, 100, [$jobBuildingTheComponent])],
            [self::TARGET_PRODUCT => $this->firstStation(), self::SECOND_TARGET_PRODUCT => $this->secondStation(), self::CAPACITOR_BATTERY => $this->firstStation()],
        );

        $result = $this->plan(
            $universe,
            new PlannedProductionsByPurchases(['' => $universe]),
            [self::TARGET_PRODUCT, self::SECOND_TARGET_PRODUCT],
            [self::LEAF_ALPHA => 100.0, self::CAPACITOR_BATTERY => 2_000.0],
            [self::LEAF_ALPHA => 0.01, self::CAPACITOR_BATTERY => 1.0],
            new FixedJobInstallationCosts([self::CAPACITOR_BATTERY => Cost::known(new Isk(5_000.0)), self::TARGET_PRODUCT => Cost::known(new Isk(1_000.0)), self::SECOND_TARGET_PRODUCT => Cost::known(new Isk(2_000.0))]),
            bridgeIskPerCubicMeter: 10.0,
        );

        $line = $this->lineOf($result, self::CAPACITOR_BATTERY);
        $consumerStructureIds = $line->consumerStructureIds;
        sort($consumerStructureIds);
        self::assertSame(['S1', 'S2'], $consumerStructureIds);
        self::assertSame(
            [
                'build' => [113_400.0, 108_000.0, 5_000.0, 400.0],
                'jita' => [280_400.0, 200_000.0, null, 80_400.0],
            ],
            [
                'build' => $this->amountsOf($line->option(SourcingChoice::Build)),
                'jita' => $this->amountsOf($line->option(SourcingChoice::Jita)),
            ],
        );
        self::assertSame(SourcingChoice::Build, $line->chosen);
        self::assertSame(1, \count(array_filter($result->lines, static fn (DeliveredSourcingLine $candidate): bool => self::CAPACITOR_BATTERY === $candidate->typeId)));
    }

    public function testExampleEMissingJitaPriceMakesTheJitaOptionUnknownAndTheCapitalMarketIsTheOnlyEligibleOne(): void
    {
        $result = $this->planSingleLeaf(jitaUnitPrices: [], structureUnitPrices: [self::LEAF_ALPHA => 90.0], structureSellVolumes: [self::LEAF_ALPHA => 1_000]);

        $line = $this->lineOf($result, self::LEAF_ALPHA);
        $jita = $line->option(SourcingChoice::Jita);
        self::assertFalse($jita->total->isKnown());
        self::assertEquals([MissingData::marketPrice(self::LEAF_ALPHA)], $jita->total->missingData);
        self::assertFalse($jita->eligible);
        self::assertSame(SourcingChoice::Structure, $line->recommended);
        self::assertSame(90_000.0, $this->amountOf($line->chosenTotal()));
        self::assertSame(90_000.0, $this->amountOf($result->totals->purchases));
    }

    public function testExampleENoEligibleOptionLeavesNoRecommendationAndAnUnknownCostNeverZero(): void
    {
        $result = $this->planSingleLeaf(jitaUnitPrices: [], structureUnitPrices: [], structureSellVolumes: []);

        $line = $this->lineOf($result, self::LEAF_ALPHA);
        self::assertNull($line->recommended);
        self::assertNull($line->chosen);
        self::assertNull($line->chosenTotal());
        self::assertFalse($result->totals->purchases->isKnown());
        self::assertContainsEquals(MissingData::marketPrice(self::LEAF_ALPHA), $result->totals->purchases->missingData);
        self::assertContainsEquals(MissingData::structurePrice(self::LEAF_ALPHA), $result->totals->purchases->missingData);
        self::assertFalse($result->totals->total()->isKnown());
    }

    public function testAnUnknownDestinationMakesTheImportAndTheBridgeUnknownAndNothingEligible(): void
    {
        $jobWithoutStation = $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::LEAF_ALPHA => 1_000]);
        $universe = $this->plannedProduction([$jobWithoutStation], [self::LEAF_ALPHA => 1_000], [], []);

        $result = $this->plan(
            $universe,
            new PlannedProductionsByPurchases(['' => $universe]),
            [self::TARGET_PRODUCT],
            [self::LEAF_ALPHA => 100.0],
            [self::LEAF_ALPHA => 0.01],
            new FixedJobInstallationCosts([self::TARGET_PRODUCT => Cost::known(new Isk(1_000.0))]),
            structureUnitPrices: [self::LEAF_ALPHA => 90.0],
            structureSellVolumes: [self::LEAF_ALPHA => 1_000],
        );

        $line = $this->lineOf($result, self::LEAF_ALPHA);
        self::assertFalse($line->option(SourcingChoice::Jita)->total->isKnown());
        self::assertEquals([MissingData::jumpRoute()], $line->option(SourcingChoice::Jita)->total->missingData);
        self::assertEquals([MissingData::jumpRoute()], $line->option(SourcingChoice::Structure)->total->missingData);
        self::assertNull($line->recommended);
    }

    public function testATieBetweenBuildingAndBuyingFromJitaIsBuilt(): void
    {
        $jobBuildingTheComponent = $this->job(self::CAPACITOR_BATTERY, ActivityKind::Manufacturing, 1, [self::LEAF_ALPHA => 100]);
        $jobOfTheTarget = $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::CAPACITOR_BATTERY => 10]);
        $universe = $this->plannedProduction(
            [$jobOfTheTarget, $jobBuildingTheComponent],
            [self::LEAF_ALPHA => 100],
            [self::CAPACITOR_BATTERY => $this->intermediate(10, 10, [$jobBuildingTheComponent])],
            [self::TARGET_PRODUCT => $this->firstStation(), self::CAPACITOR_BATTERY => $this->firstStation()],
        );

        $result = $this->plan(
            $universe,
            new PlannedProductionsByPurchases(['' => $universe]),
            [self::TARGET_PRODUCT],
            [self::LEAF_ALPHA => 100.0, self::CAPACITOR_BATTERY => 900.0],
            [self::LEAF_ALPHA => 0.01, self::CAPACITOR_BATTERY => 0.25],
            new FixedJobInstallationCosts([self::CAPACITOR_BATTERY => Cost::known(new Isk(200.0)), self::TARGET_PRODUCT => Cost::known(new Isk(1_000.0))]),
        );

        $line = $this->lineOf($result, self::CAPACITOR_BATTERY);
        self::assertSame(
            [11_000.0, 11_000.0],
            [$this->amountOf($line->option(SourcingChoice::Build)->total), $this->amountOf($line->option(SourcingChoice::Jita)->total)],
        );
        self::assertSame(SourcingChoice::Build, $line->recommended);
    }

    public function testATieBetweenJitaAndTheCapitalMarketGoesToJita(): void
    {
        $result = $this->planSingleLeaf(
            jitaUnitPrices: [self::LEAF_ALPHA => 100.0],
            structureUnitPrices: [self::LEAF_ALPHA => 108.0],
            structureSellVolumes: [self::LEAF_ALPHA => 1_000],
        );

        $line = $this->lineOf($result, self::LEAF_ALPHA);
        self::assertSame(
            [108_000.0, 108_000.0],
            [$this->amountOf($line->option(SourcingChoice::Jita)->total), $this->amountOf($line->option(SourcingChoice::Structure)->total)],
        );
        self::assertSame(SourcingChoice::Jita, $line->recommended);
    }

    public function testTheReplanIsCalledWithExactlyThePurchasedIntermediatesAndAncestorsLeftWithoutQuantityHaveNoLine(): void
    {
        $replans = $this->boughtComponentReplans();

        $result = $this->planComponentsOverSharedLeaf($replans, buys: [self::CAPACITOR_BATTERY => true]);

        self::assertSame([[self::CAPACITOR_BATTERY]], $replans->sortedCalls());
        self::assertSame([self::CAPACITOR_BATTERY], $result->boughtTypeIds);
        self::assertSame(
            [self::CAPACITOR_BATTERY, self::ARMOR_PLATE, self::LEAF_ALPHA, self::LEAF_BETA],
            $this->sortedTypeIdsOf($result),
        );
    }

    public function testLinesCarryTheQuantityRunsAndSurplusOfTheRecalculatedPlanWithTheUniversePerUnitCost(): void
    {
        $result = $this->planComponentsOverSharedLeaf($this->boughtComponentReplans(), buys: [self::CAPACITOR_BATTERY => true]);

        $bought = $this->lineOf($result, self::CAPACITOR_BATTERY);
        self::assertSame([SourcingChoice::Build, SourcingChoice::Jita, true, 10], [$bought->recommended, $bought->chosen, $bought->bought, $bought->quantity->value]);
        self::assertSame(24_000.0, $this->amountOf($bought->chosenTotal()));

        $built = $this->lineOf($result, self::ARMOR_PLATE);
        self::assertSame([SourcingChoice::Build, false, 6, 2, 4], [$built->chosen, $built->bought, $built->quantity->value, $built->runs, $built->surplus->value]);

        $sharedLeaf = $this->lineOf($result, self::LEAF_ALPHA);
        self::assertSame([150, 50], [$sharedLeaf->universeDemand->value, $sharedLeaf->quantity->value]);
        self::assertSame(5_400.0, $this->amountOf($sharedLeaf->chosenTotal()));
        self::assertSame(7_980.0, $this->amountOf($this->lineOf($result, self::LEAF_BETA)->chosenTotal()));
    }

    public function testAnUnknownInstallationMakesTheInstallationAndTheTotalUnknownButKeepsThePurchases(): void
    {
        $result = $this->planFernite(100_000.0, $this->ferniteReplans(), installationCosts: [self::FERNITE_COMPOSITE => Cost::known(new Isk(500_000.0))]);

        self::assertSame(21_440_000.0, $this->amountOf($result->totals->purchases));
        self::assertSame(0.0, $this->amountOf($result->totals->transport));
        self::assertFalse($result->totals->installation->isKnown());
        self::assertEquals([MissingData::costIndex()], $result->totals->installation->missingData);
        self::assertFalse($result->totals->total()->isKnown());
    }

    public function testAnIntermediateCoveredByStockHasNoLineAndIsCostedAsAJitaPurchaseByItsConsumer(): void
    {
        $jobBuildingTheComponent = $this->job(self::DRONE_COMPONENT, ActivityKind::Manufacturing, 1, [self::CAPACITOR_BATTERY => 8, self::LEAF_ALPHA => 20]);
        $jobOfTheTarget = $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::DRONE_COMPONENT => 4]);
        $universe = $this->plannedProduction(
            [$jobOfTheTarget, $jobBuildingTheComponent],
            [self::LEAF_ALPHA => 20],
            [
                self::DRONE_COMPONENT => $this->intermediate(4, 4, [$jobBuildingTheComponent]),
                self::CAPACITOR_BATTERY => new Intermediate(new Quantity(8), new Quantity(8), new Quantity(0), new Quantity(0), []),
            ],
            [self::TARGET_PRODUCT => $this->firstStation(), self::DRONE_COMPONENT => $this->firstStation()],
        );

        $result = $this->plan(
            $universe,
            new PlannedProductionsByPurchases(['' => $universe]),
            [self::TARGET_PRODUCT],
            [self::LEAF_ALPHA => 100.0, self::CAPACITOR_BATTERY => 1_000.0, self::DRONE_COMPONENT => 5_000.0],
            [self::LEAF_ALPHA => 0.01, self::CAPACITOR_BATTERY => 0.5, self::DRONE_COMPONENT => 1.0],
            new FixedJobInstallationCosts([self::DRONE_COMPONENT => Cost::known(new Isk(300.0)), self::TARGET_PRODUCT => Cost::known(new Isk(1_000.0))]),
        );

        self::assertSame([self::DRONE_COMPONENT, self::LEAF_ALPHA], $this->sortedTypeIdsOf($result));
        self::assertSame(
            [13_660.0, 13_360.0, 300.0, 0.0],
            $this->amountsOf($this->lineOf($result, self::DRONE_COMPONENT)->option(SourcingChoice::Build)),
        );
    }

    private function planFernite(float $ferniteUnitPrice, PlannedProductionsByPurchases $replans, array $buys = [], ?array $installationCosts = null): DeliveredSourcing
    {
        return $this->plan(
            $this->ferniteUniverse(),
            $replans,
            [self::TARGET_PRODUCT],
            [self::LEAF_ALPHA => 100.0, self::LEAF_BETA => 250.0, self::FERNITE_COMPOSITE => $ferniteUnitPrice],
            [self::LEAF_ALPHA => 0.01, self::LEAF_BETA => 0.02, self::FERNITE_COMPOSITE => 1.0],
            new FixedJobInstallationCosts($installationCosts ?? [self::FERNITE_COMPOSITE => Cost::known(new Isk(500_000.0)), self::TARGET_PRODUCT => Cost::known(new Isk(1_000_000.0))]),
            $buys,
        );
    }

    private function ferniteJobOfTheTarget(): Job
    {
        return $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::FERNITE_COMPOSITE => 300]);
    }

    private function ferniteUniverse(): PlannedProduction
    {
        $reactionJob = $this->job(self::FERNITE_COMPOSITE, ActivityKind::Reaction, 3, [self::LEAF_ALPHA => 100_000, self::LEAF_BETA => 40_000]);

        return $this->plannedProduction(
            [$this->ferniteJobOfTheTarget(), $reactionJob],
            [self::LEAF_ALPHA => 100_000, self::LEAF_BETA => 40_000],
            [self::FERNITE_COMPOSITE => $this->intermediate(300, 300, [$reactionJob])],
            [self::TARGET_PRODUCT => $this->firstStation(), self::FERNITE_COMPOSITE => $this->reactionStation()],
        );
    }

    private function ferniteReplans(): PlannedProductionsByPurchases
    {
        return new PlannedProductionsByPurchases([
            '' => $this->ferniteUniverse(),
            (string) self::FERNITE_COMPOSITE => $this->plannedProduction(
                [$this->ferniteJobOfTheTarget()],
                [self::FERNITE_COMPOSITE => 300],
                [],
                [self::TARGET_PRODUCT => $this->firstStation()],
            ),
        ]);
    }

    private function planCapitalMarketLeaves(array $markets = []): DeliveredSourcing
    {
        $jobOfTheTarget = $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::LEAF_ALPHA => 1_000, self::LEAF_BETA => 500]);
        $universe = $this->plannedProduction([$jobOfTheTarget], [self::LEAF_ALPHA => 1_000, self::LEAF_BETA => 500], [], [self::TARGET_PRODUCT => $this->firstStation()]);

        return $this->plan(
            $universe,
            new PlannedProductionsByPurchases(['' => $universe]),
            [self::TARGET_PRODUCT],
            [self::LEAF_ALPHA => 100.0, self::LEAF_BETA => 250.0],
            [self::LEAF_ALPHA => 0.01, self::LEAF_BETA => 0.02],
            new FixedJobInstallationCosts([self::TARGET_PRODUCT => Cost::known(new Isk(1_000.0))]),
            markets: $markets,
            bridgeIskPerCubicMeter: 10.0,
            structureUnitPrices: [self::LEAF_ALPHA => 90.0, self::LEAF_BETA => 200.0],
            structureSellVolumes: [self::LEAF_ALPHA => 800, self::LEAF_BETA => 500],
        );
    }

    private function planSingleLeaf(array $jitaUnitPrices, array $structureUnitPrices, array $structureSellVolumes): DeliveredSourcing
    {
        $jobOfTheTarget = $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::LEAF_ALPHA => 1_000]);
        $universe = $this->plannedProduction([$jobOfTheTarget], [self::LEAF_ALPHA => 1_000], [], [self::TARGET_PRODUCT => $this->firstStation()]);

        return $this->plan(
            $universe,
            new PlannedProductionsByPurchases(['' => $universe]),
            [self::TARGET_PRODUCT],
            $jitaUnitPrices,
            [self::LEAF_ALPHA => 0.01],
            new FixedJobInstallationCosts([self::TARGET_PRODUCT => Cost::known(new Isk(1_000.0))]),
            structureUnitPrices: $structureUnitPrices,
            structureSellVolumes: $structureSellVolumes,
        );
    }

    private function planComponentOverReaction(PlannedProductionsByPurchases $replans): DeliveredSourcing
    {
        return $this->plan(
            $this->componentOverReactionUniverse(),
            $replans,
            [self::TARGET_PRODUCT],
            [self::LEAF_ALPHA => 100.0, self::PHENOLIC_COMPOSITES => 400.0, self::CAPACITOR_BATTERY => 2_000.0],
            [self::LEAF_ALPHA => 0.01, self::PHENOLIC_COMPOSITES => 0.1, self::CAPACITOR_BATTERY => 1.0],
            new FixedJobInstallationCosts([
                self::TARGET_PRODUCT => Cost::known(new Isk(1_000.0)),
                self::CAPACITOR_BATTERY => Cost::known(new Isk(300.0)),
                self::PHENOLIC_COMPOSITES => Cost::known(new Isk(2_000.0)),
            ]),
        );
    }

    private function componentOverReactionJobs(): array
    {
        return [
            'target' => $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::CAPACITOR_BATTERY => 10]),
            'component' => $this->job(self::CAPACITOR_BATTERY, ActivityKind::Manufacturing, 1, [self::PHENOLIC_COMPOSITES => 20]),
            'reaction' => $this->job(self::PHENOLIC_COMPOSITES, ActivityKind::Reaction, 1, [self::LEAF_ALPHA => 100]),
        ];
    }

    private function componentOverReactionUniverse(): PlannedProduction
    {
        $jobs = $this->componentOverReactionJobs();

        return $this->plannedProduction(
            [$jobs['target'], $jobs['component'], $jobs['reaction']],
            [self::LEAF_ALPHA => 100],
            [
                self::CAPACITOR_BATTERY => $this->intermediate(10, 10, [$jobs['component']]),
                self::PHENOLIC_COMPOSITES => $this->intermediate(20, 20, [$jobs['reaction']]),
            ],
            [self::TARGET_PRODUCT => $this->firstStation(), self::CAPACITOR_BATTERY => $this->firstStation(), self::PHENOLIC_COMPOSITES => $this->reactionStation()],
        );
    }

    private function phenolicReplans(): PlannedProductionsByPurchases
    {
        $jobs = $this->componentOverReactionJobs();

        return new PlannedProductionsByPurchases([
            '' => $this->componentOverReactionUniverse(),
            (string) self::PHENOLIC_COMPOSITES => $this->plannedProduction(
                [$jobs['target'], $jobs['component']],
                [self::PHENOLIC_COMPOSITES => 20],
                [self::CAPACITOR_BATTERY => $this->intermediate(10, 10, [$jobs['component']])],
                [self::TARGET_PRODUCT => $this->firstStation(), self::CAPACITOR_BATTERY => $this->firstStation()],
            ),
        ]);
    }

    private function sharedLeafJobs(): array
    {
        return [
            'target' => $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::CAPACITOR_BATTERY => 10, self::ARMOR_PLATE => 6]),
            'battery' => $this->job(self::CAPACITOR_BATTERY, ActivityKind::Manufacturing, 1, [self::LEAF_ALPHA => 100, self::LEAF_GAMMA => 200]),
            'plate' => $this->job(self::ARMOR_PLATE, ActivityKind::Manufacturing, 2, [self::LEAF_ALPHA => 50, self::LEAF_BETA => 30]),
        ];
    }

    private function sharedLeafUniverse(): PlannedProduction
    {
        $jobs = $this->sharedLeafJobs();

        return $this->plannedProduction(
            [$jobs['target'], $jobs['battery'], $jobs['plate']],
            [self::LEAF_ALPHA => 150, self::LEAF_BETA => 30, self::LEAF_GAMMA => 200],
            [
                self::CAPACITOR_BATTERY => $this->intermediate(10, 10, [$jobs['battery']]),
                self::ARMOR_PLATE => $this->intermediate(6, 10, [$jobs['plate']]),
            ],
            [self::TARGET_PRODUCT => $this->firstStation(), self::CAPACITOR_BATTERY => $this->firstStation(), self::ARMOR_PLATE => $this->firstStation()],
        );
    }

    private function boughtComponentReplans(): PlannedProductionsByPurchases
    {
        $jobs = $this->sharedLeafJobs();

        return new PlannedProductionsByPurchases([
            '' => $this->sharedLeafUniverse(),
            (string) self::CAPACITOR_BATTERY => $this->plannedProduction(
                [$jobs['target'], $jobs['plate']],
                [self::CAPACITOR_BATTERY => 10, self::LEAF_ALPHA => 50, self::LEAF_BETA => 30],
                [self::ARMOR_PLATE => $this->intermediate(6, 10, [$jobs['plate']])],
                [self::TARGET_PRODUCT => $this->firstStation(), self::ARMOR_PLATE => $this->firstStation()],
            ),
        ]);
    }

    private function planComponentsOverSharedLeaf(PlannedProductionsByPurchases $replans, array $buys): DeliveredSourcing
    {
        return $this->plan(
            $this->sharedLeafUniverse(),
            $replans,
            [self::TARGET_PRODUCT],
            [self::LEAF_ALPHA => 100.0, self::LEAF_BETA => 250.0, self::LEAF_GAMMA => 20.0, self::CAPACITOR_BATTERY => 2_000.0, self::ARMOR_PLATE => 5_000.0],
            [self::LEAF_ALPHA => 0.01, self::LEAF_BETA => 0.02, self::LEAF_GAMMA => 0.005, self::CAPACITOR_BATTERY => 0.5, self::ARMOR_PLATE => 1.0],
            new FixedJobInstallationCosts([
                self::TARGET_PRODUCT => Cost::known(new Isk(1_000.0)),
                self::CAPACITOR_BATTERY => Cost::known(new Isk(500.0)),
                self::ARMOR_PLATE => Cost::known(new Isk(400.0)),
            ]),
            $buys,
        );
    }

    private function plan(
        PlannedProduction $universe,
        PlannedProductionsByPurchases $replans,
        array $targetProductTypeIds,
        array $jitaUnitPrices,
        array $cubicMetersByTypeId,
        FixedJobInstallationCosts $installationCosts,
        array $buys = [],
        array $markets = [],
        float $bridgeIskPerCubicMeter = 0.0,
        ?array $structureUnitPrices = null,
        array $structureSellVolumes = [],
    ): DeliveredSourcing {
        $recipes = [];
        foreach ($universe->plan->jobs as $job) {
            $recipes[] = new Recipe($job->productTypeId, $job->activity, new OutputPerRun(1), [], new Runs(300), 3600);
        }
        $planner = new DeliveredSourcingPlanner(
            new InMemoryBlueprintCatalog(...$recipes),
            $installationCosts,
            new InMemoryMarketPrices($jitaUnitPrices),
            new InMemoryStructureMarketPrices(
                null === $structureUnitPrices ? [] : [self::MARKET_STRUCTURE_ID => $structureUnitPrices],
                null,
                [self::MARKET_STRUCTURE_ID => $structureSellVolumes],
            ),
            new InMemoryItemVolumes($cubicMetersByTypeId),
            new WorkTypeClassifier(new InMemoryShipProducts()),
        );

        return $planner->plan(
            $universe,
            $replans->closure(),
            new FavoriteSystems(self::OTHER_SYSTEM_ID, self::REACTION_SYSTEM_ID, new CourierHub(self::HUB_SYSTEM_ID, self::HUB_ISK_PER_CUBIC_METER, $bridgeIskPerCubicMeter)),
            null === $structureUnitPrices ? null : self::MARKET_STRUCTURE_ID,
            null === $structureUnitPrices ? null : self::MARKET_SYSTEM_ID,
            $targetProductTypeIds,
            $buys,
            $markets,
        );
    }

    private function firstStation(): ProductionStructure
    {
        return ProductionStructures::raitaru('S1', solarSystemId: self::HUB_SYSTEM_ID);
    }

    private function secondStation(): ProductionStructure
    {
        return ProductionStructures::raitaru('S2', solarSystemId: self::OTHER_SYSTEM_ID);
    }

    private function reactionStation(): ProductionStructure
    {
        return ProductionStructures::tatara('R1', solarSystemId: self::REACTION_SYSTEM_ID);
    }

    private function job(int $productTypeId, ActivityKind $activity, int $runs, array $materialQuantities): Job
    {
        return new Job(
            $productTypeId,
            $activity,
            new Runs($runs),
            new MaterialEfficiency(0),
            new TimeEfficiency(0),
            array_map(static fn (int $quantity): Quantity => new Quantity($quantity), $materialQuantities),
        );
    }

    private function intermediate(int $demand, int $produced, array $jobs): Intermediate
    {
        return new Intermediate(new Quantity($demand), new Quantity(0), new Quantity($produced), new Quantity($produced - $demand), $jobs);
    }

    private function plannedProduction(array $jobs, array $leafQuantities, array $intermediates, array $stationsByProductTypeId): PlannedProduction
    {
        $choices = [];
        foreach ($jobs as $job) {
            $station = $stationsByProductTypeId[$job->productTypeId] ?? null;
            if (null !== $station) {
                $choices[PlannedProduction::choiceKey($job->productTypeId, $job->activity)] = new StructureChoice($station, StructureChoiceStatus::Assigned, Multiplier::one());
            }
        }

        return new PlannedProduction(
            new Plan($jobs, array_map(static fn (int $quantity): Quantity => new Quantity($quantity), $leafQuantities), $intermediates),
            $choices,
        );
    }

    private function lineOf(DeliveredSourcing $result, int $typeId): DeliveredSourcingLine
    {
        foreach ($result->lines as $line) {
            if ($typeId === $line->typeId) {
                return $line;
            }
        }

        self::fail(\sprintf('No line for the type %d, lines: %s.', $typeId, implode(', ', $this->sortedTypeIdsOf($result))));
    }

    private function sortedTypeIdsOf(DeliveredSourcing $result): array
    {
        $typeIds = array_map(static fn (DeliveredSourcingLine $line): int => $line->typeId, $result->lines);
        sort($typeIds);

        return $typeIds;
    }

    private function amountOf(?Cost $cost): ?float
    {
        return null !== $cost && $cost->isKnown() ? round($cost->amount()->amount, 2) : null;
    }

    private function amountsOf(DeliveredSourcingOption $option): array
    {
        return [
            $this->amountOf($option->total),
            $this->amountOf($option->price),
            SourcingChoice::Build === $option->kind ? $this->amountOf($option->installation) : null,
            $this->amountOf($option->transport),
        ];
    }

    private function priceAndTransportOf(DeliveredSourcingOption $option): array
    {
        return [$this->amountOf($option->total), $this->amountOf($option->price), $this->amountOf($option->transport), $option->eligible];
    }

    private function totalsOf(DeliveredSourcing $result): array
    {
        return [
            $this->amountOf($result->totals->purchases),
            $this->amountOf($result->totals->installation),
            $this->amountOf($result->totals->transport),
            $this->amountOf($result->totals->total()),
        ];
    }
}
