<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\IndustryBlueprintRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IndustryBlueprintRepository::class)]
#[ORM\Table(name: 'sde_industry_blueprints')]
class IndustryBlueprint
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'integer')]
    private int $maxProductionLimit;

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): self
    {
        $this->typeId = $typeId;
        return $this;
    }

    public function getMaxProductionLimit(): int
    {
        return $this->maxProductionLimit;
    }

    public function setMaxProductionLimit(int $maxProductionLimit): self
    {
        $this->maxProductionLimit = $maxProductionLimit;
        return $this;
    }
}
