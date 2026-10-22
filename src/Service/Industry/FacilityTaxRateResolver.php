<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\User;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Domain\ActivityKind;

final readonly class FacilityTaxRateResolver
{
    public function __construct(private UserProductionStructures $productionStructures)
    {
    }

    public function percentFor(?User $user, int $solarSystemId, ActivityKind $activity): float
    {
        throw new \LogicException('Not implemented yet.');
    }
}
