<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain\Double;

use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\Recipe;

final class RecipeReadCountingCatalog implements BlueprintCatalog
{
    private array $readsByTypeId = [];

    public function __construct(private readonly BlueprintCatalog $catalog)
    {
    }

    public function recipeFor(int $productTypeId): ?Recipe
    {
        $this->readsByTypeId[$productTypeId] = ($this->readsByTypeId[$productTypeId] ?? 0) + 1;

        return $this->catalog->recipeFor($productTypeId);
    }

    public function readsByTypeId(): array
    {
        $readsByTypeId = $this->readsByTypeId;
        ksort($readsByTypeId);

        return $readsByTypeId;
    }

    public function forgetReads(): void
    {
        $this->readsByTypeId = [];
    }
}
