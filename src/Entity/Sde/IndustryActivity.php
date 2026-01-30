<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\IndustryActivityRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IndustryActivityRepository::class)]
#[ORM\Table(name: 'sde_industry_activities')]
class IndustryActivity
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $activityId;

    #[ORM\Column(type: 'integer')]
    private int $time;

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

    public function getTime(): int
    {
        return $this->time;
    }

    public function setTime(int $time): self
    {
        $this->time = $time;
        return $this;
    }
}
