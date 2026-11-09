<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Opportunities;

use App\Service\Industry\Opportunities\CapacityLimit;
use App\Service\Industry\Opportunities\FreeSlots;
use App\Service\Industry\Opportunities\OpportunityContext;
use App\Service\Industry\Opportunities\OpportunityRanking;
use App\Service\Industry\Opportunities\OpportunitySort;
use App\Service\Industry\Opportunities\ProductionOpportunityRanker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionOpportunityRanker::class)]
#[Group('issue-356')]
final class ProductionOpportunityRankerTest extends TestCase
{
    private const int INDUSTRY_SKILL_ID = 3380;
    private const int ADVANCED_INDUSTRY_SKILL_ID = 3388;
    private const int REACTIONS_SKILL_ID = 45746;
    private const int HIGH_ENERGY_PHYSICS_SKILL_ID = 11433;

    private const int HAMMERHEAD_TYPE_ID = 2_446;
    private const int HAMMERHEAD_BLUEPRINT_ID = 2_447;
    private const int HOBGOBLIN_TYPE_ID = 2_454;
    private const int HOBGOBLIN_BLUEPRINT_ID = 2_455;
    private const int FERROGEL_TYPE_ID = 16_683;
    private const int FERROGEL_FORMULA_ID = 46_174;

    public function testARankedProductCarriesItsWeeklyQuantityLimitProfitAndIskPerSlotHour(): void
    {
        $ranking = $this->rank(
            ['jita' => [$this->hammerhead()]],
            $this->context(baseTimes: [self::HAMMERHEAD_BLUEPRINT_ID => 1_662], freeSlots: new FreeSlots(14, 0), capitalCap: 59_500_000),
        );

        self::assertSame(0, $ranking->unknownProductCount);
        self::assertCount(1, $ranking->rows);
        $row = $ranking->rows[0];
        self::assertSame(self::HAMMERHEAD_TYPE_ID, $row->typeId);
        self::assertSame('Hammerhead II', $row->typeName);
        self::assertSame('jita', $row->market);
        self::assertSame(90, $row->weeklyQuantity);
        self::assertSame(CapacityLimit::Capital, $row->limitedBy);
        self::assertEqualsWithDelta(5_767_967.7, $row->weeklyProfit, 0.01);
        self::assertEqualsWithDelta(138_819.92, $row->iskPerSlotHour, 0.01);
        self::assertTrue($row->groupHint);
        self::assertFalse($row->loss);
        self::assertSame([], $row->missingSkills);
    }

    public function testTheGroupHintIsOffWhenTheMarketLimitsBelowTheCapital(): void
    {
        $ranking = $this->rank(
            ['capital' => [$this->hammerhead(['dailyVolume' => 14.0, 'profitPerUnit' => 148_257.78])]],
            $this->context(baseTimes: [self::HAMMERHEAD_BLUEPRINT_ID => 1_662], freeSlots: new FreeSlots(14, 0), capitalCap: 59_500_000),
        );

        $row = $ranking->rows[0];
        self::assertSame('capital', $row->market);
        self::assertSame(9, $row->weeklyQuantity);
        self::assertSame(CapacityLimit::Market, $row->limitedBy);
        self::assertFalse($row->groupHint);
    }

    public function testWithoutACapitalCapNothingIsLimitedByTheCapital(): void
    {
        $ranking = $this->rank(
            ['jita' => [$this->hammerhead()]],
            $this->context(baseTimes: [self::HAMMERHEAD_BLUEPRINT_ID => 1_662], freeSlots: new FreeSlots(14, 0), capitalCap: null),
        );

        self::assertSame(126, $ranking->rows[0]->weeklyQuantity);
        self::assertSame(CapacityLimit::Market, $ranking->rows[0]->limitedBy);
        self::assertFalse($ranking->rows[0]->groupHint);
    }

    public function testTheSortByWeeklyProfitAndByIskPerSlotHourOrderTheSameProductsDifferently(): void
    {
        $scanRows = ['jita' => [
            $this->hammerhead(['typeId' => 1, 'blueprintTypeId' => 101, 'profitPerUnit' => 1_000.0, 'dailyVolume' => 100.0]),
            $this->hammerhead(['typeId' => 2, 'blueprintTypeId' => 102, 'profitPerUnit' => 100.0, 'dailyVolume' => 500.0]),
        ]];
        $context = $this->context(baseTimes: [101 => 3_600, 102 => 36], freeSlots: new FreeSlots(100, 0), capitalCap: null);

        $byWeek = $this->rank($scanRows, $context, OpportunitySort::WeeklyProfit);
        $byHour = $this->rank($scanRows, $context, OpportunitySort::IskPerSlotHour);

        self::assertSame([1, 2], array_map(static fn ($row): int => $row->typeId, $byWeek->rows));
        self::assertSame([2, 1], array_map(static fn ($row): int => $row->typeId, $byHour->rows));
    }

    public function testTheSameProductAtJitaAndAtTheCapitalIsListedOncePerMarket(): void
    {
        $ranking = $this->rank(
            ['jita' => [$this->hammerhead()], 'capital' => [$this->hammerhead(['dailyVolume' => 14.0])]],
            $this->context(baseTimes: [self::HAMMERHEAD_BLUEPRINT_ID => 1_662], freeSlots: new FreeSlots(14, 0), capitalCap: null),
        );

        self::assertSame(['jita', 'capital'], array_map(static fn ($row): string => $row->market, $ranking->rows));
    }

    public function testAProductMissingASkillIsGreyedAfterTheCapableProductsAndNamesTheSkill(): void
    {
        $ranking = $this->rank(
            ['jita' => [
                $this->hammerhead(['typeId' => 1, 'blueprintTypeId' => 101, 'profitPerUnit' => 1_000_000.0]),
                $this->hammerhead(['typeId' => 2, 'blueprintTypeId' => 102, 'profitPerUnit' => 10.0]),
            ]],
            $this->context(
                requiredSkills: [101 => [['skillId' => self::HIGH_ENERGY_PHYSICS_SKILL_ID, 'level' => 5]]],
                characterSkillLevels: [[self::HIGH_ENERGY_PHYSICS_SKILL_ID => 3]],
                baseTimes: [101 => 1_000, 102 => 1_000],
                freeSlots: new FreeSlots(5, 0),
            ),
        );

        self::assertSame([2, 1], array_map(static fn ($row): int => $row->typeId, $ranking->rows));
        $greyed = $ranking->rows[1];
        self::assertCount(1, $greyed->missingSkills);
        self::assertSame(self::HIGH_ENERGY_PHYSICS_SKILL_ID, $greyed->missingSkills[0]->skillId);
        self::assertSame('High Energy Physics', $greyed->missingSkills[0]->name);
        self::assertSame(5, $greyed->missingSkills[0]->level);
        self::assertNull($greyed->weeklyQuantity);
        self::assertNull($greyed->limitedBy);
        self::assertNull($greyed->weeklyProfit);
        self::assertNull($greyed->iskPerSlotHour);
        self::assertTrue($greyed->groupHint);
    }

    public function testTheMissingSkillsAreThoseOfTheCharacterWhoLacksTheFewest(): void
    {
        $ranking = $this->rank(
            ['jita' => [$this->hammerhead()]],
            $this->context(
                requiredSkills: [self::HAMMERHEAD_BLUEPRINT_ID => [
                    ['skillId' => self::HIGH_ENERGY_PHYSICS_SKILL_ID, 'level' => 5],
                    ['skillId' => self::INDUSTRY_SKILL_ID, 'level' => 3],
                ]],
                characterSkillLevels: [[], [self::INDUSTRY_SKILL_ID => 3]],
                baseTimes: [self::HAMMERHEAD_BLUEPRINT_ID => 1_000],
                freeSlots: new FreeSlots(5, 0),
            ),
        );

        self::assertSame([self::HIGH_ENERGY_PHYSICS_SKILL_ID], array_map(static fn ($skill): int => $skill->skillId, $ranking->rows[0]->missingSkills));
    }

    public function testTheDurationOfAUnitUsesTheFastestCharacterAbleToProduceIt(): void
    {
        $ranking = $this->rank(
            ['jita' => [$this->hammerhead(['teUsed' => 0, 'profitPerUnit' => 1_000.0])]],
            $this->context(
                requiredSkills: [self::HAMMERHEAD_BLUEPRINT_ID => [['skillId' => self::HIGH_ENERGY_PHYSICS_SKILL_ID, 'level' => 3]]],
                characterSkillLevels: [
                    [self::HIGH_ENERGY_PHYSICS_SKILL_ID => 3],
                    [self::HIGH_ENERGY_PHYSICS_SKILL_ID => 5, self::INDUSTRY_SKILL_ID => 5, self::ADVANCED_INDUSTRY_SKILL_ID => 5],
                ],
                baseTimes: [self::HAMMERHEAD_BLUEPRINT_ID => 1_000],
                freeSlots: new FreeSlots(5, 0),
            ),
        );

        $secondsPerUnit = 1_000 * 0.8 * 0.85 * 0.95;
        self::assertEqualsWithDelta(1_000.0 * 3_600 / round($secondsPerUnit), $ranking->rows[0]->iskPerSlotHour, 0.01);
    }

    public function testTheTimeEfficiencyOfTheBlueprintAndTheOutputPerRunShortenTheDurationOfAUnit(): void
    {
        $ranking = $this->rank(
            ['jita' => [$this->hammerhead(['teUsed' => 20, 'outputPerRun' => 10, 'profitPerUnit' => 1_000.0])]],
            $this->context(baseTimes: [self::HAMMERHEAD_BLUEPRINT_ID => 1_000], freeSlots: new FreeSlots(5, 0)),
        );

        self::assertEqualsWithDelta(1_000.0 * 3_600 / 80.0, $ranking->rows[0]->iskPerSlotHour, 0.01);
    }

    public function testAReactionUsesTheReactionSlotsAndTheReactionsSkill(): void
    {
        $ranking = $this->rank(
            ['jita' => [$this->scanRow([
                'typeId' => self::FERROGEL_TYPE_ID,
                'blueprintTypeId' => self::FERROGEL_FORMULA_ID,
                'activityType' => 'reaction',
                'teUsed' => 0,
                'dailyVolume' => 10_000.0,
            ])]],
            $this->context(
                characterSkillLevels: [[self::REACTIONS_SKILL_ID => 5]],
                baseTimes: [self::FERROGEL_FORMULA_ID => 1_000],
                freeSlots: new FreeSlots(0, 1),
            ),
        );

        $row = $ranking->rows[0];
        self::assertSame(756, $row->weeklyQuantity);
        self::assertSame(CapacityLimit::Slots, $row->limitedBy);
    }

    public function testAProductAtALossComesLastWithoutAQuantityOrAProfit(): void
    {
        $ranking = $this->rank(
            ['jita' => [
                $this->hammerhead(['typeId' => 1, 'blueprintTypeId' => 101, 'profitPerUnit' => -50.0]),
                $this->hammerhead(['typeId' => 2, 'blueprintTypeId' => 102, 'profitPerUnit' => 10.0]),
                $this->hammerhead(['typeId' => 3, 'blueprintTypeId' => 103, 'profitPerUnit' => 5.0]),
            ]],
            $this->context(
                requiredSkills: [102 => [['skillId' => self::HIGH_ENERGY_PHYSICS_SKILL_ID, 'level' => 5]]],
                characterSkillLevels: [[]],
                baseTimes: [101 => 1_000, 102 => 1_000, 103 => 1_000],
                freeSlots: new FreeSlots(5, 0),
            ),
        );

        self::assertSame([3, 2, 1], array_map(static fn ($row): int => $row->typeId, $ranking->rows));
        $loss = $ranking->rows[2];
        self::assertTrue($loss->loss);
        self::assertNull($loss->weeklyQuantity);
        self::assertNull($loss->weeklyProfit);
        self::assertNull($loss->iskPerSlotHour);
        self::assertFalse($ranking->rows[0]->loss);
    }

    public function testProductsWithUnknownDataAreLeftOutAndCounted(): void
    {
        $ranking = $this->rank(
            ['jita' => [
                $this->hammerhead(['typeId' => 1, 'blueprintTypeId' => 101]),
                $this->hammerhead(['typeId' => 2, 'blueprintTypeId' => 102, 'profitPerUnit' => null, 'unitCost' => null, 'unknownReason' => 'missing_sale_price']),
                $this->hammerhead(['typeId' => 3, 'blueprintTypeId' => 103]),
                $this->hammerhead(['typeId' => 4, 'blueprintTypeId' => 104, 'unknownReason' => 'missing_daily_volume', 'dailyVolume' => null]),
            ]],
            $this->context(baseTimes: [101 => 1_000, 102 => 1_000, 104 => 1_000], freeSlots: new FreeSlots(5, 0)),
        );

        self::assertSame([1], array_map(static fn ($row): int => $row->typeId, $ranking->rows));
        self::assertSame(3, $ranking->unknownProductCount);
    }

    public function testWithUnknownFreeSlotsTheQuantityTheProfitAndTheIskPerSlotHourAreUnknown(): void
    {
        $ranking = $this->rank(
            ['jita' => [$this->hammerhead()]],
            $this->context(baseTimes: [self::HAMMERHEAD_BLUEPRINT_ID => 1_000], freeSlots: null),
        );

        $row = $ranking->rows[0];
        self::assertNull($row->weeklyQuantity);
        self::assertNull($row->limitedBy);
        self::assertNull($row->weeklyProfit);
        self::assertNull($row->iskPerSlotHour);
        self::assertFalse($row->loss);
    }

    public function testNoMoreThanOneHundredRowsAreReturned(): void
    {
        $scanRows = [];
        $baseTimes = [];
        foreach (range(1, 101) as $typeId) {
            $scanRows[] = $this->hammerhead(['typeId' => $typeId, 'blueprintTypeId' => 1_000 + $typeId, 'profitPerUnit' => (float) $typeId]);
            $baseTimes[1_000 + $typeId] = 1_000;
        }

        $ranking = $this->rank(['jita' => $scanRows], $this->context(baseTimes: $baseTimes, freeSlots: new FreeSlots(5, 0)));

        self::assertCount(100, $ranking->rows);
        self::assertSame(101, $ranking->rows[0]->typeId);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $scanRowsByMarket
     */
    private function rank(array $scanRowsByMarket, OpportunityContext $context, OpportunitySort $sort = OpportunitySort::WeeklyProfit): OpportunityRanking
    {
        return (new ProductionOpportunityRanker())->rank($scanRowsByMarket, $context, $sort);
    }

    /**
     * @param array<int, list<array{skillId: int, level: int}>> $requiredSkills
     * @param list<array<int, int>> $characterSkillLevels
     * @param array<int, int> $baseTimes
     */
    private function context(
        array $requiredSkills = [],
        array $characterSkillLevels = [[]],
        array $baseTimes = [],
        ?FreeSlots $freeSlots = new FreeSlots(1, 1),
        ?int $capitalCap = null,
    ): OpportunityContext {
        return new OpportunityContext(
            $requiredSkills,
            $characterSkillLevels,
            [self::HIGH_ENERGY_PHYSICS_SKILL_ID => 'High Energy Physics', self::INDUSTRY_SKILL_ID => 'Industry'],
            $baseTimes,
            $freeSlots,
            $capitalCap,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function hammerhead(array $overrides = []): array
    {
        return $this->scanRow($overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function scanRow(array $overrides = []): array
    {
        return array_replace([
            'typeId' => self::HAMMERHEAD_TYPE_ID,
            'typeName' => 'Hammerhead II',
            'groupName' => 'Medium Drone',
            'categoryLabel' => 'T2',
            'marginPercent' => 9.71,
            'profitPerUnit' => 64_088.53,
            'dailyVolume' => 180.0,
            'iskPerDay' => 0.0,
            'materialCost' => 600_000.0,
            'importCost' => 0.0,
            'exportCost' => 0.0,
            'sellPrice' => 760_000.0,
            'unitCost' => 659_773.47,
            'blueprintTypeId' => self::HAMMERHEAD_BLUEPRINT_ID,
            'outputPerRun' => 1,
            'teUsed' => 0,
            'meUsed' => 2,
            'activityType' => 'manufacturing',
            'unknownReason' => null,
        ], $overrides);
    }
}
