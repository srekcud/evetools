<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\MapMoonRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MapMoonRepository::class)]
#[ORM\Table(name: 'sde_map_moons')]
#[ORM\Index(columns: ['solar_system_id'])]
class MapMoon
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $moonId;

    #[ORM\Column(type: 'integer')]
    private int $solarSystemId;

    #[ORM\Column(type: 'integer')]
    private int $orbitId;

    #[ORM\Column(type: 'integer')]
    private int $celestialIndex;

    #[ORM\Column(type: 'integer')]
    private int $orbitIndex;

    public function getMoonId(): int
    {
        return $this->moonId;
    }

    public function setMoonId(int $moonId): static
    {
        $this->moonId = $moonId;

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

    public function getOrbitId(): int
    {
        return $this->orbitId;
    }

    public function setOrbitId(int $orbitId): static
    {
        $this->orbitId = $orbitId;

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

    public function getOrbitIndex(): int
    {
        return $this->orbitIndex;
    }

    public function setOrbitIndex(int $orbitIndex): static
    {
        $this->orbitIndex = $orbitIndex;

        return $this;
    }
}
