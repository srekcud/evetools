<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\OwnedBlueprintUse;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\ResearchProfitability;
use App\Industry\Application\ResearchSuggestion;
use App\Industry\Application\ResearchVerdict;
use App\Industry\Application\StructureType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\SecurityClass;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\BuildCostsByMaterialEfficiency;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResearchProfitability::class)]
#[Group('issue-410')]
final class ResearchProfitabilityTest extends TestCase
{
    private const int HOBGOBLIN_II = 2456;
    private const int WARRIOR_II = 2488;
    private const int FERNITE_COMPOSITE = 16670;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;

    private const int MANUFACTURING_SYSTEM_ID = 30_002_187;
    private const int REACTION_SYSTEM_ID = 30_002_659;
    private const int STRUCTURE_SYSTEM_ID = 30_000_142;

    private const float RESEARCH_COST_INDEX = 0.05;
    private const float STRUCTURE_SYSTEM_RESEARCH_COST_INDEX = 0.05;
    private const float OTHER_FAVORITE_SYSTEM_RESEARCH_COST_INDEX = 0.07;
    private const int JOB_TIME_EFFICIENCY = 6;

    public function testResearchFromMe0CostsTheSystemShareTheNpcTaxAndTheSccAndIsWorthItWhenTheSavingExceedsIt(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->hobgoblinCosts(me0: 1_000_000.0, me1: 980_000.0),
            $this->planOf($this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0)),
            [self::HOBGOBLIN_II => $this->owned(me: 0)],
        );

        self::assertCount(1, $suggestions);
        self::assertSame(
            [self::HOBGOBLIN_II, 0, 1, 10, 20_000.0, 14_500.0, 5_500.0, ResearchVerdict::Worth],
            $this->rowOf($suggestions[0]),
        );
        self::assertSame([], $suggestions[0]->missingData);
    }

    public function testResearchWhoseSavingEqualsItsCostIsNotWorthAndNetsZero(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->hobgoblinCosts(me0: 1_000_000.0, me1: 985_500.0),
            $this->planOf($this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0)),
            [self::HOBGOBLIN_II => $this->owned(me: 0)],
        );

        self::assertCount(1, $suggestions);
        self::assertSame(
            [self::HOBGOBLIN_II, 0, 1, 10, 14_500.0, 14_500.0, 0.0, ResearchVerdict::NotWorth],
            $this->rowOf($suggestions[0]),
        );
    }

    public function testResearchWhoseSavingIsBelowItsCostIsNotWorthAndNetsNegative(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->hobgoblinCosts(me0: 1_000_000.0, me1: 990_000.0),
            $this->planOf($this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0)),
            [self::HOBGOBLIN_II => $this->owned(me: 0)],
        );

        self::assertCount(1, $suggestions);
        self::assertSame(
            [self::HOBGOBLIN_II, 0, 1, 10, 10_000.0, 14_500.0, -4_500.0, ResearchVerdict::NotWorth],
            $this->rowOf($suggestions[0]),
        );
    }

    public function testResearchThatSavesNothingIsNotWorthAndNetsTheWholeCostNegative(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->hobgoblinCosts(me0: 1_000_000.0, me1: 1_000_000.0),
            $this->planOf($this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0)),
            [self::HOBGOBLIN_II => $this->owned(me: 0)],
        );

        self::assertCount(1, $suggestions);
        self::assertSame(
            [self::HOBGOBLIN_II, 0, 1, 10, 0.0, 14_500.0, -14_500.0, ResearchVerdict::NotWorth],
            $this->rowOf($suggestions[0]),
        );
    }

    public function testUnknownProductionCostAtTheNextLevelMakesTheSavingAndTheNetUnknownAndKeepsTheReason(): void
    {
        $suggestions = $this->suggestionsOf(
            new BuildCostsByMaterialEfficiency([self::HOBGOBLIN_II => [0 => Cost::known(new Isk(1_000_000.0))]]),
            $this->planOf($this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0)),
            [self::HOBGOBLIN_II => $this->owned(me: 0)],
        );

        self::assertCount(1, $suggestions);
        self::assertSame(
            [self::HOBGOBLIN_II, 0, 1, 10, null, 14_500.0, null, ResearchVerdict::Unknown],
            $this->rowOf($suggestions[0]),
        );
        self::assertEquals([MissingData::marketPrice(self::HOBGOBLIN_II)], $suggestions[0]->missingData);
    }

    public function testMissingResearchCostIndexMakesTheCostAndTheNetUnknownButKeepsTheSaving(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->hobgoblinCosts(me0: 1_000_000.0, me1: 980_000.0),
            $this->planOf($this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0)),
            [self::HOBGOBLIN_II => $this->owned(me: 0)],
            costIndices: InMemorySystemCostIndices::none(),
        );

        self::assertCount(1, $suggestions);
        self::assertSame(
            [self::HOBGOBLIN_II, 0, 1, 10, 20_000.0, null, null, ResearchVerdict::Unknown],
            $this->rowOf($suggestions[0]),
        );
        self::assertEquals([MissingData::costIndex()], $suggestions[0]->missingData);
    }

    public function testNeitherStructureNorFavoriteSystemMakesTheResearchCostUnknownBecauseOfTheIndex(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->hobgoblinCosts(me0: 1_000_000.0, me1: 980_000.0),
            $this->planOf($this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0)),
            [self::HOBGOBLIN_II => $this->owned(me: 0)],
            favoriteSystems: FavoriteSystems::none(),
        );

        self::assertCount(1, $suggestions);
        self::assertNull($suggestions[0]->researchCost);
        self::assertSame(ResearchVerdict::Unknown, $suggestions[0]->verdict);
        self::assertEquals([MissingData::costIndex()], $suggestions[0]->missingData);
    }

    public function testMissingAdjustedPriceOfAMaterialMakesTheEivAndTheResearchCostUnknown(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->hobgoblinCosts(me0: 1_000_000.0, me1: 980_000.0),
            $this->planOf($this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0)),
            [self::HOBGOBLIN_II => $this->owned(me: 0)],
            adjustedPrices: [self::TRITANIUM => 5_000.0],
        );

        self::assertCount(1, $suggestions);
        self::assertSame(
            [self::HOBGOBLIN_II, 0, 1, 10, 20_000.0, null, null, ResearchVerdict::Unknown],
            $this->rowOf($suggestions[0]),
        );
        self::assertEquals([MissingData::adjustedPrice(self::PYERITE)], $suggestions[0]->missingData);
    }

    public function testRunsAreTheSumOfTheJobsOfTheProductAndTheCostsAreAskedOncePerLevelWithThoseRunsAndTheJobTimeEfficiency(): void
    {
        $buildCosts = $this->hobgoblinCosts(me0: 1_000_000.0, me1: 980_000.0);

        $suggestions = $this->suggestionsOf(
            $buildCosts,
            $this->planOf(
                $this->manufacturingJob(self::HOBGOBLIN_II, runs: 6, me: 0),
                $this->manufacturingJob(self::HOBGOBLIN_II, runs: 4, me: 0),
            ),
            [self::HOBGOBLIN_II => $this->owned(me: 0)],
        );

        self::assertCount(1, $suggestions);
        self::assertSame(10, $suggestions[0]->runs);
        self::assertSame(
            [
                ['typeId' => self::HOBGOBLIN_II, 'runs' => 10, 'materialEfficiency' => 0, 'timeEfficiency' => self::JOB_TIME_EFFICIENCY, 'boughtTypeIds' => [self::TRITANIUM]],
                ['typeId' => self::HOBGOBLIN_II, 'runs' => 10, 'materialEfficiency' => 1, 'timeEfficiency' => self::JOB_TIME_EFFICIENCY, 'boughtTypeIds' => [self::TRITANIUM]],
            ],
            $buildCosts->calls,
        );
    }

    public function testConfiguredStructureGivesItsCostBonusItsFacilityTaxAndItsOwnSystemIndex(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->hobgoblinCosts(me0: 1_000_000.0, me1: 980_000.0),
            $this->planOf($this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0)),
            [self::HOBGOBLIN_II => $this->owned(me: 0)],
            structures: [$this->structure(costRoleBonus: 0.9, facilityTaxRate: 0.01)],
            costIndices: new InMemorySystemCostIndices([
                self::STRUCTURE_SYSTEM_ID => [ActivityKind::ResearchMaterial->name => self::STRUCTURE_SYSTEM_RESEARCH_COST_INDEX],
                self::MANUFACTURING_SYSTEM_ID => [ActivityKind::ResearchMaterial->name => self::OTHER_FAVORITE_SYSTEM_RESEARCH_COST_INDEX],
            ]),
        );

        self::assertCount(1, $suggestions);
        self::assertSame(
            [self::HOBGOBLIN_II, 0, 1, 10, 20_000.0, 15_000.0, 5_000.0, ResearchVerdict::Worth],
            $this->rowOf($suggestions[0]),
        );
    }

    public function testNextLevelCostGrowsWithTheLevelMultiplierOfTheResearchTable(): void
    {
        $suggestions = $this->suggestionsOf(
            new BuildCostsByMaterialEfficiency([self::WARRIOR_II => [4 => Cost::known(new Isk(3_000_000.0)), 5 => Cost::known(new Isk(2_900_000.0))]]),
            $this->planOf($this->manufacturingJob(self::WARRIOR_II, runs: 3, me: 4)),
            [self::WARRIOR_II => $this->owned(me: 4)],
        );

        self::assertCount(1, $suggestions);
        self::assertSame(
            [self::WARRIOR_II, 4, 5, 3, 100_000.0, 232_000.0, -132_000.0, ResearchVerdict::NotWorth],
            $this->rowOf($suggestions[0]),
        );
    }

    public function testTwoProductsGiveTwoSuggestionsInTheOrderOfTheJobsOfThePlan(): void
    {
        $suggestions = $this->suggestionsOf(
            new BuildCostsByMaterialEfficiency([
                self::WARRIOR_II => [4 => Cost::known(new Isk(3_000_000.0)), 5 => Cost::known(new Isk(2_900_000.0))],
                self::HOBGOBLIN_II => [0 => Cost::known(new Isk(1_000_000.0)), 1 => Cost::known(new Isk(980_000.0))],
            ]),
            $this->planOf(
                $this->manufacturingJob(self::WARRIOR_II, runs: 3, me: 4),
                $this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0),
            ),
            [self::HOBGOBLIN_II => $this->owned(me: 0), self::WARRIOR_II => $this->owned(me: 4)],
        );

        self::assertSame([self::WARRIOR_II, self::HOBGOBLIN_II], $this->productsOf($suggestions));
        self::assertSame([ResearchVerdict::NotWorth, ResearchVerdict::Worth], array_map(static fn (ResearchSuggestion $suggestion): ResearchVerdict => $suggestion->verdict, $suggestions));
    }

    public function testOwnedCopyGetsNoSuggestion(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->costsOfBothProducts(),
            $this->planOf(
                $this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0),
                $this->manufacturingJob(self::WARRIOR_II, runs: 3, me: 4),
            ),
            [self::HOBGOBLIN_II => $this->owned(me: 0, original: false), self::WARRIOR_II => $this->owned(me: 4)],
        );

        self::assertSame([self::WARRIOR_II], $this->productsOf($suggestions));
    }

    public function testOriginalAlreadyAtMe10GetsNoSuggestionAndNoCostIsAsked(): void
    {
        $buildCosts = $this->costsOfBothProducts();

        $suggestions = $this->suggestionsOf(
            $buildCosts,
            $this->planOf(
                $this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 10),
                $this->manufacturingJob(self::WARRIOR_II, runs: 3, me: 4),
            ),
            [self::HOBGOBLIN_II => $this->owned(me: 10), self::WARRIOR_II => $this->owned(me: 4)],
        );

        self::assertSame([self::WARRIOR_II], $this->productsOf($suggestions));
        self::assertSame([self::WARRIOR_II, self::WARRIOR_II], array_column($buildCosts->calls, 'typeId'));
    }

    public function testProductWithoutOwnedBlueprintGetsNoSuggestion(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->costsOfBothProducts(),
            $this->planOf(
                $this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0),
                $this->manufacturingJob(self::WARRIOR_II, runs: 3, me: 4),
            ),
            [self::WARRIOR_II => $this->owned(me: 4)],
        );

        self::assertSame([self::WARRIOR_II], $this->productsOf($suggestions));
    }

    public function testReactionGetsNoSuggestionEvenWithAnOwnedOriginal(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->costsOfBothProducts(),
            $this->planOf(
                new Job(self::FERNITE_COMPOSITE, ActivityKind::Reaction, new Runs(5), new MaterialEfficiency(0), new TimeEfficiency(0), [self::PYERITE => new Quantity(1_000)]),
                $this->manufacturingJob(self::WARRIOR_II, runs: 3, me: 4),
            ),
            [self::FERNITE_COMPOSITE => $this->owned(me: 0), self::WARRIOR_II => $this->owned(me: 4)],
        );

        self::assertSame([self::WARRIOR_II], $this->productsOf($suggestions));
    }

    public function testProductOfThePlanWithoutRecipeInTheCatalogIsIgnored(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->costsOfBothProducts(),
            $this->planOf(
                $this->manufacturingJob(self::MEXALLON, runs: 10, me: 0),
                $this->manufacturingJob(self::WARRIOR_II, runs: 3, me: 4),
            ),
            [self::MEXALLON => $this->owned(me: 0), self::WARRIOR_II => $this->owned(me: 4)],
        );

        self::assertSame([self::WARRIOR_II], $this->productsOf($suggestions));
    }

    private function suggestionsOf(
        BuildCostsByMaterialEfficiency $buildCosts,
        Plan $plan,
        array $ownedBlueprints,
        array $structures = [],
        ?InMemorySystemCostIndices $costIndices = null,
        ?FavoriteSystems $favoriteSystems = null,
        array $adjustedPrices = [self::TRITANIUM => 5_000.0, self::PYERITE => 10_000.0, self::MEXALLON => 25_000.0],
    ): array {
        $profitability = new ResearchProfitability(
            $buildCosts,
            new InMemoryBlueprintCatalog(
                $this->recipe(self::HOBGOBLIN_II, ActivityKind::Manufacturing, [self::TRITANIUM => 1_000, self::PYERITE => 500]),
                $this->recipe(self::WARRIOR_II, ActivityKind::Manufacturing, [self::MEXALLON => 200]),
                $this->recipe(self::FERNITE_COMPOSITE, ActivityKind::Reaction, [self::PYERITE => 100]),
            ),
            new InMemoryAdjustedPrices($adjustedPrices),
            $costIndices ?? new InMemorySystemCostIndices([self::MANUFACTURING_SYSTEM_ID => [ActivityKind::ResearchMaterial->name => self::RESEARCH_COST_INDEX]]),
        );

        return $profitability->suggestionsOf(
            $plan,
            $ownedBlueprints,
            $structures,
            $favoriteSystems ?? new FavoriteSystems(self::MANUFACTURING_SYSTEM_ID, self::REACTION_SYSTEM_ID),
        );
    }

    private function costsOfBothProducts(): BuildCostsByMaterialEfficiency
    {
        return new BuildCostsByMaterialEfficiency([
            self::HOBGOBLIN_II => [0 => Cost::known(new Isk(1_000_000.0)), 1 => Cost::known(new Isk(980_000.0))],
            self::WARRIOR_II => [4 => Cost::known(new Isk(3_000_000.0)), 5 => Cost::known(new Isk(2_900_000.0))],
        ]);
    }

    private function productsOf(array $suggestions): array
    {
        return array_map(static fn (ResearchSuggestion $suggestion): int => $suggestion->productTypeId, $suggestions);
    }

    private function hobgoblinCosts(float $me0, float $me1): BuildCostsByMaterialEfficiency
    {
        return new BuildCostsByMaterialEfficiency([self::HOBGOBLIN_II => [0 => Cost::known(new Isk($me0)), 1 => Cost::known(new Isk($me1))]]);
    }

    private function recipe(int $productTypeId, ActivityKind $activity, array $baseQuantitiesPerRunByTypeId): Recipe
    {
        $materials = [];
        foreach ($baseQuantitiesPerRunByTypeId as $typeId => $baseQuantityPerRun) {
            $materials[] = new RecipeMaterial($typeId, new Quantity($baseQuantityPerRun));
        }

        return new Recipe($productTypeId, $activity, new OutputPerRun(1), $materials, new Runs(200), 900);
    }

    private function planOf(Job ...$jobs): Plan
    {
        return new Plan(array_values($jobs), [], []);
    }

    private function manufacturingJob(int $productTypeId, int $runs, int $me): Job
    {
        return new Job($productTypeId, ActivityKind::Manufacturing, new Runs($runs), new MaterialEfficiency($me), new TimeEfficiency(self::JOB_TIME_EFFICIENCY), [self::TRITANIUM => new Quantity(1_000)]);
    }

    private function owned(int $me, bool $original = true): OwnedBlueprintUse
    {
        return new OwnedBlueprintUse(new BlueprintEfficiency(new MaterialEfficiency($me), new TimeEfficiency(self::JOB_TIME_EFFICIENCY)), $original);
    }

    private function structure(float $costRoleBonus, float $facilityTaxRate): ProductionStructure
    {
        return new ProductionStructure(
            'workshop',
            StructureType::EngineeringComplex,
            SecurityClass::HighSec,
            self::STRUCTURE_SYSTEM_ID,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new Multiplier(0.99),
            [],
            new Multiplier($costRoleBonus),
            $facilityTaxRate,
        );
    }

    private function rowOf(ResearchSuggestion $suggestion): array
    {
        return [
            $suggestion->productTypeId,
            $suggestion->currentLevel,
            $suggestion->targetLevel,
            $suggestion->runs,
            $this->roundedAmountOf($suggestion->materialSaving),
            $this->roundedAmountOf($suggestion->researchCost),
            null === $suggestion->net ? null : round($suggestion->net, 2),
            $suggestion->verdict,
        ];
    }

    private function roundedAmountOf(?Isk $isk): ?float
    {
        return null === $isk ? null : round($isk->amount, 2);
    }
}
