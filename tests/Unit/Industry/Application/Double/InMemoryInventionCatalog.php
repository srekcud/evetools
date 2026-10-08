<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\InventionCatalog;
use App\Industry\Application\InventionRecipe;

/**
 * Invention data of the SDE by T2 product typeId. A product absent from it is not invented (T1, reaction...).
 */
final readonly class InMemoryInventionCatalog implements InventionCatalog
{
    /**
     * @param array<int, InventionRecipe> $inventionsByT2Product
     */
    public function __construct(private array $inventionsByT2Product = [])
    {
    }

    public function inventionOf(int $t2ProductTypeId): ?InventionRecipe
    {
        return $this->inventionsByT2Product[$t2ProductTypeId] ?? null;
    }
}
