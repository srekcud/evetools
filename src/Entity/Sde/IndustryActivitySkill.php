<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\IndustryActivitySkillRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IndustryActivitySkillRepository::class)]
#[ORM\Table(name: 'sde_industry_activity_skills')]
#[ORM\Index(columns: ['skill_id'], name: 'idx_skill')]
class IndustryActivitySkill
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $activityId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $skillId;

    #[ORM\Column(type: 'integer')]
    private int $level;

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

    public function getSkillId(): int
    {
        return $this->skillId;
    }

    public function setSkillId(int $skillId): self
    {
        $this->skillId = $skillId;
        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): self
    {
        $this->level = $level;
        return $this;
    }
}
