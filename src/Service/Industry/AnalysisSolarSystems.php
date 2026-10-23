<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryUserSettings;

final readonly class AnalysisSolarSystems
{
    private function __construct(public int $manufacturingSolarSystemId, public int $reactionSolarSystemId)
    {
    }

    public static function resolve(?int $requestedSolarSystemId, ?IndustryUserSettings $settings): self
    {
        throw new \LogicException('Not implemented yet.');
    }
}
