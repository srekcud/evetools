<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\MapSolarSystemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MapSolarSystemRepository::class)]
#[ORM\Table(name: 'sde_map_solar_systems')]
#[ORM\Index(columns: ['constellation_id'])]
#[ORM\Index(columns: ['region_id'])]
#[ORM\Index(columns: ['security'])]
class MapSolarSystem
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $solarSystemId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $solarSystemName;

    #[ORM\ManyToOne(targetEntity: MapConstellation::class, inversedBy: 'solarSystems')]
    #[ORM\JoinColumn(name: 'constellation_id', referencedColumnName: 'constellation_id', nullable: false)]
    private MapConstellation $constellation;

    #[ORM\Column(type: 'integer')]
    private int $regionId;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $x = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $y = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $z = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $xMin = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $xMax = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $yMin = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $yMax = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $zMin = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $zMax = null;

    #[ORM\Column(type: 'float')]
    private float $security = 0.0;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $trueSecurityStatus = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $factionId = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $radius = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sunTypeId = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $securityClass = null;

    #[ORM\Column(type: 'boolean')]
    private bool $border = false;

    #[ORM\Column(type: 'boolean')]
    private bool $fringe = false;

    #[ORM\Column(type: 'boolean')]
    private bool $corridor = false;

    #[ORM\Column(type: 'boolean')]
    private bool $hub = false;

    #[ORM\Column(type: 'boolean')]
    private bool $international = false;

    #[ORM\Column(type: 'boolean')]
    private bool $regional = false;

    /** @var Collection<int, StaStation> */
    #[ORM\OneToMany(targetEntity: StaStation::class, mappedBy: 'solarSystem')]
    private Collection $stations;

    public function __construct()
    {
        $this->stations = new ArrayCollection();
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

    public function getSolarSystemName(): string
    {
        return $this->solarSystemName;
    }

    public function setSolarSystemName(string $solarSystemName): static
    {
        $this->solarSystemName = $solarSystemName;
        return $this;
    }

    public function getConstellation(): MapConstellation
    {
        return $this->constellation;
    }

    public function setConstellation(MapConstellation $constellation): static
    {
        $this->constellation = $constellation;
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

    public function getXMin(): ?float
    {
        return $this->xMin;
    }

    public function setXMin(?float $xMin): static
    {
        $this->xMin = $xMin;
        return $this;
    }

    public function getXMax(): ?float
    {
        return $this->xMax;
    }

    public function setXMax(?float $xMax): static
    {
        $this->xMax = $xMax;
        return $this;
    }

    public function getYMin(): ?float
    {
        return $this->yMin;
    }

    public function setYMin(?float $yMin): static
    {
        $this->yMin = $yMin;
        return $this;
    }

    public function getYMax(): ?float
    {
        return $this->yMax;
    }

    public function setYMax(?float $yMax): static
    {
        $this->yMax = $yMax;
        return $this;
    }

    public function getZMin(): ?float
    {
        return $this->zMin;
    }

    public function setZMin(?float $zMin): static
    {
        $this->zMin = $zMin;
        return $this;
    }

    public function getZMax(): ?float
    {
        return $this->zMax;
    }

    public function setZMax(?float $zMax): static
    {
        $this->zMax = $zMax;
        return $this;
    }

    public function getSecurity(): float
    {
        return $this->security;
    }

    public function setSecurity(float $security): static
    {
        $this->security = $security;
        return $this;
    }

    public function getTrueSecurityStatus(): ?float
    {
        return $this->trueSecurityStatus;
    }

    public function setTrueSecurityStatus(?float $trueSecurityStatus): static
    {
        $this->trueSecurityStatus = $trueSecurityStatus;
        return $this;
    }

    public function getFactionId(): ?int
    {
        return $this->factionId;
    }

    public function setFactionId(?int $factionId): static
    {
        $this->factionId = $factionId;
        return $this;
    }

    public function getRadius(): ?float
    {
        return $this->radius;
    }

    public function setRadius(?float $radius): static
    {
        $this->radius = $radius;
        return $this;
    }

    public function getSunTypeId(): ?int
    {
        return $this->sunTypeId;
    }

    public function setSunTypeId(?int $sunTypeId): static
    {
        $this->sunTypeId = $sunTypeId;
        return $this;
    }

    public function getSecurityClass(): ?string
    {
        return $this->securityClass;
    }

    public function setSecurityClass(?string $securityClass): static
    {
        $this->securityClass = $securityClass;
        return $this;
    }

    public function isBorder(): bool
    {
        return $this->border;
    }

    public function setBorder(bool $border): static
    {
        $this->border = $border;
        return $this;
    }

    public function isFringe(): bool
    {
        return $this->fringe;
    }

    public function setFringe(bool $fringe): static
    {
        $this->fringe = $fringe;
        return $this;
    }

    public function isCorridor(): bool
    {
        return $this->corridor;
    }

    public function setCorridor(bool $corridor): static
    {
        $this->corridor = $corridor;
        return $this;
    }

    public function isHub(): bool
    {
        return $this->hub;
    }

    public function setHub(bool $hub): static
    {
        $this->hub = $hub;
        return $this;
    }

    public function isInternational(): bool
    {
        return $this->international;
    }

    public function setInternational(bool $international): static
    {
        $this->international = $international;
        return $this;
    }

    public function isRegional(): bool
    {
        return $this->regional;
    }

    public function setRegional(bool $regional): static
    {
        $this->regional = $regional;
        return $this;
    }

    /** @return Collection<int, StaStation> */
    public function getStations(): Collection
    {
        return $this->stations;
    }
}
