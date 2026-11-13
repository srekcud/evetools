<?php

declare(strict_types=1);

namespace App\Service\Sde;

use App\Repository\Sde\MapMoonRepository;
use App\Repository\Sde\MapPlanetRepository;
use App\Repository\Sde\MapSolarSystemRepository;

class SdeCelestialNameResolver
{
    public function __construct(
        private readonly MapPlanetRepository $planetRepository,
        private readonly MapMoonRepository $moonRepository,
        private readonly MapSolarSystemRepository $solarSystemRepository,
    ) {
    }

    public function planetName(int $planetId): ?string
    {
        $planet = $this->planetRepository->find($planetId);
        if ($planet === null) {
            return null;
        }

        $solarSystem = $this->solarSystemRepository->findBySolarSystemId($planet->getSolarSystemId());
        if ($solarSystem === null) {
            return null;
        }

        return CelestialNameFormatter::planetName($solarSystem->getSolarSystemName(), $planet->getCelestialIndex());
    }

    public function moon(int $moonId): ?ResolvedMoon
    {
        $moon = $this->moonRepository->find($moonId);
        if ($moon === null) {
            return null;
        }

        $solarSystem = $this->solarSystemRepository->findBySolarSystemId($moon->getSolarSystemId());
        if ($solarSystem === null) {
            return null;
        }

        return new ResolvedMoon(
            CelestialNameFormatter::moonName($solarSystem->getSolarSystemName(), $moon->getCelestialIndex(), $moon->getOrbitIndex()),
            $moon->getSolarSystemId(),
        );
    }
}
