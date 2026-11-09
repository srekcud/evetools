<?php

declare(strict_types=1);

namespace App\Service\Industry\Opportunities;

use App\Entity\CachedCharacterSkill;
use App\Industry\Domain\JobDuration;
use App\Industry\Domain\Runs;
use App\Industry\Domain\SkillTimeModifier;
use App\Industry\Domain\TimeEfficiency;

final readonly class ProductionOpportunityRanker
{
    public const int MAXIMUM_ROWS = 100;

    private const string REACTION_ACTIVITY = 'reaction';
    private const int UNTRAINED_LEVEL = 0;
    private const int OPPORTUNITY_RANK = 0;
    private const int BLOCKED_RANK = 1;
    private const int LOSS_RANK = 2;
    private const int SINGLE_RUN = 1;

    private ProductionCapacityCalculator $capacity;

    public function __construct()
    {
        $this->capacity = new ProductionCapacityCalculator();
    }

    /**
     * @param array<string, list<array<string, mixed>>> $scanRowsByMarket scanner rows, keyed by market ("jita" or "capital")
     */
    public function rank(array $scanRowsByMarket, OpportunityContext $context, OpportunitySort $sort): OpportunityRanking
    {
        $opportunities = [];
        $unknownProductCount = 0;
        foreach ($scanRowsByMarket as $market => $scanRows) {
            foreach ($scanRows as $scanRow) {
                $opportunity = $this->opportunityOf((string) $market, $scanRow, $context);
                if (null === $opportunity) {
                    ++$unknownProductCount;

                    continue;
                }
                $opportunities[] = $opportunity;
            }
        }

        usort($opportunities, fn (ProductionOpportunity $first, ProductionOpportunity $second): int => $this->compare($first, $second, $sort));

        return new OpportunityRanking(\array_slice($opportunities, 0, self::MAXIMUM_ROWS), $unknownProductCount);
    }

    /**
     * @param array<string, mixed> $scanRow
     */
    private function opportunityOf(string $market, array $scanRow, OpportunityContext $context): ?ProductionOpportunity
    {
        $profitPerUnit = $scanRow['profitPerUnit'];
        $unitCost = $scanRow['unitCost'];
        $dailyVolume = $scanRow['dailyVolume'];
        $baseTimeSeconds = $context->baseTimeSecondsByBlueprint[$scanRow['blueprintTypeId']] ?? null;
        if (null !== $scanRow['unknownReason'] || null === $profitPerUnit || null === $unitCost || null === $dailyVolume || null === $baseTimeSeconds || $unitCost <= 0.0) {
            return null;
        }

        $isReaction = self::REACTION_ACTIVITY === $scanRow['activityType'];
        $requiredSkills = $context->requiredSkillsByBlueprint[$scanRow['blueprintTypeId']] ?? [];
        $loss = $profitPerUnit <= 0.0;

        $secondsPerRun = null;
        $missingSkills = [];
        if ([] !== $context->characterSkillLevels) {
            $missingSkills = $this->fewestMissingSkills($requiredSkills, $context);
            if ([] === $missingSkills) {
                $secondsPerRun = $this->fastestSecondsPerRun($scanRow, $isReaction, $baseTimeSeconds, $requiredSkills, $context);
                if (null === $secondsPerRun) {
                    return null;
                }
            }
        }
        $secondsPerUnit = null === $secondsPerRun ? null : $secondsPerRun / $scanRow['outputPerRun'];

        $quantity = null;
        $limitedBy = null;
        $weeklyProfit = null;
        $iskPerSlotHour = null;
        $freeSlots = null === $context->freeSlots ? null : ($isReaction ? $context->freeSlots->reaction : $context->freeSlots->manufacturing);
        if (!$loss && [] === $missingSkills && null !== $secondsPerUnit && null !== $freeSlots) {
            $weekly = $this->capacity->weekly($dailyVolume, $unitCost, $context->capitalCap, $freeSlots, $secondsPerUnit);
            $quantity = $weekly->quantity;
            $limitedBy = $weekly->limitedBy;
            $weeklyProfit = $quantity * $profitPerUnit;
            $iskPerSlotHour = $this->capacity->iskPerSlotHour($profitPerUnit, $secondsPerUnit);
        }

        $capitalLimit = $this->capacity->capitalLimit($context->capitalCap, $unitCost);
        $groupHint = !$loss && ([] !== $missingSkills || (null !== $capitalLimit && $capitalLimit < $this->capacity->marketLimit($dailyVolume)));

        return new ProductionOpportunity(
            $scanRow['typeId'],
            $scanRow['typeName'],
            $scanRow['categoryLabel'],
            $market,
            $scanRow['sellPrice'],
            $dailyVolume,
            $unitCost,
            $profitPerUnit,
            $scanRow['marginPercent'],
            $quantity,
            $limitedBy,
            $weeklyProfit,
            $iskPerSlotHour,
            $missingSkills,
            $groupHint,
            $loss,
        );
    }

    /**
     * @param list<array{skillId: int, level: int}> $requiredSkills
     *
     * @return list<MissingSkill>
     */
    private function fewestMissingSkills(array $requiredSkills, OpportunityContext $context): array
    {
        $fewest = null;
        foreach ($context->characterSkillLevels as $skillLevels) {
            $missing = $this->missingSkillsOf($requiredSkills, $skillLevels, $context);
            if (null === $fewest || \count($missing) < \count($fewest)) {
                $fewest = $missing;
            }
        }

        return $fewest ?? [];
    }

    /**
     * @param list<array{skillId: int, level: int}> $requiredSkills
     * @param array<int, int> $skillLevels
     *
     * @return list<MissingSkill>
     */
    private function missingSkillsOf(array $requiredSkills, array $skillLevels, OpportunityContext $context): array
    {
        $missing = [];
        foreach ($requiredSkills as $requiredSkill) {
            if (($skillLevels[$requiredSkill['skillId']] ?? self::UNTRAINED_LEVEL) < $requiredSkill['level']) {
                $missing[] = new MissingSkill($requiredSkill['skillId'], $context->skillNames[$requiredSkill['skillId']] ?? null, $requiredSkill['level']);
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $scanRow
     * @param list<array{skillId: int, level: int}> $requiredSkills
     */
    private function fastestSecondsPerRun(array $scanRow, bool $isReaction, int $baseTimeSeconds, array $requiredSkills, OpportunityContext $context): ?int
    {
        $fastest = null;
        foreach ($context->characterSkillLevels as $skillLevels) {
            if ([] !== $this->missingSkillsOf($requiredSkills, $skillLevels, $context)) {
                continue;
            }
            $seconds = $this->secondsPerRun($scanRow, $isReaction, $baseTimeSeconds, $requiredSkills, $skillLevels);
            if (null === $fastest || $seconds < $fastest) {
                $fastest = $seconds;
            }
        }

        return null !== $fastest && $fastest > 0 ? $fastest : null;
    }

    /**
     * @param array<string, mixed> $scanRow
     * @param list<array{skillId: int, level: int}> $requiredSkills
     * @param array<int, int> $skillLevels
     */
    private function secondsPerRun(array $scanRow, bool $isReaction, int $baseTimeSeconds, array $requiredSkills, array $skillLevels): int
    {
        $runs = new Runs(self::SINGLE_RUN);
        if ($isReaction) {
            return JobDuration::forReactionJob(
                $baseTimeSeconds,
                $runs,
                SkillTimeModifier::forReaction($skillLevels[CachedCharacterSkill::SKILL_REACTIONS] ?? self::UNTRAINED_LEVEL),
            )->seconds;
        }

        $scienceSkillLevels = [];
        foreach ($requiredSkills as $requiredSkill) {
            if (!\in_array($requiredSkill['skillId'], [CachedCharacterSkill::SKILL_INDUSTRY, CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY], true)) {
                $scienceSkillLevels[] = $skillLevels[$requiredSkill['skillId']] ?? self::UNTRAINED_LEVEL;
            }
        }

        return JobDuration::forManufacturingJob(
            $baseTimeSeconds,
            $runs,
            new TimeEfficiency($scanRow['teUsed']),
            SkillTimeModifier::forManufacturing(
                $skillLevels[CachedCharacterSkill::SKILL_INDUSTRY] ?? self::UNTRAINED_LEVEL,
                $skillLevels[CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY] ?? self::UNTRAINED_LEVEL,
                $scienceSkillLevels,
            ),
        )->seconds;
    }

    private function compare(ProductionOpportunity $first, ProductionOpportunity $second, OpportunitySort $sort): int
    {
        $byRank = $this->rankOf($first) <=> $this->rankOf($second);
        if (0 !== $byRank) {
            return $byRank;
        }

        $byMeasure = self::OPPORTUNITY_RANK === $this->rankOf($first)
            ? $this->descendingNullsLast($this->measureOf($first, $sort), $this->measureOf($second, $sort))
            : $second->profitPerUnit <=> $first->profitPerUnit;

        return 0 !== $byMeasure ? $byMeasure : [$first->typeId, $first->market] <=> [$second->typeId, $second->market];
    }

    private function rankOf(ProductionOpportunity $opportunity): int
    {
        return match (true) {
            $opportunity->loss => self::LOSS_RANK,
            [] !== $opportunity->missingSkills => self::BLOCKED_RANK,
            default => self::OPPORTUNITY_RANK,
        };
    }

    private function measureOf(ProductionOpportunity $opportunity, OpportunitySort $sort): ?float
    {
        return OpportunitySort::WeeklyProfit === $sort ? $opportunity->weeklyProfit : $opportunity->iskPerSlotHour;
    }

    private function descendingNullsLast(?float $first, ?float $second): int
    {
        return [null === $first, $second] <=> [null === $second, $first];
    }
}
