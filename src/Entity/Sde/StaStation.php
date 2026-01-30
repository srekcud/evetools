<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\StaStationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StaStationRepository::class)]
#[ORM\Table(name: 'sde_sta_stations')]
#[ORM\Index(columns: ['solar_system_id'])]
#[ORM\Index(columns: ['constellation_id'])]
#[ORM\Index(columns: ['region_id'])]
#[ORM\Index(columns: ['corporation_id'])]
class StaStation
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    private int $stationId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $stationName;

    #[ORM\ManyToOne(targetEntity: MapSolarSystem::class, inversedBy: 'stations')]
    #[ORM\JoinColumn(name: 'solar_system_id', referencedColumnName: 'solar_system_id', nullable: false)]
    private MapSolarSystem $solarSystem;

    #[ORM\Column(type: 'integer')]
    private int $constellationId;

    #[ORM\Column(type: 'integer')]
    private int $regionId;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $stationTypeId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $corporationId = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $x = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $y = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $z = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $security = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $dockingCostPerVolume = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $maxShipVolumeDockable = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $officeRentalCost = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $reprocessingEfficiency = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $reprocessingStationsTake = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $operationId = null;

    public function getStationId(): int
    {
        return $this->stationId;
    }

    public function setStationId(int $stationId): static
    {
        $this->stationId = $stationId;
        return $this;
    }

    public function getStationName(): string
    {
        return $this->stationName;
    }

    public function setStationName(string $stationName): static
    {
        $this->stationName = $stationName;
        return $this;
    }

    public function getSolarSystem(): MapSolarSystem
    {
        return $this->solarSystem;
    }

    public function setSolarSystem(MapSolarSystem $solarSystem): static
    {
        $this->solarSystem = $solarSystem;
        return $this;
    }

    public function getConstellationId(): int
    {
        return $this->constellationId;
    }

    public function setConstellationId(int $constellationId): static
    {
        $this->constellationId = $constellationId;
        return $this;
    }

    public function getRegionId(): int
    {
        return $this->regionId;
    }

    public function setRegionId(int $regionId): static
    {
        $this->regionId = $regionId;
        return $this;
    }

    public function getStationTypeId(): ?int
    {
        return $this->stationTypeId;
    }

    public function setStationTypeId(?int $stationTypeId): static
    {
        $this->stationTypeId = $stationTypeId;
        return $this;
    }

    public function getCorporationId(): ?int
    {
        return $this->corporationId;
    }

    public function setCorporationId(?int $corporationId): static
    {
        $this->corporationId = $corporationId;
        return $this;
    }

    public function getX(): ?float
    {
        return $this->x;
    }

    public function setX(?float $x): static
    {
        $this->x = $x;
        return $this;
    }

    public function getY(): ?float
    {
        return $this->y;
    }

    public function setY(?float $y): static
    {
        $this->y = $y;
        return $this;
    }

    public function getZ(): ?float
    {
        return $this->z;
    }

    public function setZ(?float $z): static
    {
        $this->z = $z;
        return $this;
    }

    public function getSecurity(): ?float
    {
        return $this->security;
    }

    public function setSecurity(?float $security): static
    {
        $this->security = $security;
        return $this;
    }

    public function getDockingCostPerVolume(): ?float
    {
        return $this->dockingCostPerVolume;
    }

    public function setDockingCostPerVolume(?float $dockingCostPerVolume): static
    {
        $this->dockingCostPerVolume = $dockingCostPerVolume;
        return $this;
    }

    public function getMaxShipVolumeDockable(): ?float
    {
        return $this->maxShipVolumeDockable;
    }

    public function setMaxShipVolumeDockable(?float $maxShipVolumeDockable): static
    {
        $this->maxShipVolumeDockable = $maxShipVolumeDockable;
        return $this;
    }

    public function getOfficeRentalCost(): ?int
    {
        return $this->officeRentalCost;
    }

    public function setOfficeRentalCost(?int $officeRentalCost): static
    {
        $this->officeRentalCost = $officeRentalCost;
        return $this;
    }

    public function getReprocessingEfficiency(): ?float
    {
        return $this->reprocessingEfficiency;
    }

    public function setReprocessingEfficiency(?float $reprocessingEfficiency): static
    {
        $this->reprocessingEfficiency = $reprocessingEfficiency;
        return $this;
    }

    public function getReprocessingStationsTake(): ?float
    {
        return $this->reprocessingStationsTake;
    }

    public function setReprocessingStationsTake(?float $reprocessingStationsTake): static
    {
        $this->reprocessingStationsTake = $reprocessingStationsTake;
        return $this;
    }

    public function getOperationId(): ?int
    {
        return $this->operationId;
    }

    public function setOperationId(?int $operationId): static
    {
        $this->operationId = $operationId;
        return $this;
    }
}
