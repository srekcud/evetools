<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\User;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureSelector;
use App\Industry\Application\SystemCostIndices;
use App\Industry\Domain\ActivityKind;

final readonly class FacilityTaxResolver
{
    private const int PERCENT_PER_FRACTION = 100;

    public function __construct(private UserProductionStructures $productionStructures, private SystemCostIndices $costIndices)
    {
    }

    public function percentFor(?User $user, int $solarSystemId, ActivityKind $activity): float
    {
        return self::percentOf($this->structureFor($user, $solarSystemId, $activity));
    }

    public function structureFor(?User $user, int $solarSystemId, ActivityKind $activity): ?ProductionStructure
    {
        $structures = null === $user ? [] : $this->productionStructures->configuredBy(self::idOf($user));

        return (new StructureSelector($structures, new FavoriteSystems($solarSystemId, $solarSystemId), $this->costIndices))->select($activity, null)->structure;
    }

    public static function percentOf(?ProductionStructure $structure): float
    {
        $facilityTaxRate = null === $structure ? ProductionCostCalculator::NPC_STATION_FACILITY_TAX_RATE : $structure->facilityTaxRate;

        return $facilityTaxRate * self::PERCENT_PER_FRACTION;
    }

    private static function idOf(User $user): string
    {
        $userId = $user->getId() ?? throw new \LogicException('A user without id has no configured structure.');

        return $userId->toRfc4122();
    }
}
