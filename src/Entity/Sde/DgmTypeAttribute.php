<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\DgmTypeAttributeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DgmTypeAttributeRepository::class)]
#[ORM\Table(name: 'sde_dgm_type_attributes')]
#[ORM\Index(columns: ['attribute_id'], name: 'idx_attribute')]
class DgmTypeAttribute
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $attributeId;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $valueInt = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $valueFloat = null;

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): self
    {
        $this->typeId = $typeId;
        return $this;
    }

    public function getAttributeId(): int
    {
        return $this->attributeId;
    }

    public function setAttributeId(int $attributeId): self
    {
        $this->attributeId = $attributeId;
        return $this;
    }

    public function getValueInt(): ?int
    {
        return $this->valueInt;
    }

    public function setValueInt(?int $valueInt): self
    {
        $this->valueInt = $valueInt;
        return $this;
    }

    public function getValueFloat(): ?float
    {
        return $this->valueFloat;
    }

    public function setValueFloat(?float $valueFloat): self
    {
        $this->valueFloat = $valueFloat;
        return $this;
    }

    public function getValue(): float|int|null
    {
        return $this->valueInt ?? $this->valueFloat;
    }
}
