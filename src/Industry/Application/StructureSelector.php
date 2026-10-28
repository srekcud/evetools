<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;

/**
 * Structure of a job (spec R4, D3, D3b): the assigned structure, otherwise the best structure able to run the activity
 * (smallest material multiplier for the category of the job product, then the most recently configured one).
 * The favorite system of the activity filters first; without a suitable structure there, the best of all is kept.
 */
final readonly class StructureSelector
{
    private const float INSTALL_RATE_TOLERANCE = 1e-9;

    /**
     * @param list<ProductionStructure> $structures
     */
    public function __construct(private array $structures, private FavoriteSystems $favoriteSystems, private SystemCostIndices $costIndices)
    {
    }

    public function select(ActivityKind $activity, ?string $productCategory, ?ProductionStructure $assignedStructure = null): StructureChoice
    {
        if (null !== $assignedStructure && $assignedStructure->canRun($activity)) {
            return new StructureChoice(
                $assignedStructure,
                StructureChoiceStatus::Assigned,
                $assignedStructure->materialMultiplierFor($activity, $productCategory),
            );
        }

        $choice = $this->selectBest($activity, $productCategory);

        return null === $assignedStructure ? $choice : $choice->ignoring($assignedStructure);
    }

    private function selectBest(ActivityKind $activity, ?string $productCategory): StructureChoice
    {
        $candidates = array_values(array_filter(
            $this->structures,
            static fn (ProductionStructure $structure): bool => $structure->canRun($activity),
        ));
        if ([] === $candidates) {
            return StructureChoice::notConfigured();
        }

        return match ($activity) {
            ActivityKind::Copying, ActivityKind::Invention => $this->lowestInstallRate($candidates, $activity),
            ActivityKind::Manufacturing, ActivityKind::Reaction => $this->bestMaterialMultiplier($candidates, $activity, $productCategory),
        };
    }

    /**
     * @param non-empty-list<ProductionStructure> $candidates
     */
    private function bestMaterialMultiplier(array $candidates, ActivityKind $activity, ?string $productCategory): StructureChoice
    {
        if (null !== $productCategory && null !== $this->favoriteSystems->forActivity($activity)) {
            $adaptedInFavoriteSystem = array_values(array_filter(
                $candidates,
                fn (ProductionStructure $structure): bool => $this->favoriteSystems->contains($structure, $activity) && $structure->isAdaptedTo($productCategory),
            ));

            return [] === $adaptedInFavoriteSystem
                ? $this->choiceOf($this->smallestMaterialMultiplier($candidates, $activity, $productCategory), StructureChoiceStatus::BestOutsideFavoriteSystem, $activity, $productCategory)
                : $this->choiceOf($this->smallestMaterialMultiplier($adaptedInFavoriteSystem, $activity, $productCategory), StructureChoiceStatus::BestInFavoriteSystem, $activity, $productCategory);
        }

        $structure = $this->smallestMaterialMultiplier($candidates, $activity, $productCategory);

        return $this->choiceOf($structure, $this->statusWithoutFavoriteFilter($structure, $activity), $activity, $productCategory);
    }

    /**
     * @param non-empty-list<ProductionStructure> $candidates
     */
    private function lowestInstallRate(array $candidates, ActivityKind $activity): StructureChoice
    {
        $installRates = [];
        foreach ($candidates as $candidate) {
            $installRates[$candidate->id] = $this->installRateOf($candidate, $activity);
        }

        $knownInstallRates = array_filter($installRates, static fn (?float $installRate): bool => null !== $installRate);
        if ([] !== $knownInstallRates) {
            $lowestInstallRate = min($knownInstallRates);
            $candidates = array_values(array_filter(
                $candidates,
                static fn (ProductionStructure $structure): bool => null !== $installRates[$structure->id] && $installRates[$structure->id] - $lowestInstallRate < self::INSTALL_RATE_TOLERANCE,
            ));
        }

        $structure = $this->firstByPreference($candidates, $activity);

        return $this->choiceOf($structure, $this->statusWithoutFavoriteFilter($structure, $activity), $activity, null);
    }

    private function installRateOf(ProductionStructure $structure, ActivityKind $activity): ?float
    {
        $solarSystemId = $this->favoriteSystems->systemOrFavorite($structure, $activity);
        $costIndex = null === $solarSystemId ? null : $this->costIndices->costIndexOf($solarSystemId, $activity);

        return null === $costIndex ? null : $costIndex * $structure->costRoleBonus->value + $structure->facilityTaxRate;
    }

    /**
     * @param non-empty-list<ProductionStructure> $candidates
     */
    private function smallestMaterialMultiplier(array $candidates, ActivityKind $activity, ?string $productCategory): ProductionStructure
    {
        usort(
            $candidates,
            fn (ProductionStructure $left, ProductionStructure $right): int => $left->materialMultiplierFor($activity, $productCategory)->value <=> $right->materialMultiplierFor($activity, $productCategory)->value
                ?: $this->compareByPreference($left, $right, $activity),
        );

        return $candidates[0];
    }

    /**
     * @param list<ProductionStructure> $candidates
     */
    private function firstByPreference(array $candidates, ActivityKind $activity): ProductionStructure
    {
        usort($candidates, fn (ProductionStructure $left, ProductionStructure $right): int => $this->compareByPreference($left, $right, $activity));

        return $candidates[0];
    }

    private function compareByPreference(ProductionStructure $left, ProductionStructure $right, ActivityKind $activity): int
    {
        return [!$this->favoriteSystems->contains($left, $activity), $right->configuredAt, $left->id]
            <=> [!$this->favoriteSystems->contains($right, $activity), $left->configuredAt, $right->id];
    }

    private function statusWithoutFavoriteFilter(ProductionStructure $structure, ActivityKind $activity): StructureChoiceStatus
    {
        return $this->favoriteSystems->contains($structure, $activity) ? StructureChoiceStatus::BestInFavoriteSystem : StructureChoiceStatus::BestConfigured;
    }

    private function choiceOf(ProductionStructure $structure, StructureChoiceStatus $status, ActivityKind $activity, ?string $productCategory): StructureChoice
    {
        return new StructureChoice($structure, $status, $structure->materialMultiplierFor($activity, $productCategory));
    }
}
