<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProjectStep;
use App\Repository\IndustryUserSettingsRepository;

final readonly class ProjectStepStructureResolver
{
    public function __construct(
        private IndustryUserSettingsRepository $userSettingsRepository,
        private IndustryCalculationService $calculationService,
    ) {
    }

    public function solarSystemOf(IndustryProjectStep $step): int
    {
        throw new \LogicException('Not implemented yet.');
    }

    public function facilityTaxPercentOf(IndustryProjectStep $step): float
    {
        throw new \LogicException('Not implemented yet.');
    }
}
