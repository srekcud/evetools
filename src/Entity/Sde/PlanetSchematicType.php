<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\PlanetSchematicTypeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanetSchematicTypeRepository::class)]
#[ORM\Table(name: 'sde_planet_schematic_types')]
#[ORM\Index(columns: ['type_id'])]
class PlanetSchematicType
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: PlanetSchematic::class, inversedBy: 'schematicTypes')]
    #[ORM\JoinColumn(name: 'schematic_id', referencedColumnName: 'schematic_id', nullable: false)]
    private PlanetSchematic $schematic;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'boolean')]
    private bool $isInput;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    public function getSchematic(): PlanetSchematic
    {
        return $this->schematic;
    }

    public function setSchematic(PlanetSchematic $schematic): static
    {
        $this->schematic = $schematic;
        return $this;
    }

    public function getSchematicId(): int
    {
        return $this->schematic->getSchematicId();
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

    public function isInput(): bool
    {
        return $this->isInput;
    }

    public function setIsInput(bool $isInput): static
    {
        $this->isInput = $isInput;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }
}
