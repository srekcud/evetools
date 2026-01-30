<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\DgmTypeEffectRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DgmTypeEffectRepository::class)]
#[ORM\Table(name: 'sde_dgm_type_effects')]
#[ORM\Index(columns: ['effect_id'], name: 'idx_effect')]
class DgmTypeEffect
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $effectId;

    #[ORM\Column(type: 'boolean')]
    private bool $isDefault = false;

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): self
    {
        $this->typeId = $typeId;
        return $this;
    }

    public function getEffectId(): int
    {
        return $this->effectId;
    }

    public function setEffectId(int $effectId): self
    {
        $this->effectId = $effectId;
        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;
        return $this;
    }
}
