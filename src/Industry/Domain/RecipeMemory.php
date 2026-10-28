<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Recipes read by one calculation: the catalog is asked once per product, a Leaf (no recipe) included.
 * Created at the start of a calculation and dropped at its end, never shared between two calculations.
 */
final class RecipeMemory implements BlueprintCatalog
{
    /** @var array<int, Recipe|null> by product typeId */
    private array $recipes = [];

    public function __construct(private readonly BlueprintCatalog $catalog)
    {
    }

    public function recipeFor(int $productTypeId): ?Recipe
    {
        if (!\array_key_exists($productTypeId, $this->recipes)) {
            $this->recipes[$productTypeId] = $this->catalog->recipeFor($productTypeId);
        }

        return $this->recipes[$productTypeId];
    }
}
