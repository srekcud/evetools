<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

use App\Entity\IndustryProjectPurchase;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\StaStationRepository;

final readonly class PurchaseOriginResolver
{
    private const int FIRST_STRUCTURE_ID = 1_000_000_000;

    public function __construct(
        private StaStationRepository $stationRepository,
        private CachedStructureRepository $cachedStructureRepository,
        private IndustryStructureConfigRepository $structureConfigRepository,
    ) {
    }

    public function solarSystemIdOf(IndustryProjectPurchase $purchase): ?int
    {
        $locationId = $purchase->getTransaction()?->getLocationId();
        if (null === $locationId) {
            return null;
        }

        return $this->solarSystemIdOfLocation($locationId);
    }

    public function solarSystemIdOfLocation(int $locationId): ?int
    {
        if ($locationId < self::FIRST_STRUCTURE_ID) {
            return $this->stationRepository->findByStationId($locationId)?->getSolarSystem()->getSolarSystemId();
        }

        return $this->cachedStructureRepository->findByStructureId($locationId)?->getSolarSystemId()
            ?? $this->structureConfigRepository->findByLocationId($locationId)?->getSolarSystemId();
    }
}
