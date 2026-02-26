<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GroupIndustryProjectItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GroupIndustryProjectItemRepository::class)]
#[ORM\Table(name: 'group_industry_project_items')]
#[ORM\Index(columns: ['project_id'])]
class GroupIndustryProjectItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: GroupIndustryProject::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GroupIndustryProject $project;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $typeName;

    #[ORM\Column(type: 'integer')]
    private int $meLevel = 0;

    #[ORM\Column(type: 'integer')]
    private int $teLevel = 0;

    #[ORM\Column(type: 'integer')]
    private int $runs = 1;

    #[ORM\Column(type: 'integer')]
    private int $sortOrder = 0;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getProject(): GroupIndustryProject
    {
        return $this->project;
    }

    public function setProject(GroupIndustryProject $project): static
    {
        $this->project = $project;
        return $this;
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

    public function getTypeName(): string
    {
        return $this->typeName;
    }

    public function setTypeName(string $typeName): static
    {
        $this->typeName = $typeName;
        return $this;
    }

    public function getMeLevel(): int
    {
        return $this->meLevel;
    }

    public function setMeLevel(int $meLevel): static
    {
        $this->meLevel = $meLevel;
        return $this;
    }

    public function getTeLevel(): int
    {
        return $this->teLevel;
    }

    public function setTeLevel(int $teLevel): static
    {
        $this->teLevel = $teLevel;
        return $this;
    }

    public function getRuns(): int
    {
        return $this->runs;
    }

    public function setRuns(int $runs): static
    {
        $this->runs = $runs;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }
}
