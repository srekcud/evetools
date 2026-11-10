<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Entity\CachedCharacterSkill;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\OwnedBlueprintUse;
use App\Industry\Application\ResearchJobEstimate;
use App\Industry\Application\ResearchProfitability;
use App\Industry\Application\ResearchVerdict;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\BuildCostsByMaterialEfficiency;
use App\Tests\Unit\Industry\Application\Double\InMemoryAdjustedPrices;
use App\Tests\Unit\Industry\Application\Double\InMemoryCharacterSkillLevels;
use App\Tests\Unit\Industry\Application\Double\InMemoryResearchBaseTimes;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResearchProfitability::class)]
#[Group('issue-475')]
final class ResearchProfitabilityDurationTest extends TestCase
{
    private const int HOBGOBLIN_II = 2456;
    private const int WARRIOR_II = 2488;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;

    private const int MANUFACTURING_SYSTEM_ID = 30_002_187;
    private const int REACTION_SYSTEM_ID = 30_002_659;
    private const float RESEARCH_COST_INDEX = 0.05;

    private const int HOBGOBLIN_ME_RESEARCH_BASE_TIME = 2_100;
    private const int HOBGOBLIN_TE_RESEARCH_BASE_TIME = 2_800;
    private const int WARRIOR_ME_RESEARCH_BASE_TIME = 4_200;
    private const int WARRIOR_TE_RESEARCH_BASE_TIME = 3_150;

    private const int SEASONED_RESEARCHER = 9_001;
    private const int NOVICE_RESEARCHER = 9_002;
    private const int RESEARCHER_WITHOUT_ADVANCED_INDUSTRY = 9_003;

    public function testMeAndTeDurationsUseTheSdeBaseTimesAndTheSkillsOfTheResearcherAtTheNextLevelOfEach(): void
    {
        $suggestions = $this->suggestionsOf(
            new BuildCostsByMaterialEfficiency([self::HOBGOBLIN_II => [4 => Cost::known(new Isk(3_000_000.0)), 5 => Cost::known(new Isk(2_900_000.0))]]),
            [$this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 4, te: 6)],
            [self::HOBGOBLIN_II => $this->owned(me: 4, te: 6, researcher: self::SEASONED_RESEARCHER)],
        );

        self::assertCount(1, $suggestions);
        self::assertSame([5, true, 44_352], $this->estimateOf($suggestions[0]->meResearch));
        self::assertNotNull($suggestions[0]->teResearch);
        self::assertSame([4, true, 26_545], $this->estimateOf($suggestions[0]->teResearch));
    }

    public function testMissingMetallurgyMakesTheMeDurationUnknownAndLeavesTheTeDurationComputed(): void
    {
        $suggestions = $this->suggestionsOf(
            new BuildCostsByMaterialEfficiency([self::WARRIOR_II => [2 => Cost::known(new Isk(3_000_000.0)), 3 => Cost::known(new Isk(2_900_000.0))]]),
            [$this->manufacturingJob(self::WARRIOR_II, runs: 3, me: 2, te: 10)],
            [self::WARRIOR_II => $this->owned(me: 2, te: 10, researcher: self::NOVICE_RESEARCHER)],
            skillLevels: [self::NOVICE_RESEARCHER => [
                CachedCharacterSkill::SKILL_RESEARCH => 1,
                CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 1,
            ]],
        );

        self::assertCount(1, $suggestions);
        self::assertSame(3, $suggestions[0]->meResearch->targetLevel);
        self::assertFalse($suggestions[0]->meResearch->duration->isKnown());
        self::assertEquals([MissingData::researchSkill(CachedCharacterSkill::SKILL_METALLURGY)], $suggestions[0]->meResearch->duration->missingData);
        self::assertNotNull($suggestions[0]->teResearch);
        self::assertSame([6, true, 221_160], $this->estimateOf($suggestions[0]->teResearch));
    }

    public function testMissingAdvancedIndustryMakesBothDurationsUnknown(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->hobgoblinCosts(),
            [$this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0, te: 6)],
            [self::HOBGOBLIN_II => $this->owned(me: 0, te: 6, researcher: self::RESEARCHER_WITHOUT_ADVANCED_INDUSTRY)],
        );

        self::assertCount(1, $suggestions);
        self::assertNotNull($suggestions[0]->teResearch);
        self::assertEquals([MissingData::researchSkill(CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY)], $suggestions[0]->meResearch->duration->missingData);
        self::assertEquals([MissingData::researchSkill(CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY)], $suggestions[0]->teResearch->duration->missingData);
        self::assertSame([false, false], [$suggestions[0]->meResearch->duration->isKnown(), $suggestions[0]->teResearch->duration->isKnown()]);
    }

    public function testOriginalWithoutResearcherCharacterMakesBothDurationsUnknownBecauseOfTheResearcher(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->hobgoblinCosts(),
            [$this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0, te: 6)],
            [self::HOBGOBLIN_II => $this->owned(me: 0, te: 6, researcher: null)],
        );

        self::assertCount(1, $suggestions);
        self::assertNotNull($suggestions[0]->teResearch);
        self::assertSame([1, false], [$suggestions[0]->meResearch->targetLevel, $suggestions[0]->meResearch->duration->isKnown()]);
        self::assertEquals([MissingData::researcher()], $suggestions[0]->meResearch->duration->missingData);
        self::assertSame([4, false], [$suggestions[0]->teResearch->targetLevel, $suggestions[0]->teResearch->duration->isKnown()]);
        self::assertEquals([MissingData::researcher()], $suggestions[0]->teResearch->duration->missingData);
    }

    public function testUnknownMeResearchBaseTimeIsReportedForTheProductWhileTheTeDurationStaysIndependent(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->hobgoblinCosts(),
            [$this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0, te: 6)],
            [self::HOBGOBLIN_II => $this->owned(me: 0, te: 6, researcher: self::SEASONED_RESEARCHER)],
            baseTimes: [self::HOBGOBLIN_II => [ActivityKind::ResearchTime->name => self::HOBGOBLIN_TE_RESEARCH_BASE_TIME]],
        );

        self::assertCount(1, $suggestions);
        self::assertFalse($suggestions[0]->meResearch->duration->isKnown());
        self::assertEquals([MissingData::researchBaseTime(self::HOBGOBLIN_II)], $suggestions[0]->meResearch->duration->missingData);
        self::assertNotNull($suggestions[0]->teResearch);
        self::assertSame([4, true, 26_545], $this->estimateOf($suggestions[0]->teResearch));
    }

    public function testOriginalAtTe20HasNoTeResearchWhileTheMeDurationIsComputed(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->hobgoblinCosts(),
            [$this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0, te: 20)],
            [self::HOBGOBLIN_II => $this->owned(me: 0, te: 20, researcher: self::SEASONED_RESEARCHER)],
        );

        self::assertCount(1, $suggestions);
        self::assertNull($suggestions[0]->teResearch);
        self::assertSame([1, true, 1_386], $this->estimateOf($suggestions[0]->meResearch));
    }

    public function testUnknownMeDurationNeverChangesTheVerdictNorTheMissingDataOfTheSuggestion(): void
    {
        $suggestions = $this->suggestionsOf(
            $this->hobgoblinCosts(),
            [$this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 0, te: 6)],
            [self::HOBGOBLIN_II => $this->owned(me: 0, te: 6, researcher: null)],
        );

        self::assertCount(1, $suggestions);
        self::assertEquals([MissingData::researcher()], $suggestions[0]->meResearch->duration->missingData);
        self::assertSame(ResearchVerdict::Worth, $suggestions[0]->verdict);
        self::assertEqualsWithDelta(5_500.0, $suggestions[0]->net, 0.01);
        self::assertSame([], $suggestions[0]->missingData);
    }

    public function testEachProductReadsTheSkillsOfItsOwnResearcher(): void
    {
        $suggestions = $this->suggestionsOf(
            new BuildCostsByMaterialEfficiency([
                self::HOBGOBLIN_II => [4 => Cost::known(new Isk(3_000_000.0)), 5 => Cost::known(new Isk(2_900_000.0))],
                self::WARRIOR_II => [2 => Cost::known(new Isk(3_000_000.0)), 3 => Cost::known(new Isk(2_900_000.0))],
            ]),
            [
                $this->manufacturingJob(self::HOBGOBLIN_II, runs: 10, me: 4, te: 6),
                $this->manufacturingJob(self::WARRIOR_II, runs: 3, me: 2, te: 10),
            ],
            [
                self::HOBGOBLIN_II => $this->owned(me: 4, te: 6, researcher: self::SEASONED_RESEARCHER),
                self::WARRIOR_II => $this->owned(me: 2, te: 10, researcher: self::NOVICE_RESEARCHER),
            ],
        );

        self::assertCount(2, $suggestions);
        self::assertSame(
            [[5, true, 44_352], [3, true, 20_777]],
            [$this->estimateOf($suggestions[0]->meResearch), $this->estimateOf($suggestions[1]->meResearch)],
        );
    }

    private function suggestionsOf(
        BuildCostsByMaterialEfficiency $buildCosts,
        array $jobs,
        array $ownedBlueprints,
        ?array $skillLevels = null,
        ?array $baseTimes = null,
    ): array {
        $profitability = new ResearchProfitability(
            $buildCosts,
            new InMemoryBlueprintCatalog(
                $this->recipe(self::HOBGOBLIN_II, [self::TRITANIUM => 1_000, self::PYERITE => 500]),
                $this->recipe(self::WARRIOR_II, [self::MEXALLON => 200]),
            ),
            new InMemoryAdjustedPrices([self::TRITANIUM => 5_000.0, self::PYERITE => 10_000.0, self::MEXALLON => 25_000.0]),
            new InMemorySystemCostIndices([self::MANUFACTURING_SYSTEM_ID => [ActivityKind::ResearchMaterial->name => self::RESEARCH_COST_INDEX]]),
            new InMemoryResearchBaseTimes($baseTimes ?? [
                self::HOBGOBLIN_II => [
                    ActivityKind::ResearchMaterial->name => self::HOBGOBLIN_ME_RESEARCH_BASE_TIME,
                    ActivityKind::ResearchTime->name => self::HOBGOBLIN_TE_RESEARCH_BASE_TIME,
                ],
                self::WARRIOR_II => [
                    ActivityKind::ResearchMaterial->name => self::WARRIOR_ME_RESEARCH_BASE_TIME,
                    ActivityKind::ResearchTime->name => self::WARRIOR_TE_RESEARCH_BASE_TIME,
                ],
            ]),
            new InMemoryCharacterSkillLevels($skillLevels ?? [
                self::SEASONED_RESEARCHER => [
                    CachedCharacterSkill::SKILL_METALLURGY => 5,
                    CachedCharacterSkill::SKILL_RESEARCH => 4,
                    CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 4,
                ],
                self::NOVICE_RESEARCHER => [
                    CachedCharacterSkill::SKILL_METALLURGY => 2,
                    CachedCharacterSkill::SKILL_RESEARCH => 1,
                    CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => 1,
                ],
                self::RESEARCHER_WITHOUT_ADVANCED_INDUSTRY => [
                    CachedCharacterSkill::SKILL_METALLURGY => 5,
                    CachedCharacterSkill::SKILL_RESEARCH => 4,
                ],
            ]),
        );

        return $profitability->suggestionsOf(
            new Plan($jobs, [], []),
            $ownedBlueprints,
            [],
            new FavoriteSystems(self::MANUFACTURING_SYSTEM_ID, self::REACTION_SYSTEM_ID),
        );
    }

    private function estimateOf(ResearchJobEstimate $estimate): array
    {
        return [
            $estimate->targetLevel,
            $estimate->duration->isKnown(),
            $estimate->duration->isKnown() ? $estimate->duration->jobDuration()->seconds : null,
        ];
    }

    private function hobgoblinCosts(): BuildCostsByMaterialEfficiency
    {
        return new BuildCostsByMaterialEfficiency([self::HOBGOBLIN_II => [0 => Cost::known(new Isk(1_000_000.0)), 1 => Cost::known(new Isk(980_000.0))]]);
    }

    private function recipe(int $productTypeId, array $baseQuantitiesPerRunByTypeId): Recipe
    {
        $materials = [];
        foreach ($baseQuantitiesPerRunByTypeId as $typeId => $baseQuantityPerRun) {
            $materials[] = new RecipeMaterial($typeId, new Quantity($baseQuantityPerRun));
        }

        return new Recipe($productTypeId, ActivityKind::Manufacturing, new OutputPerRun(1), $materials, new Runs(200), 900);
    }

    private function manufacturingJob(int $productTypeId, int $runs, int $me, int $te): Job
    {
        return new Job($productTypeId, ActivityKind::Manufacturing, new Runs($runs), new MaterialEfficiency($me), new TimeEfficiency($te), [self::TRITANIUM => new Quantity(1_000)]);
    }

    private function owned(int $me, int $te, ?int $researcher): OwnedBlueprintUse
    {
        return new OwnedBlueprintUse(new BlueprintEfficiency(new MaterialEfficiency($me), new TimeEfficiency($te)), true, $researcher);
    }
}
