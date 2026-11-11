<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;

final readonly class PlannedJobTimeModifier
{
    public function __construct(private RigCategories $rigCategories)
    {
    }

    /**
     * @param list<ProductionStructure> $structures
     */
    public function of(?string $plannedStructureId, array $structures, int $productTypeId, ActivityKind $activity): ?Multiplier
    {
        if (null === $plannedStructureId) {
            return Multiplier::one();
        }

        $plannedStructure = array_find($structures, static fn (ProductionStructure $structure): bool => $structure->id === $plannedStructureId);
        if (null === $plannedStructure) {
            return null;
        }

        return $plannedStructure->jobTimeMultiplierFor($activity, $this->rigCategories->categoryOf($productTypeId, $activity));
    }
}
