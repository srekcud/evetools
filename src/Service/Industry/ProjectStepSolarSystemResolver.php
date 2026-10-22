<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProjectStep;
use App\Repository\IndustryUserSettingsRepository;

final readonly class ProjectStepSolarSystemResolver
{
    public function __construct(private IndustryUserSettingsRepository $userSettingsRepository)
    {
    }

    public function forStep(IndustryProjectStep $step): int
    {
        throw new \LogicException('Not implemented yet.');
    }
}
