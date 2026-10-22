<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\EivMaterial;
use App\Industry\Domain\EstimatedItemValue;
use App\Industry\Domain\InstallCost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;

final readonly class JobInstallCostEstimator
{
    private const PERCENT = 100.0;

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
        ?float $facilityTaxPercent,
    ): array {
        $activityKind = match ($activity) {
            'manufacturing' => ActivityKind::Manufacturing,
            'reaction' => ActivityKind::Reaction,
            'invention' => ActivityKind::Invention,
            default => throw new \InvalidArgumentException(sprintf('Unsupported install cost activity "%s".', $activity)),
        };
        $materialTypeIds = array_column($materials, 'materialTypeId');
        $missingAdjustedPriceTypeIds = array_unique(array_diff($materialTypeIds, array_keys($adjustedPrices)));
        sort($missingAdjustedPriceTypeIds);

        if ($costIndex === null) {
            return ['cost' => null, 'unknownReason' => EsiCostIndexService::UNKNOWN_REASON_MISSING_COST_INDEX, 'missingAdjustedPriceTypeIds' => $missingAdjustedPriceTypeIds];
        }

        if ($missingAdjustedPriceTypeIds !== []) {
            return ['cost' => null, 'unknownReason' => EsiCostIndexService::UNKNOWN_REASON_MISSING_ADJUSTED_PRICE, 'missingAdjustedPriceTypeIds' => $missingAdjustedPriceTypeIds];
        }

        $estimatedItemValue = EstimatedItemValue::of(
            new Runs($runs),
            array_map(
                static fn (array $material): EivMaterial => new EivMaterial(
                    $material['materialTypeId'],
                    new Quantity($material['quantity']),
                    new Isk($adjustedPrices[$material['materialTypeId']]),
                ),
                $materials,
            ),
        );
        $installCost = InstallCost::forJob(
            $activityKind,
            $estimatedItemValue,
            $costIndex,
            Multiplier::one(),
            ($facilityTaxPercent ?? 0.0) / self::PERCENT,
            false,
        );

        return [
            'cost' => $installCost->total->amount()->amount,
            'unknownReason' => null,
            'missingAdjustedPriceTypeIds' => [],
        ];
    }
}
