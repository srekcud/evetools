<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\TypeNameResolver;

class IndustryCalculationService
{
    public function __construct(
        private readonly TypeNameResolver $typeNameResolver,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly StaStationRepository $staStationRepository,
        private readonly CachedStructureRepository $cachedStructureRepository,
    ) {
    }

    public function resolveFacilityName(int $stationId): ?string
    {
        if ($stationId < 1_000_000_000) {
            $station = $this->staStationRepository->findByStationId($stationId);
            return $station?->getStationName();
        }

        $structure = $this->cachedStructureRepository->findByStructureId($stationId);
        if ($structure !== null) {
            return $structure->getName();
        }

        $config = $this->structureConfigRepository->findByLocationId($stationId);
        return $config?->getName();
    }

    public function resolveTypeName(int $typeId): ?string
    {
        return $this->typeNameResolver->findName($typeId);
    }
}
