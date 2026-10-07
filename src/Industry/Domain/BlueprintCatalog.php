<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Production recipes of the SDE, by product. Implemented outside the Domain.
 */
interface BlueprintCatalog
{
    /**
     * Null when nothing builds the product: it can only be bought (a Leaf).
     */
    public function recipeFor(int $productTypeId): ?Recipe;
}
