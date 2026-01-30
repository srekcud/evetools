<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\MapSolarSystemJumpRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MapSolarSystemJumpRepository::class)]
#[ORM\Table(name: 'sde_map_solar_system_jumps')]
#[ORM\Index(columns: ['from_solar_system_id'])]
#[ORM\Index(columns: ['to_solar_system_id'])]
class MapSolarSystemJump
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $fromSolarSystemId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $toSolarSystemId;

    #[ORM\Column(type: 'integer')]
    private int $fromRegionId;

    #[ORM\Column(type: 'integer')]
    private int $fromConstellationId;

    #[ORM\Column(type: 'integer')]
    private int $toRegionId;

    #[ORM\Column(type: 'integer')]
    private int $toConstellationId;

    public function getFromSolarSystemId(): int
    {
        return $this->fromSolarSystemId;
    }

    public function setFromSolarSystemId(int $fromSolarSystemId): static
    {
        $this->fromSolarSystemId = $fromSolarSystemId;
        return $this;
    }

    public function getToSolarSystemId(): int
    {
        return $this->toSolarSystemId;
    }

    public function setToSolarSystemId(int $toSolarSystemId): static
    {
        $this->toSolarSystemId = $toSolarSystemId;
        return $this;
    }

    public function getFromRegionId(): int
    {
        return $this->fromRegionId;
    }

    public function setFromRegionId(int $fromRegionId): static
    {
        $this->fromRegionId = $fromRegionId;
        return $this;
    }

    public function getFromConstellationId(): int
    {
        return $this->fromConstellationId;
    }

    public function setFromConstellationId(int $fromConstellationId): static
    {
        $this->fromConstellationId = $fromConstellationId;
        return $this;
    }

    public function getToRegionId(): int
    {
        return $this->toRegionId;
    }

    public function setToRegionId(int $toRegionId): static
    {
        $this->toRegionId = $toRegionId;
        return $this;
    }

    public function getToConstellationId(): int
    {
        return $this->toConstellationId;
    }

    public function setToConstellationId(int $toConstellationId): static
    {
        $this->toConstellationId = $toConstellationId;
        return $this;
    }
}
