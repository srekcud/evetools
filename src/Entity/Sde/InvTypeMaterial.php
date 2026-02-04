<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\InvTypeMaterialRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Materials obtained when reprocessing an item (ore -> minerals).
 */
#[ORM\Entity(repositoryClass: InvTypeMaterialRepository::class)]
#[ORM\Table(name: 'sde_inv_type_materials')]
#[ORM\Index(columns: ['type_id'])]
#[ORM\Index(columns: ['material_type_id'])]
class InvTypeMaterial
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $materialTypeId;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): static
    {
        $this->typeId = $typeId;
        return $this;
    }

    public function getMaterialTypeId(): int
    {
        return $this->materialTypeId;
    }

    public function setMaterialTypeId(int $materialTypeId): static
    {
        $this->materialTypeId = $materialTypeId;
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
