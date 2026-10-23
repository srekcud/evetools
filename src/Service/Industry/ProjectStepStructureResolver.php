<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Constant\EveConstants;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStructureConfig;
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
        return $this->effectiveStructureOf($step)?->getSolarSystemId() ?? $this->favoriteSolarSystemOf($step);
    }

    public function facilityTaxPercentOf(IndustryProjectStep $step): float
    {
        $structure = $this->effectiveStructureOf($step);
        if ($structure === null) {
            return IndustryStructureConfig::NPC_STATION_FACILITY_TAX_PERCENT;
        }

        return $structure->getFacilityTaxPercent() ?? 0.0;
    }

    private function effectiveStructureOf(IndustryProjectStep $step): ?IndustryStructureConfig
    {
        return $step->getStructureConfig() ?? $this->calculationService->getStructureBonusForStep($step)['structure'];
    }

    private function favoriteSolarSystemOf(IndustryProjectStep $step): int
    {
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
