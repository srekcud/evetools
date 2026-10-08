<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Sde;

use App\Entity\Sde\IndustryActivityProduct;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\IndustryActivityRepository;
use App\Repository\Sde\IndustryBlueprintRepository;

/**
 * Recipes read from the SDE industry tables (ADR-0009, spec R1-R3).
 */
final readonly class SdeBlueprintCatalog implements BlueprintCatalog
{
    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ACTIVITY_REACTION = 11;

    public function __construct(
        private IndustryActivityProductRepository $activityProductRepository,
        private IndustryActivityRepository $activityRepository,
        private IndustryActivityMaterialRepository $activityMaterialRepository,
        private IndustryBlueprintRepository $blueprintRepository,
    ) {
    }

    /**
     * Manufacturing first, reaction as a fallback, as IndustryTreeService::findProducerFor().
     */
    public function recipeFor(int $productTypeId): ?Recipe
    {
        $manufacturing = $this->activityProductRepository->findBlueprintForProduct($productTypeId, self::ACTIVITY_MANUFACTURING);
        if (null !== $manufacturing) {
            return $this->recipeOf($manufacturing, ActivityKind::Manufacturing);
        }

        $reaction = $this->activityProductRepository->findBlueprintForProduct($productTypeId, self::ACTIVITY_REACTION);
        if (null !== $reaction) {
            return $this->recipeOf($reaction, ActivityKind::Reaction);
        }

        return null;
    }

    private function recipeOf(IndustryActivityProduct $producer, ActivityKind $activity): Recipe
    {
        $blueprintTypeId = $producer->getTypeId();
        $activityId = $producer->getActivityId();

        $blueprint = $this->blueprintRepository->find($blueprintTypeId);
        if (null === $blueprint) {
            throw new \UnexpectedValueException(\sprintf('Blueprint %d has no row in sde_industry_blueprints: its max production limit is unknown.', $blueprintTypeId));
        }

        $blueprintActivity = $this->activityRepository->findOneBy(['typeId' => $blueprintTypeId, 'activityId' => $activityId]);
        if (null === $blueprintActivity) {
            throw new \UnexpectedValueException(\sprintf('Blueprint %d has no row in sde_industry_activities for activity %d: its base time is unknown.', $blueprintTypeId, $activityId));
        }

        $materials = [];
        foreach ($this->activityMaterialRepository->findByBlueprintAndActivity($blueprintTypeId, $activityId) as $material) {
            $materials[] = new RecipeMaterial($material->getMaterialTypeId(), new Quantity($material->getQuantity()));
        }

        return new Recipe(
            $producer->getProductTypeId(),
            $activity,
            new Quantity($producer->getQuantity()),
            $materials,
            new Runs($blueprint->getMaxProductionLimit()),
            $blueprintActivity->getTime(),
        );
    }
}
