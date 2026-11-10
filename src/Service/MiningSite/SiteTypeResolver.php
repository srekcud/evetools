<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

final class SiteTypeResolver
{
    /**
     * @param list<array<string, mixed>> $scanTypes
     */
    public function dominantTypeIdOf(array $scanTypes): ?int
    {
        $dominantTypeId = null;
        $dominantUnits = null;
        foreach ($scanTypes as $scanType) {
            $typeId = (int) $scanType['typeId'];
            $units = (int) $scanType['units'];
            if ($dominantUnits === null || $units > $dominantUnits || ($units === $dominantUnits && $typeId < $dominantTypeId)) {
                $dominantTypeId = $typeId;
                $dominantUnits = $units;
            }
        }

        return $dominantTypeId;
    }
}
