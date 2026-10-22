<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\User;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Application\StructureSelector;
use App\Industry\Domain\ActivityKind;

final readonly class FacilityTaxResolver
{
    private const int PERCENT_PER_FRACTION = 100;

    public function __construct(private UserProductionStructures $productionStructures)
    {
    }

    public function percentFor(?User $user, int $solarSystemId, ActivityKind $activity): float
    {
        $structures = null === $user ? [] : $this->productionStructures->configuredBy(self::idOf($user));
        $choice = (new StructureSelector($structures, new FavoriteSystems($solarSystemId, $solarSystemId)))->select($activity, null);
        $facilityTaxRate = null === $choice->structure ? ProductionCostCalculator::NPC_STATION_FACILITY_TAX_RATE : $choice->structure->facilityTaxRate;

        return $facilityTaxRate * self::PERCENT_PER_FRACTION;
    }

    private static function idOf(User $user): string
    {
        $userId = $user->getId() ?? throw new \LogicException('A user without id has no configured structure.');

        return $userId->toRfc4122();
    }
}
