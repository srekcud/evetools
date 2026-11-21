<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Enum\IndustryActivityType;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;

final readonly class SdeChainRecipeIngredients implements ChainRecipeIngredients
{
    public function __construct(
        private IndustryActivityProductRepository $productRepository,
        private IndustryActivityMaterialRepository $materialRepository,
    ) {
    }

    public function producedIngredientsOf(array $productTypeIds): array
    {
        $requestedTypeIds = array_values(array_unique($productTypeIds));
        $hasProducerByTypeId = [];
        $materialTypeIdsByTypeId = [];

        $frontier = $requestedTypeIds;
        while ([] !== $frontier) {
            $producers = $this->productRepository->findProducersOf($frontier);
            foreach ($frontier as $typeId) {
                $hasProducerByTypeId[$typeId] = isset($producers[$typeId]);
            }
            if ([] === $producers) {
                break;
            }

            $materialsByBlueprintTypeId = $this->materialRepository->findMaterialsForBlueprints(
                array_values(array_unique(array_column($producers, 'blueprintTypeId'))),
                [IndustryActivityType::Manufacturing->value, IndustryActivityType::Reaction->value],
            );

            $discoveredTypeIds = [];
            foreach ($producers as $typeId => $producer) {
                $materialTypeIds = array_values(array_unique(array_column($materialsByBlueprintTypeId[$producer['blueprintTypeId']] ?? [], 'materialTypeId')));
                $materialTypeIdsByTypeId[$typeId] = $materialTypeIds;
                foreach ($materialTypeIds as $materialTypeId) {
                    if (!isset($hasProducerByTypeId[$materialTypeId])) {
                        $discoveredTypeIds[$materialTypeId] = $materialTypeId;
                    }
                }
            }
            $frontier = array_values($discoveredTypeIds);
        }

        $ingredientsByTypeId = [];
        foreach ($hasProducerByTypeId as $typeId => $hasProducer) {
            if (!$hasProducer && !\in_array($typeId, $requestedTypeIds, true)) {
                continue;
            }
            $ingredientsByTypeId[$typeId] = array_values(array_filter(
                $materialTypeIdsByTypeId[$typeId] ?? [],
                static fn (int $materialTypeId): bool => $hasProducerByTypeId[$materialTypeId] ?? false,
            ));
        }

        return $ingredientsByTypeId;
    }
}
