<?php

declare(strict_types=1);

namespace App\Service\Industry;

final readonly class JobInstallCostEstimator
{
    /**
     * @param list<array{materialTypeId: int, quantity: int}> $materials
     * @param array<int, float> $adjustedPrices
     *
     * @return array{cost: ?float, unknownReason: ?string, missingAdjustedPriceTypeIds: list<int>}
     */
    public function estimate(
        array $materials,
        array $adjustedPrices,
        ?float $costIndex,
        int $runs,
        string $activity,
        ?float $facilityTaxRate,
    ): array {
        throw new \LogicException('Not implemented yet.');
    }
}
