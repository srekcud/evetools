<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

interface ChainRecipeIngredients
{
    /**
     * @param list<int> $productTypeIds
     * @return array<int, list<int>> typeIds of the recipe materials that have a producer, by product typeId
     */
    public function producedIngredientsOf(array $productTypeIds): array;
}
