<?php

declare(strict_types=1);

namespace App\Service\Industry;

final class MaterialQuantityFormula
{
    public static function forJob(int $baseQuantity, int $runs, int $meLevel, float $structureBaseBonus, float $rigBonus): int
    {
        $meMultiplier = (100 - $meLevel) / 100;
        $structureMultiplier = $structureBaseBonus > 0 ? (1 - $structureBaseBonus / 100) : 1.0;
        $rigMultiplier = $rigBonus > 0 ? (1 - $rigBonus / 100) : 1.0;

        return max(
            $runs,
            (int) ceil(round($baseQuantity * $runs * $meMultiplier * $structureMultiplier * $rigMultiplier, 2))
        );
    }
}
