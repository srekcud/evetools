<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * How one product is built: a manufacturing blueprint or a reaction formula of the SDE.
 */
final readonly class Recipe
{
    /**
     * @param list<RecipeMaterial> $materials
     */
    public function __construct(
        public int $productTypeId,
        public ActivityKind $activity,
        public Quantity $outputPerRun,
        public array $materials,
        public Runs $maxProductionLimit,
        public int $baseTimeSeconds,
    ) {
    }
}
