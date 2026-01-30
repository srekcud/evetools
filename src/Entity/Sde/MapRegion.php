<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\MapRegionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MapRegionRepository::class)]
#[ORM\Table(name: 'sde_map_regions')]
class MapRegion
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $regionId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $regionName;

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

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $factionId = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $radius = null;

    /** @var Collection<int, MapConstellation> */
    #[ORM\OneToMany(targetEntity: MapConstellation::class, mappedBy: 'region')]
    private Collection $constellations;

    public function __construct()
    {
        $this->constellations = new ArrayCollection();
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

    public function getRegionName(): string
    {
        return $this->regionName;
    }

    public function setRegionName(string $regionName): static
    {
        $this->regionName = $regionName;
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

    /** @return Collection<int, MapConstellation> */
    public function getConstellations(): Collection
    {
        return $this->constellations;
    }
}
