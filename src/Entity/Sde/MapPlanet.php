<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\MapPlanetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MapPlanetRepository::class)]
#[ORM\Table(name: 'sde_map_planets')]
#[ORM\Index(columns: ['solar_system_id'])]
class MapPlanet
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $planetId;

    #[ORM\Column(type: 'integer')]
    private int $solarSystemId;

    #[ORM\Column(type: 'integer')]
    private int $celestialIndex;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    public function getPlanetId(): int
    {
        return $this->planetId;
    }

    public function setPlanetId(int $planetId): static
    {
        $this->planetId = $planetId;

        return $this;
    }

    public function getSolarSystemId(): int
    {
        return $this->solarSystemId;
    }

    public function setSolarSystemId(int $solarSystemId): static
    {
        $this->solarSystemId = $solarSystemId;

        return $this;
    }

    public function getCelestialIndex(): int
    {
        return $this->celestialIndex;
    }

    public function setCelestialIndex(int $celestialIndex): static
    {
        $this->celestialIndex = $celestialIndex;

        return $this;
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): static
    {
        $this->typeId = $typeId;

        return $this;
    }
}
