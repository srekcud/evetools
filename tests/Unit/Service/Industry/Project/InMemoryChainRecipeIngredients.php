<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Service\Industry\Project\ChainRecipeIngredients;

final readonly class InMemoryChainRecipeIngredients implements ChainRecipeIngredients
{
    /**
     * @param array<int, list<int>> $producedIngredientsByTypeId
     */
    public function __construct(private array $producedIngredientsByTypeId)
    {
    }

    public function producedIngredientsOf(array $productTypeIds): array
    {
        $closure = [];
        $pending = $productTypeIds;
        while ([] !== $pending) {
            $typeId = array_shift($pending);
            if (isset($closure[$typeId])) {
                continue;
            }
            $closure[$typeId] = $this->producedIngredientsByTypeId[$typeId] ?? [];
            array_push($pending, ...$closure[$typeId]);
        }

        return $closure;
    }
}
