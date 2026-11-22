<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Constant\EveConstants;
use App\Entity\IndustryUserSettings;
use App\Industry\Application\CourierHub;

final class CourierHubSettings
{
    public static function resolve(?IndustryUserSettings $settings): CourierHub
    {
        return new CourierHub(
            $settings?->getCourierHubSolarSystemId() ?? EveConstants::DEFAULT_COURIER_HUB_SOLAR_SYSTEM_ID,
            $settings?->getExportCostPerM3() ?? EveConstants::DEFAULT_EXPORT_COST_PER_M3,
            $settings?->getJumpBridgeCostPerM3() ?? EveConstants::DEFAULT_JUMP_BRIDGE_COST_PER_M3,
        );
    }
}
