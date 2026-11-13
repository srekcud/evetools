<?php

declare(strict_types=1);

namespace App\Service\Sde;

use App\Entity\SdeBuildState;
use App\Enum\SdeBuildStatus;

final readonly class SdeBuildStatusResolver
{
    public function resolve(SdeBuildState $state): SdeBuildStatus
    {
        $latestBuildNumber = $state->getLatestBuildNumber();
        if ($latestBuildNumber === null) {
            return SdeBuildStatus::Unknown;
        }

        $importedBuildNumber = $state->getImportedBuildNumber();
        if ($importedBuildNumber === null) {
            return SdeBuildStatus::NotRecorded;
        }

        return $importedBuildNumber >= $latestBuildNumber ? SdeBuildStatus::UpToDate : SdeBuildStatus::Outdated;
    }
}
