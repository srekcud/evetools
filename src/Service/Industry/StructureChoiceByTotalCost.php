<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\User;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\RigCategories;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\StructureJobBasis;
use App\Industry\Application\StructureSelectionPolicy;
use App\Industry\Application\StructureSelector;
use App\Industry\Application\StructureTotalCostComparator;
use App\Industry\Application\SystemCostIndices;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\Runs;
use App\Repository\IndustryUserSettingsRepository;

final readonly class StructureChoiceByTotalCost
{
    public function __construct(
        private UserProductionStructures $productionStructures,
        private IndustryUserSettingsRepository $settingsRepository,
        private SystemCostIndices $costIndices,
        private StructureTotalCostComparator $totalCostComparator,
        private RigCategories $rigCategories,
        private BlueprintCatalog $blueprints,
    ) {
    }

    public function structureIdChosenFor(User $user, int $productTypeId, ActivityKind $activity, StructureSelectionPolicy $policy, int $referenceRuns): ?string
    {
        $recipe = $this->blueprints->recipeFor($productTypeId);
        if (null === $recipe || $recipe->activity !== $activity) {
            return null;
        }

        $userId = $user->getId()?->toRfc4122() ?? throw new \LogicException('A user without id has no configured structure.');
        $settings = $this->settingsRepository->findOneBy(['user' => $user]);
        $favoriteSystems = null === $settings
            ? FavoriteSystems::none()
            : new FavoriteSystems($settings->getFavoriteManufacturingSystemId(), $settings->getFavoriteReactionSystemId());

        $choice = (new StructureSelector(
            $this->productionStructures->configuredBy($userId),
            $favoriteSystems,
            $this->costIndices,
            $this->totalCostComparator,
            $policy,
        ))->select($activity, $this->rigCategories->categoryOf($productTypeId, $activity), null, new StructureJobBasis($recipe, new Runs($referenceRuns)));

        return StructureChoiceStatus::BestByTotalCost === $choice->status ? $choice->structure?->id : null;
    }
}
