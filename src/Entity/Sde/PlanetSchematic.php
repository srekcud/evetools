<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\PlanetSchematicRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanetSchematicRepository::class)]
#[ORM\Table(name: 'sde_planet_schematics')]
class PlanetSchematic
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $schematicId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $schematicName;

    #[ORM\Column(type: 'integer')]
    private int $cycleTime;

    /** @var Collection<int, PlanetSchematicType> */
    #[ORM\OneToMany(targetEntity: PlanetSchematicType::class, mappedBy: 'schematic')]
    private Collection $schematicTypes;

    public function __construct()
    {
        $this->schematicTypes = new ArrayCollection();
    }

    public function getSchematicId(): int
    {
        return $this->schematicId;
    }

    public function setSchematicId(int $schematicId): static
    {
        $this->schematicId = $schematicId;
        return $this;
    }

    public function getSchematicName(): string
    {
        return $this->schematicName;
    }

    public function setSchematicName(string $schematicName): static
    {
        $this->schematicName = $schematicName;
        return $this;
    }

    public function getCycleTime(): int
    {
        return $this->cycleTime;
    }

    public function setCycleTime(int $cycleTime): static
    {
        $this->cycleTime = $cycleTime;
        return $this;
    }

    /** @return Collection<int, PlanetSchematicType> */
    public function getSchematicTypes(): Collection
    {
        return $this->schematicTypes;
    }
}
