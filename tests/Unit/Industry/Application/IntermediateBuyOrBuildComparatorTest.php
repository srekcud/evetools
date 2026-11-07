<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Constant\EveConstants;
use App\Industry\Application\BuyOrBuildChoice;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\IntermediateBuyOrBuildComparator;
use App\Industry\Application\IntermediateComparison;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Intermediate;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\OrderBookCoverage;
use App\Industry\Domain\PartialPrice;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\FixedIntermediateBuildCosts;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Industry\Application\Double\InMemoryJumpDistances;
use App\Tests\Unit\Industry\Application\Double\InMemoryMarketPrices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntermediateBuyOrBuildComparator::class)]
#[Group('issue-343')]
final class IntermediateBuyOrBuildComparatorTest extends TestCase
{
    private const int HOBGOBLIN_II = 2456;
    private const int FERNITE_COMPOSITE = 16670;
    private const int CAPACITOR_BATTERY = 3500;
    private const int WARRIOR_II = 2488;
    private const int OGRE_II = 2446;
    private const int RAM_STARSHIP_TECH = 11478;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;

    private const int MANUFACTURING_SYSTEM_ID = 30_002_187;
    private const int REACTION_SYSTEM_ID = 30_002_659;
    private const int SYSTEM_AWAY_FROM_JITA = 30_001_000;

    public function testExampleAManufacturedDroneIsBuiltWhenBuildingCostsLessThanBuyingWithTheImport(): void
    {
        $plan = $this->planOf([self::HOBGOBLIN_II => $this->intermediate(demand: 50, produced: 50, jobs: [$this->manufacturingJob(self::HOBGOBLIN_II, runs: 5)])]);

        $comparisons = $this->comparator(
            [self::HOBGOBLIN_II => Cost::known(new Isk(1_687_000.0))],
            [self::HOBGOBLIN_II => 31_900.0],
            [self::HOBGOBLIN_II => 10.0],
            [EveConstants::JITA_SOLAR_SYSTEM_ID => [self::MANUFACTURING_SYSTEM_ID => 9]],
        )->compare($plan, [], $this->favoriteSystems());

        self::assertCount(1, $comparisons);
        self::assertSame(
            [
                self::HOBGOBLIN_II, ActivityKind::Manufacturing, 5, 50, 50, 0,
                1_687_000.0, 1_595_000.0, 675_000.0, 2_270_000.0, 10.0, 9,
                BuyOrBuildChoice::Build, BuyOrBuildChoice::Buy, 583_000.0, null,
            ],
            $this->rowOf($comparisons[0]),
        );
    }

    public function testExampleBReactionIsBoughtAndTheSurplusReuseWouldMakeBuildingCheaperByTheDifference(): void
    {
        $plan = $this->planOf([self::FERNITE_COMPOSITE => $this->intermediate(demand: 50, produced: 500, jobs: [$this->reactionJob(self::FERNITE_COMPOSITE, runs: 5)])]);

        $comparisons = $this->comparator(
            [self::FERNITE_COMPOSITE => Cost::known(new Isk(15_700_000.0))],
            [self::FERNITE_COMPOSITE => 30_900.0],
            [self::FERNITE_COMPOSITE => 1.0],
            [EveConstants::JITA_SOLAR_SYSTEM_ID => [self::REACTION_SYSTEM_ID => 9]],
        )->compare($plan, [], $this->favoriteSystems());

        self::assertCount(1, $comparisons);
        self::assertSame(
            [
                self::FERNITE_COMPOSITE, ActivityKind::Reaction, 5, 50, 500, 450,
                15_700_000.0, 1_545_000.0, 67_500.0, 1_612_500.0, 1.0, 9,
                BuyOrBuildChoice::Buy, BuyOrBuildChoice::Buy, 14_087_500.0, 42_500.0,
            ],
            $this->rowOf($comparisons[0]),
        );
    }

    public function testExampleCTieBetweenBuildingAndBuyingWithTheImportIsBuiltAndSavesNothing(): void
    {
        $plan = $this->planOf([self::CAPACITOR_BATTERY => $this->intermediate(demand: 10, produced: 10, jobs: [$this->manufacturingJob(self::CAPACITOR_BATTERY, runs: 1)])]);

        $comparisons = $this->comparator(
            [self::CAPACITOR_BATTERY => Cost::known(new Isk(1_000.0))],
            [self::CAPACITOR_BATTERY => 40.0],
            [self::CAPACITOR_BATTERY => 0.4],
            [EveConstants::JITA_SOLAR_SYSTEM_ID => [self::MANUFACTURING_SYSTEM_ID => 1]],
        )->compare($plan, [], $this->favoriteSystems());

        self::assertCount(1, $comparisons);
        self::assertSame(
            [
                self::CAPACITOR_BATTERY, ActivityKind::Manufacturing, 1, 10, 10, 0,
                1_000.0, 400.0, 600.0, 1_000.0, 0.4, 1,
                BuyOrBuildChoice::Build, BuyOrBuildChoice::Buy, 0.0, null,
            ],
            $this->rowOf($comparisons[0]),
        );
    }

    public function testExampleDMissingJitaPriceMakesTheBuyCostAndBothChoicesUnknownButKeepsTheImport(): void
    {
        $plan = $this->planOf([self::WARRIOR_II => $this->intermediate(demand: 50, produced: 50, jobs: [$this->manufacturingJob(self::WARRIOR_II, runs: 5)])]);

        $comparisons = $this->comparator(
            [self::WARRIOR_II => Cost::known(new Isk(1_687_000.0))],
            [],
            [self::WARRIOR_II => 10.0],
            [EveConstants::JITA_SOLAR_SYSTEM_ID => [self::MANUFACTURING_SYSTEM_ID => 9]],
        )->compare($plan, [], $this->favoriteSystems());

        self::assertCount(1, $comparisons);
        self::assertSame(
            [
                self::WARRIOR_II, ActivityKind::Manufacturing, 5, 50, 50, 0,
                1_687_000.0, null, 675_000.0, null, 10.0, 9,
                BuyOrBuildChoice::Unknown, BuyOrBuildChoice::Unknown, null, null,
            ],
            $this->rowOf($comparisons[0]),
        );
        self::assertEquals([MissingData::marketPrice(self::WARRIOR_II)], $comparisons[0]->jitaCost->missingData);
        self::assertEquals([MissingData::marketPrice(self::WARRIOR_II)], $comparisons[0]->buyCost()->missingData);
    }

    public function testExampleENoFavoriteSystemMakesTheImportUnknownBecauseOfTheJumpRoute(): void
    {
        $plan = $this->planOf([self::OGRE_II => $this->intermediate(demand: 50, produced: 50, jobs: [$this->manufacturingJob(self::OGRE_II, runs: 5)])]);

        $comparisons = $this->comparator(
            [self::OGRE_II => Cost::known(new Isk(1_687_000.0))],
            [self::OGRE_II => 31_900.0],
            [self::OGRE_II => 10.0],
            [EveConstants::JITA_SOLAR_SYSTEM_ID => [self::MANUFACTURING_SYSTEM_ID => 9]],
        )->compare($plan, [], FavoriteSystems::none());

        self::assertCount(1, $comparisons);
        self::assertSame(
            [
                self::OGRE_II, ActivityKind::Manufacturing, 5, 50, 50, 0,
                1_687_000.0, 1_595_000.0, null, null, 10.0, null,
                BuyOrBuildChoice::Unknown, BuyOrBuildChoice::Buy, null, null,
            ],
            $this->rowOf($comparisons[0]),
        );
        self::assertEquals([MissingData::jumpRoute()], $comparisons[0]->importCost->missingData);
    }

    public function testExampleEUnreachableFavoriteSystemMakesTheImportUnknownBecauseOfTheJumpRoute(): void
    {
        $plan = $this->planOf([self::OGRE_II => $this->intermediate(demand: 50, produced: 50, jobs: [$this->manufacturingJob(self::OGRE_II, runs: 5)])]);

        $comparisons = $this->comparator(
            [self::OGRE_II => Cost::known(new Isk(1_687_000.0))],
            [self::OGRE_II => 31_900.0],
            [self::OGRE_II => 10.0],
            [],
        )->compare($plan, [], $this->favoriteSystems());

        self::assertCount(1, $comparisons);
        self::assertEquals([MissingData::jumpRoute()], $comparisons[0]->importCost->missingData);
        self::assertSame([BuyOrBuildChoice::Unknown, BuyOrBuildChoice::Buy, null], [$comparisons[0]->choice, $comparisons[0]->choiceWithoutImport, $comparisons[0]->jumps]);
    }

    public function testExampleFMissingVolumeMakesTheImportUnknownButKeepsTheJitaPrice(): void
    {
        $plan = $this->planOf([self::CAPACITOR_BATTERY => $this->intermediate(demand: 50, produced: 50, jobs: [$this->manufacturingJob(self::CAPACITOR_BATTERY, runs: 5)])]);

        $comparisons = $this->comparator(
            [self::CAPACITOR_BATTERY => Cost::known(new Isk(1_687_000.0))],
            [self::CAPACITOR_BATTERY => 31_900.0],
            [],
            [EveConstants::JITA_SOLAR_SYSTEM_ID => [self::MANUFACTURING_SYSTEM_ID => 9]],
        )->compare($plan, [], $this->favoriteSystems());

        self::assertCount(1, $comparisons);
        $comparison = $comparisons[0];
        self::assertSame(
            [1_687_000.0, 1_595_000.0, null, null, null, BuyOrBuildChoice::Unknown, BuyOrBuildChoice::Buy, null, null],
            [
                $this->amountOf($comparison->buildCost),
                $this->amountOf($comparison->jitaCost),
                $this->amountOf($comparison->importCost),
                $this->amountOf($comparison->buyCost()),
                $comparison->volumePerUnit,
                $comparison->choice,
                $comparison->choiceWithoutImport,
                $comparison->saving,
                $comparison->savingIfSurplusReused,
            ],
        );
        self::assertEquals([MissingData::itemVolume(self::CAPACITOR_BATTERY)], $comparison->importCost->missingData);
    }

    public function testUnknownBuildCostMakesBothChoicesUnknownWhateverTheJitaPrice(): void
    {
        $plan = $this->planOf([self::HOBGOBLIN_II => $this->intermediate(demand: 50, produced: 50, jobs: [$this->manufacturingJob(self::HOBGOBLIN_II, runs: 5)])]);

        $comparisons = $this->comparator(
            [self::HOBGOBLIN_II => Cost::unknown(MissingData::adjustedPrice(self::TRITANIUM))],
            [self::HOBGOBLIN_II => 31_900.0],
            [self::HOBGOBLIN_II => 10.0],
            [EveConstants::JITA_SOLAR_SYSTEM_ID => [self::MANUFACTURING_SYSTEM_ID => 9]],
        )->compare($plan, [], $this->favoriteSystems());

        self::assertCount(1, $comparisons);
        self::assertSame(
            [null, 1_595_000.0, 675_000.0, 2_270_000.0, BuyOrBuildChoice::Unknown, BuyOrBuildChoice::Unknown, null, null],
            [
                $this->amountOf($comparisons[0]->buildCost),
                $this->amountOf($comparisons[0]->jitaCost),
                $this->amountOf($comparisons[0]->importCost),
                $this->amountOf($comparisons[0]->buyCost()),
                $comparisons[0]->choice,
                $comparisons[0]->choiceWithoutImport,
                $comparisons[0]->saving,
                $comparisons[0]->savingIfSurplusReused,
            ],
        );
    }

    public function testWorkshopInJitaHasAKnownImportOfZero(): void
    {
        $plan = $this->planOf([self::HOBGOBLIN_II => $this->intermediate(demand: 50, produced: 50, jobs: [$this->manufacturingJob(self::HOBGOBLIN_II, runs: 5)])]);

        $comparisons = $this->comparator(
            [self::HOBGOBLIN_II => Cost::known(new Isk(1_687_000.0))],
            [self::HOBGOBLIN_II => 31_900.0],
            [self::HOBGOBLIN_II => 10.0],
            [],
        )->compare($plan, [], new FavoriteSystems(EveConstants::JITA_SOLAR_SYSTEM_ID, null));

        self::assertCount(1, $comparisons);
        self::assertSame(
            [0, 0.0, 1_595_000.0, BuyOrBuildChoice::Buy, 92_000.0],
            [
                $comparisons[0]->jumps,
                $this->amountOf($comparisons[0]->importCost),
                $this->amountOf($comparisons[0]->buyCost()),
                $comparisons[0]->choice,
                $comparisons[0]->saving?->amount,
            ],
        );
    }

    public function testSeveralJobsOfAnIntermediateGiveOneRowWithTheSumOfTheirRunsAndTheEfficiencyAndMaterialsOfTheFirstJob(): void
    {
        $firstJob = new Job(
            self::HOBGOBLIN_II,
            ActivityKind::Manufacturing,
            new Runs(30),
            new MaterialEfficiency(8),
            new TimeEfficiency(16),
            [self::TRITANIUM => new Quantity(900), self::PYERITE => new Quantity(300)],
        );
        $secondJob = new Job(
            self::HOBGOBLIN_II,
            ActivityKind::Manufacturing,
            new Runs(20),
            new MaterialEfficiency(10),
            new TimeEfficiency(20),
            [self::MEXALLON => new Quantity(70)],
        );
        $plan = $this->planOf([self::HOBGOBLIN_II => $this->intermediate(demand: 480, produced: 500, jobs: [$firstJob, $secondJob])]);
        $buildCosts = new FixedIntermediateBuildCosts([self::HOBGOBLIN_II => Cost::known(new Isk(1_687_000.0))]);
        $favoriteSystems = $this->favoriteSystems();

        $comparisons = $this->comparatorWith(
            $buildCosts,
            [self::HOBGOBLIN_II => 31_900.0],
            [self::HOBGOBLIN_II => 10.0],
            [EveConstants::JITA_SOLAR_SYSTEM_ID => [self::MANUFACTURING_SYSTEM_ID => 9]],
        )->compare($plan, [], $favoriteSystems);

        self::assertCount(1, $comparisons);
        self::assertSame(50, $comparisons[0]->runs);
        self::assertCount(1, $buildCosts->calls);
        $call = $buildCosts->calls[0];
        self::assertSame(
            [self::HOBGOBLIN_II, 50, 8, 16, [self::TRITANIUM, self::PYERITE]],
            [$call['typeId'], $call['runs']->value, $call['efficiency']->materialEfficiency->value, $call['efficiency']->timeEfficiency->value, $call['boughtTypeIds']],
        );
        self::assertSame([[], $favoriteSystems], [$call['structures'], $call['favoriteSystems']]);
    }

    public function testIntermediateWithoutJobHasNoRowAndRowsKeepTheOrderOfThePlan(): void
    {
        $plan = $this->planOf([
            self::WARRIOR_II => $this->intermediate(demand: 50, produced: 50, jobs: [$this->manufacturingJob(self::WARRIOR_II, runs: 5)]),
            self::CAPACITOR_BATTERY => new Intermediate(new Quantity(10), new Quantity(10), new Quantity(0), new Quantity(0), []),
            self::HOBGOBLIN_II => $this->intermediate(demand: 20, produced: 20, jobs: [$this->manufacturingJob(self::HOBGOBLIN_II, runs: 2)]),
        ]);
        $buildCosts = new FixedIntermediateBuildCosts([
            self::WARRIOR_II => Cost::known(new Isk(500_000.0)),
            self::HOBGOBLIN_II => Cost::known(new Isk(200_000.0)),
        ]);

        $comparisons = $this->comparatorWith($buildCosts, [self::WARRIOR_II => 9_000.0, self::HOBGOBLIN_II => 8_000.0], [self::WARRIOR_II => 5.0, self::HOBGOBLIN_II => 2.0], [])
            ->compare($plan, [], $this->favoriteSystems());

        self::assertSame([self::WARRIOR_II, self::HOBGOBLIN_II], array_map(static fn (IntermediateComparison $comparison): int => $comparison->typeId, $comparisons));
        self::assertSame([self::WARRIOR_II, self::HOBGOBLIN_II], array_column($buildCosts->calls, 'typeId'));
    }

    public function testJumpsAreTakenFromJitaToTheFavoriteSystemOfTheActivity(): void
    {
        $plan = $this->planOf([
            self::FERNITE_COMPOSITE => $this->intermediate(demand: 50, produced: 50, jobs: [$this->reactionJob(self::FERNITE_COMPOSITE, runs: 5)]),
            self::HOBGOBLIN_II => $this->intermediate(demand: 50, produced: 50, jobs: [$this->manufacturingJob(self::HOBGOBLIN_II, runs: 5)]),
        ]);
        $jumpDistances = new InMemoryJumpDistances([
            EveConstants::JITA_SOLAR_SYSTEM_ID => [self::MANUFACTURING_SYSTEM_ID => 3, self::REACTION_SYSTEM_ID => 7],
        ]);
        $comparator = new IntermediateBuyOrBuildComparator(
            new FixedIntermediateBuildCosts([
                self::FERNITE_COMPOSITE => Cost::known(new Isk(1_000_000.0)),
                self::HOBGOBLIN_II => Cost::known(new Isk(1_000_000.0)),
            ]),
            new InMemoryMarketPrices([self::FERNITE_COMPOSITE => 100.0, self::HOBGOBLIN_II => 100.0]),
            new InMemoryItemVolumes([self::FERNITE_COMPOSITE => 1.0, self::HOBGOBLIN_II => 1.0]),
            $jumpDistances,
        );

        $comparisons = $comparator->compare($plan, [], $this->favoriteSystems());

        self::assertSame([self::FERNITE_COMPOSITE => 7, self::HOBGOBLIN_II => 3], array_column(array_map(
            static fn (IntermediateComparison $comparison): array => [$comparison->typeId, $comparison->jumps],
            $comparisons,
        ), 1, 0));
        self::assertEqualsCanonicalizing(
            [
                [EveConstants::JITA_SOLAR_SYSTEM_ID, self::REACTION_SYSTEM_ID],
                [EveConstants::JITA_SOLAR_SYSTEM_ID, self::MANUFACTURING_SYSTEM_ID],
            ],
            $jumpDistances->requestedRoutes,
        );
    }

    public function testPartialJitaPriceIsKeptOnTheBuyCost(): void
    {
        $plan = $this->planOf([self::HOBGOBLIN_II => $this->intermediate(demand: 50, produced: 50, jobs: [$this->manufacturingJob(self::HOBGOBLIN_II, runs: 5)])]);
        $comparator = new IntermediateBuyOrBuildComparator(
            new FixedIntermediateBuildCosts([self::HOBGOBLIN_II => Cost::known(new Isk(1_687_000.0))]),
            new InMemoryMarketPrices([self::HOBGOBLIN_II => 31_900.0], [self::HOBGOBLIN_II => 0.6]),
            new InMemoryItemVolumes([self::HOBGOBLIN_II => 10.0]),
            new InMemoryJumpDistances([EveConstants::JITA_SOLAR_SYSTEM_ID => [self::MANUFACTURING_SYSTEM_ID => 9]]),
        );

        $comparisons = $comparator->compare($plan, [], $this->favoriteSystems());

        self::assertCount(1, $comparisons);
        self::assertEquals(
            [new PartialPrice(self::HOBGOBLIN_II, new OrderBookCoverage(0.6))],
            $comparisons[0]->buyCost()->partialPrices,
        );
    }

    private function favoriteSystems(): FavoriteSystems
    {
        return new FavoriteSystems(self::MANUFACTURING_SYSTEM_ID, self::REACTION_SYSTEM_ID);
    }

    /**
     * @param array<int, Cost>  $buildCosts
     * @param array<int, float> $unitPrices
     * @param array<int, float> $volumes
     * @param array<int, array<int, int>> $jumps
     */
    private function comparator(array $buildCosts, array $unitPrices, array $volumes, array $jumps): IntermediateBuyOrBuildComparator
    {
        return $this->comparatorWith(new FixedIntermediateBuildCosts($buildCosts), $unitPrices, $volumes, $jumps);
    }

    /**
     * @param array<int, float> $unitPrices
     * @param array<int, float> $volumes
     * @param array<int, array<int, int>> $jumps
     */
    private function comparatorWith(FixedIntermediateBuildCosts $buildCosts, array $unitPrices, array $volumes, array $jumps): IntermediateBuyOrBuildComparator
    {
        return new IntermediateBuyOrBuildComparator(
            $buildCosts,
            new InMemoryMarketPrices($unitPrices),
            new InMemoryItemVolumes($volumes),
            new InMemoryJumpDistances($jumps),
        );
    }

    /**
     * @param array<int, Intermediate> $intermediates
     */
    private function planOf(array $intermediates): Plan
    {
        return new Plan([], [], $intermediates);
    }

    /**
     * @param list<Job> $jobs
     */
    private function intermediate(int $demand, int $produced, array $jobs): Intermediate
    {
        return new Intermediate(
            new Quantity($demand),
            new Quantity(0),
            new Quantity($produced),
            new Quantity($produced - $demand),
            $jobs,
        );
    }

    private function manufacturingJob(int $productTypeId, int $runs): Job
    {
        return new Job($productTypeId, ActivityKind::Manufacturing, new Runs($runs), new MaterialEfficiency(10), new TimeEfficiency(20), [self::TRITANIUM => new Quantity(1_000)]);
    }

    private function reactionJob(int $productTypeId, int $runs): Job
    {
        return new Job($productTypeId, ActivityKind::Reaction, new Runs($runs), new MaterialEfficiency(0), new TimeEfficiency(0), [self::PYERITE => new Quantity(1_000)]);
    }

    private function amountOf(Cost $cost): ?float
    {
        return $cost->isKnown() ? round($cost->amount()->amount, 2) : null;
    }

    /**
     * @return list<mixed>
     */
    private function rowOf(IntermediateComparison $comparison): array
    {
        return [
            $comparison->typeId,
            $comparison->activity,
            $comparison->runs,
            $comparison->demand->value,
            $comparison->quantityProduced->value,
            $comparison->surplus->value,
            $this->amountOf($comparison->buildCost),
            $this->amountOf($comparison->jitaCost),
            $this->amountOf($comparison->importCost),
            $this->amountOf($comparison->buyCost()),
            $comparison->volumePerUnit,
            $comparison->jumps,
            $comparison->choice,
            $comparison->choiceWithoutImport,
            null === $comparison->saving ? null : round($comparison->saving->amount, 2),
            null === $comparison->savingIfSurplusReused ? null : round($comparison->savingIfSurplusReused->amount, 2),
        ];
    }
}
