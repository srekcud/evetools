<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Cost;
use App\Industry\Domain\EivMaterial;
use App\Industry\Domain\EstimatedItemValue;
use App\Industry\Domain\InstallCost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\ResearchJobDuration;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\ResearchLevels;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;

final readonly class ResearchProfitability
{
    public function __construct(
        private IntermediateBuildCosts $buildCosts,
        private BlueprintCatalog $blueprints,
        private AdjustedPrices $adjustedPrices,
        private SystemCostIndices $costIndices,
        private ResearchBaseTimes $researchBaseTimes,
        private CharacterSkillLevels $characterSkillLevels,
    ) {
    }

    /**
     * @param array<int, OwnedBlueprintUse> $ownedBlueprints by product typeId
     * @param list<ProductionStructure>     $structures
     *
     * @return list<ResearchSuggestion>
     */
    public function suggestionsOf(Plan $plan, array $ownedBlueprints, array $structures, FavoriteSystems $favoriteSystems, bool $alphaClone = false): array
    {
        $selector = new StructureSelector($structures, $favoriteSystems, $this->costIndices);

        $suggestions = [];
        foreach ($this->candidateFirstJobsOf($plan, $ownedBlueprints) as $firstJob) {
            $recipe = $this->blueprints->recipeFor($firstJob->productTypeId);
            if (null === $recipe) {
                continue;
            }

            $runs = array_sum(array_map(static fn (Job $job): int => $job->runs->value, $plan->jobsFor($firstJob->productTypeId)));
            $suggestions[] = $this->suggestionOf($firstJob, $runs, $recipe, $ownedBlueprints[$firstJob->productTypeId], $structures, $favoriteSystems, $selector, $alphaClone);
        }

        return $suggestions;
    }

    /**
     * @param array<int, OwnedBlueprintUse> $ownedBlueprints
     *
     * @return array<int, Job> the first job of each candidate product, by product typeId
     */
    private function candidateFirstJobsOf(Plan $plan, array $ownedBlueprints): array
    {
        $candidates = [];
        foreach ($plan->jobs as $job) {
            $owned = $ownedBlueprints[$job->productTypeId] ?? null;
            if (ActivityKind::Manufacturing !== $job->activity
                || null === $owned
                || !$owned->original
                || $job->materialEfficiency->value >= ResearchLevels::MAXIMUM_LEVEL
                || isset($candidates[$job->productTypeId])
            ) {
                continue;
            }
            $candidates[$job->productTypeId] = $job;
        }

        return $candidates;
    }

    /**
     * @param list<ProductionStructure> $structures
     */
    private function suggestionOf(Job $firstJob, int $runs, Recipe $recipe, OwnedBlueprintUse $owned, array $structures, FavoriteSystems $favoriteSystems, StructureSelector $selector, bool $alphaClone): ResearchSuggestion
    {
        $currentLevel = $firstJob->materialEfficiency->value;
        $targetLevel = $currentLevel + 1;

        $currentCost = $this->productionCostOf($firstJob, $runs, $currentLevel, $structures, $favoriteSystems);
        $nextCost = $this->productionCostOf($firstJob, $runs, $targetLevel, $structures, $favoriteSystems);
        $researchCost = $this->researchCostOf($recipe, $targetLevel, $selector, $favoriteSystems, $alphaClone);

        $saving = $currentCost->isKnown() && $nextCost->isKnown()
            ? $currentCost->amount()->amount - $nextCost->amount()->amount
            : null;
        $researchAmount = $researchCost->isKnown() ? $researchCost->amount()->amount : null;
        $net = null === $saving || null === $researchAmount ? null : $saving - $researchAmount;

        return new ResearchSuggestion(
            $firstJob->productTypeId,
            $currentLevel,
            $targetLevel,
            $runs,
            null === $saving ? null : new Isk(max(0.0, $saving)),
            $researchCost->isKnown() ? $researchCost->amount() : null,
            $net,
            self::verdictOf($net),
            $currentCost->plus($nextCost)->plus($researchCost)->missingData,
            $this->researchEstimateOf(ActivityKind::ResearchMaterial, $firstJob->productTypeId, $targetLevel, $owned->researcherEveCharacterId),
            $firstJob->timeEfficiency->value >= TimeEfficiency::MAXIMUM_LEVEL
                ? null
                : $this->researchEstimateOf(ActivityKind::ResearchTime, $firstJob->productTypeId, intdiv($firstJob->timeEfficiency->value, TimeEfficiency::LEVEL_STEP) + 1, $owned->researcherEveCharacterId),
        );
    }

    private function researchEstimateOf(ActivityKind $activity, int $productTypeId, int $targetLevel, ?int $researcherEveCharacterId): ResearchJobEstimate
    {
        $baseTimeSeconds = $this->researchBaseTimes->of($productTypeId, $activity);

        if (null === $baseTimeSeconds || null === $researcherEveCharacterId) {
            return new ResearchJobEstimate($targetLevel, ResearchJobDuration::unknown(array_values(array_filter([
                null === $baseTimeSeconds ? MissingData::researchBaseTime($productTypeId) : null,
                null === $researcherEveCharacterId ? MissingData::researcher() : null,
            ]))));
        }

        $researchSkillTypeId = ResearchJobDuration::researchSkillTypeIdOf($activity);

        return new ResearchJobEstimate($targetLevel, ResearchJobDuration::of(
            $activity,
            $baseTimeSeconds,
            $targetLevel,
            $this->characterSkillLevels->levelOf($researcherEveCharacterId, $researchSkillTypeId),
            $this->characterSkillLevels->levelOf($researcherEveCharacterId, ResearchJobDuration::ADVANCED_INDUSTRY_SKILL_TYPE_ID),
            Multiplier::one(),
        ));
    }

    /**
     * @param list<ProductionStructure> $structures
     */
    private function productionCostOf(Job $job, int $runs, int $materialEfficiency, array $structures, FavoriteSystems $favoriteSystems): Cost
    {
        return $this->buildCosts->totalCostOf(
            $job->productTypeId,
            new Runs($runs),
            new BlueprintEfficiency(new MaterialEfficiency($materialEfficiency), $job->timeEfficiency),
            array_keys($job->materials),
            $structures,
            $favoriteSystems,
        );
    }

    private function researchCostOf(Recipe $recipe, int $targetLevel, StructureSelector $selector, FavoriteSystems $favoriteSystems, bool $alphaClone): Cost
    {
        $structure = $selector->select(ActivityKind::ResearchMaterial, null)->structure;
        $solarSystemId = $favoriteSystems->systemOrFavorite($structure, ActivityKind::ResearchMaterial);
        $costIndex = null === $solarSystemId ? null : $this->costIndices->costIndexOf($solarSystemId, ActivityKind::ResearchMaterial);

        return InstallCost::forResearch(
            ActivityKind::ResearchMaterial,
            EstimatedItemValue::of(new Runs(1), $this->eivMaterialsOf($recipe)),
            $targetLevel,
            $costIndex,
            null === $structure ? Multiplier::one() : $structure->installCostMultiplierFor(ActivityKind::ResearchMaterial),
            null === $structure ? ProductionCostCalculator::NPC_STATION_FACILITY_TAX_RATE : $structure->facilityTaxRate,
            $alphaClone,
        )->total;
    }

    /**
     * @return list<EivMaterial>
     */
    private function eivMaterialsOf(Recipe $recipe): array
    {
        return array_map(
            fn (RecipeMaterial $material): EivMaterial => new EivMaterial(
                $material->typeId,
                $material->baseQuantityPerRun,
                $this->adjustedPrices->adjustedPriceOf($material->typeId),
            ),
            $recipe->materials,
        );
    }

    private static function verdictOf(?float $net): ResearchVerdict
    {
        if (null === $net) {
            return ResearchVerdict::Unknown;
        }

        return $net > 0.0 ? ResearchVerdict::Worth : ResearchVerdict::NotWorth;
    }
}
