<?php

declare(strict_types=1);

namespace App\Service\Mining;

use App\Enum\ReprocessingLocation;
use App\Enum\ReprocessingRigTier;
use App\Enum\ReprocessingSecurity;

final readonly class ReprocessingYieldCalculator
{
    private const float NPC_STATION_BASE_YIELD = 0.50;
    private const int STRUCTURE_BASE_PERCENT = 50;
    private const float REPROCESSING_BONUS_PER_LEVEL = 0.03;
    private const float REPROCESSING_EFFICIENCY_BONUS_PER_LEVEL = 0.02;
    private const float ORE_PROCESSING_BONUS_PER_LEVEL = 0.02;

    public function calculate(
        int $reprocessingLevel,
        int $reprocessingEfficiencyLevel,
        int $oreProcessingLevel,
        ReprocessingLocation $location,
        ?ReprocessingRigTier $rigTier,
        ?ReprocessingSecurity $security,
        int $implantPercent,
    ): float {
        $skillFactor = (1 + self::REPROCESSING_BONUS_PER_LEVEL * $reprocessingLevel)
            * (1 + self::REPROCESSING_EFFICIENCY_BONUS_PER_LEVEL * $reprocessingEfficiencyLevel)
            * (1 + self::ORE_PROCESSING_BONUS_PER_LEVEL * $oreProcessingLevel);
        $implantFactor = 1 + $implantPercent / 100;

        return $this->baseYield($location, $rigTier, $security) * $skillFactor * $implantFactor;
    }

    private function baseYield(ReprocessingLocation $location, ?ReprocessingRigTier $rigTier, ?ReprocessingSecurity $security): float
    {
        if (!$location->isStructure()) {
            return self::NPC_STATION_BASE_YIELD;
        }

        if ($rigTier === null || $security === null) {
            throw new \InvalidArgumentException('A structure needs a rig tier and a security');
        }

        return (self::STRUCTURE_BASE_PERCENT + $rigTier->basePercentBonus()) / 100
            * (1 + $security->bonus())
            * (1 + $location->structureBonus());
    }
}
