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
    /**
     * @param list<ProductionStructure> $structures
     */
    public function __construct(private array $structures, private FavoriteSystems $favoriteSystems)
    {
    }

    public function select(ActivityKind $activity, ?string $productCategory, ?ProductionStructure $assignedStructure = null): StructureChoice
    {
        if (null !== $assignedStructure) {
            return new StructureChoice(
                $assignedStructure,
                StructureChoiceStatus::Assigned,
                $assignedStructure->materialMultiplierFor($activity, $productCategory),
            );
        }

        $suitable = array_filter(
            $this->structures,
            static fn (ProductionStructure $structure): bool => $structure->canRun($activity),
        );

        $favoriteSystemId = $this->favoriteSystems->forActivity($activity);
        if (null === $favoriteSystemId) {
            return self::best($suitable, $activity, $productCategory, StructureChoiceStatus::BestConfigured);
        }

        $inFavoriteSystem = array_filter(
            $suitable,
            static fn (ProductionStructure $structure): bool => $favoriteSystemId === $structure->solarSystemId,
        );
        if ([] !== $inFavoriteSystem) {
            return self::best($inFavoriteSystem, $activity, $productCategory, StructureChoiceStatus::BestInFavoriteSystem);
        }

        return self::best($suitable, $activity, $productCategory, StructureChoiceStatus::BestOutsideFavoriteSystem);
    }

    /**
     * Smallest material multiplier first; equal multiplier: most recently configured first.
     *
     * @param array<ProductionStructure> $candidates
     */
    private static function best(array $candidates, ActivityKind $activity, ?string $productCategory, StructureChoiceStatus $status): StructureChoice
    {
        if ([] === $candidates) {
            return StructureChoice::notConfigured();
        }

        usort(
            $candidates,
            static fn (ProductionStructure $left, ProductionStructure $right): int => [$left->materialMultiplierFor($activity, $productCategory)->value, $right->configuredAt]
                <=> [$right->materialMultiplierFor($activity, $productCategory)->value, $left->configuredAt],
        );

        return new StructureChoice($candidates[0], $status, $candidates[0]->materialMultiplierFor($activity, $productCategory));
    }
}
