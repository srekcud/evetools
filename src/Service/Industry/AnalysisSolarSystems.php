<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Constant\EveConstants;
use App\Entity\IndustryUserSettings;

final readonly class AnalysisSolarSystems
{
    private function __construct(public int $manufacturingSolarSystemId, public int $reactionSolarSystemId)
    {
    }

    public static function resolve(?int $requestedSolarSystemId, ?IndustryUserSettings $settings): self
    {
        if ($requestedSolarSystemId !== null) {
            return new self($requestedSolarSystemId, $requestedSolarSystemId);
        }

        $manufacturingSolarSystemId = $settings?->getFavoriteManufacturingSystemId() ?? EveConstants::JITA_SOLAR_SYSTEM_ID;

        return new self($manufacturingSolarSystemId, $settings?->getFavoriteReactionSystemId() ?? $manufacturingSolarSystemId);
    }
}
