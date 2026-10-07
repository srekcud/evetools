<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * EIV(activity, runs) = runs × Σ (base quantity × adjusted price) (spec R5).
 */
final readonly class EstimatedItemValue
{
    /**
     * @param list<EivMaterial> $materials materials of the job activity; for copying and invention, the manufacturing
     *                                     materials of the copied or invented blueprint
     */
    public static function of(Runs $runs, array $materials): Cost
    {
        $valuePerRun = 0.0;
        $missingAdjustedPrices = [];
        foreach ($materials as $material) {
            if (null === $material->adjustedPrice) {
                $missingAdjustedPrices[] = MissingData::adjustedPrice($material->typeId);

                continue;
            }
            $valuePerRun += $material->baseQuantityPerRun->value * $material->adjustedPrice->amount;
        }

        if ([] !== $missingAdjustedPrices) {
            return Cost::unknown(...$missingAdjustedPrices);
        }

        return Cost::known(new Isk($runs->value * $valuePerRun));
    }
}
