<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\DgmAttributeTypeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DgmAttributeTypeRepository::class)]
#[ORM\Table(name: 'sde_dgm_attribute_types')]
class DgmAttributeType
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $attributeId;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $attributeName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $iconId = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $defaultValue = null;

    #[ORM\Column(type: 'boolean')]
    private bool $published = false;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $unitId = null;

    #[ORM\Column(type: 'boolean')]
    private bool $stackable = false;

    #[ORM\Column(type: 'boolean')]
    private bool $highIsGood = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $categoryId = null;

    public function getAttributeId(): int
    {
        return $this->attributeId;
    }

    public function setAttributeId(int $attributeId): self
    {
        $this->attributeId = $attributeId;
        return $this;
    }

    public function getAttributeName(): ?string
    {
        return $this->attributeName;
    }

    public function setAttributeName(?string $attributeName): self
    {
        $this->attributeName = $attributeName;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getIconId(): ?int
    {
        return $this->iconId;
    }

    public function setIconId(?int $iconId): self
    {
        $this->iconId = $iconId;
        return $this;
    }

    public function getDefaultValue(): ?float
    {
        return $this->defaultValue;
    }

    public function setDefaultValue(?float $defaultValue): self
    {
        $this->defaultValue = $defaultValue;
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): self
    {
        $this->published = $published;
        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $this->displayName = $displayName;
        return $this;
    }

    public function getUnitId(): ?int
    {
        return $this->unitId;
    }

    public function setUnitId(?int $unitId): self
    {
        $this->unitId = $unitId;
        return $this;
    }

    public function isStackable(): bool
    {
        return $this->stackable;
    }

    public function setStackable(bool $stackable): self
    {
        $this->stackable = $stackable;
        return $this;
    }

    public function isHighIsGood(): bool
    {
        return $this->highIsGood;
    }

    public function setHighIsGood(bool $highIsGood): self
    {
        $this->highIsGood = $highIsGood;
        return $this;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }

    public function setCategoryId(?int $categoryId): self
    {
        $this->categoryId = $categoryId;
        return $this;
    }
}
