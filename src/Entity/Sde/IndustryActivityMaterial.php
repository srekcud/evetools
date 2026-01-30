<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\IndustryActivityMaterialRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IndustryActivityMaterialRepository::class)]
#[ORM\Table(name: 'sde_industry_activity_materials')]
#[ORM\Index(columns: ['material_type_id'], name: 'idx_material_type')]
class IndustryActivityMaterial
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $activityId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $materialTypeId;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): self
    {
        $this->typeId = $typeId;
        return $this;
    }

    public function getActivityId(): int
    {
        return $this->activityId;
    }

    public function setActivityId(int $activityId): self
    {
        $this->activityId = $activityId;
        return $this;
    }

    public function getMaterialTypeId(): int
    {
        return $this->materialTypeId;
    }

    public function setMaterialTypeId(int $materialTypeId): self
    {
        $this->materialTypeId = $materialTypeId;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }
}
