<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;

/**
 * ESI cost indices of the solar systems (spec R6). Implemented outside the Application.
 */
interface SystemCostIndices
{
    /**
     * Null when the ESI has no index for the system and activity.
     */
    public function costIndexOf(int $solarSystemId, ActivityKind $activity): ?float;
}
