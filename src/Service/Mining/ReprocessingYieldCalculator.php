<?php

declare(strict_types=1);

namespace App\Service\Mining;

use App\Enum\ReprocessingLocation;
use App\Enum\ReprocessingRigTier;
use App\Enum\ReprocessingSecurity;

final readonly class ReprocessingYieldCalculator
{
    public function calculate(
        int $reprocessingLevel,
        int $reprocessingEfficiencyLevel,
        int $oreProcessingLevel,
        ReprocessingLocation $location,
        ?ReprocessingRigTier $rigTier,
        ?ReprocessingSecurity $security,
        int $implantPercent,
    ): float {
        return 0.0;
    }
}
