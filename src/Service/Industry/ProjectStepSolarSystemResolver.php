<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Constant\EveConstants;
use App\Entity\IndustryProjectStep;
use App\Repository\IndustryUserSettingsRepository;

final readonly class ProjectStepSolarSystemResolver
{
    public function __construct(private IndustryUserSettingsRepository $userSettingsRepository)
    {
    }

    public function forStep(IndustryProjectStep $step): int
    {
        $structureSolarSystemId = $step->getStructureConfig()?->getSolarSystemId();
        if ($structureSolarSystemId !== null) {
            return $structureSolarSystemId;
        }

        $settings = $this->userSettingsRepository->findOneBy(['user' => $step->getProject()->getUser()]);
        if ($settings === null) {
            return EveConstants::JITA_SOLAR_SYSTEM_ID;
        }

        $favoriteSolarSystemId = $step->getActivityType() === 'reaction'
            ? $settings->getFavoriteReactionSystemId()
            : $settings->getFavoriteManufacturingSystemId();

        return $favoriteSolarSystemId ?? EveConstants::JITA_SOLAR_SYSTEM_ID;
    }
}
