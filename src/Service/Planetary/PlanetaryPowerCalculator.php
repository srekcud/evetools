<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\PlanetaryColony;
use App\Repository\Sde\DgmTypeAttributeRepository;
use App\Repository\Sde\InvTypeRepository;
use Symfony\Contracts\Service\ResetInterface;

class PlanetaryPowerCalculator implements ResetInterface
{
    public function __construct(
        private readonly DgmTypeAttributeRepository $typeAttributeRepository,
        private readonly InvTypeRepository $invTypeRepository,
    ) {
    }

    public function reset(): void
    {
    }

    /**
     * @return array{cpu: int, powergrid: int}|null
     */
    public function outputs(PlanetaryColony $colony): ?array
    {
        return null;
    }

    /**
     * @return array{cpu: int, powergrid: int}|null
     */
    public function load(PlanetaryColony $colony): ?array
    {
        return null;
    }
}
