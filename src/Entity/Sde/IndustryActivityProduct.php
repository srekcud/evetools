<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\IndustryActivityProductRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IndustryActivityProductRepository::class)]
#[ORM\Table(name: 'sde_industry_activity_products')]
#[ORM\Index(columns: ['product_type_id'], name: 'idx_product_type')]
class IndustryActivityProduct
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $activityId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $productTypeId;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $probability = null;

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

    public function getProductTypeId(): int
    {
        return $this->productTypeId;
    }

    public function setProductTypeId(int $productTypeId): self
    {
        $this->productTypeId = $productTypeId;
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

    public function getProbability(): ?float
    {
        return $this->probability;
    }

    public function setProbability(?float $probability): self
    {
        $this->probability = $probability;
        return $this;
    }
}
