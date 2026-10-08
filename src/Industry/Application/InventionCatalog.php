<?php

declare(strict_types=1);

namespace App\Industry\Application;

/**
 * Invention data of the SDE, by T2 product (spec R8). Implemented outside the Application.
 */
interface InventionCatalog
{
    /**
     * Null when the product is not invented (T1, reaction...).
     */
    public function inventionOf(int $t2ProductTypeId): ?InventionRecipe;
}
