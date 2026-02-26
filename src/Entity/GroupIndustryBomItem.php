<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GroupIndustryBomItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GroupIndustryBomItemRepository::class)]
#[ORM\Table(name: 'group_industry_bom_items')]
#[ORM\Index(columns: ['project_id'])]
#[ORM\Index(columns: ['type_id'])]
class GroupIndustryBomItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: GroupIndustryProject::class, inversedBy: 'bomItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GroupIndustryProject $project;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $typeName;

    #[ORM\Column(type: 'integer')]
    private int $requiredQuantity;

    #[ORM\Column(type: 'integer')]
    private int $fulfilledQuantity = 0;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $estimatedPrice = null;

    /** false = raw material, true = job to launch */
    #[ORM\Column(type: 'boolean')]
    private bool $isJob = false;

    /** 'blueprint' / 'component' / 'final' (when isJob=true) */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $jobGroup = null;

    /** 'manufacturing' / 'reaction' / 'copy' / 'invention' */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $activityType = null;

    /** Which product this job/material is needed for */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $parentTypeId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $meLevel = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $teLevel = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $runs = null;

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

    public function getRequiredQuantity(): int
    {
        return $this->requiredQuantity;
    }

    public function setRequiredQuantity(int $requiredQuantity): static
    {
        $this->requiredQuantity = $requiredQuantity;
        return $this;
    }

    public function getFulfilledQuantity(): int
    {
        return $this->fulfilledQuantity;
    }

    public function setFulfilledQuantity(int $fulfilledQuantity): static
    {
        $this->fulfilledQuantity = $fulfilledQuantity;
        return $this;
    }

    public function getEstimatedPrice(): ?float
    {
        return $this->estimatedPrice;
    }

    public function setEstimatedPrice(?float $estimatedPrice): static
    {
        $this->estimatedPrice = $estimatedPrice;
        return $this;
    }

    public function isJob(): bool
    {
        return $this->isJob;
    }

    public function setIsJob(bool $isJob): static
    {
        $this->isJob = $isJob;
        return $this;
    }

    public function getJobGroup(): ?string
    {
        return $this->jobGroup;
    }

    public function setJobGroup(?string $jobGroup): static
    {
        $this->jobGroup = $jobGroup;
        return $this;
    }

    public function getActivityType(): ?string
    {
        return $this->activityType;
    }

    public function setActivityType(?string $activityType): static
    {
        $this->activityType = $activityType;
        return $this;
    }

    public function getParentTypeId(): ?int
    {
        return $this->parentTypeId;
    }

    public function setParentTypeId(?int $parentTypeId): static
    {
        $this->parentTypeId = $parentTypeId;
        return $this;
    }

    public function getMeLevel(): ?int
    {
        return $this->meLevel;
    }

    public function setMeLevel(?int $meLevel): static
    {
        $this->meLevel = $meLevel;
        return $this;
    }

    public function getTeLevel(): ?int
    {
        return $this->teLevel;
    }

    public function setTeLevel(?int $teLevel): static
    {
        $this->teLevel = $teLevel;
        return $this;
    }

    public function getRuns(): ?int
    {
        return $this->runs;
    }

    public function setRuns(?int $runs): static
    {
        $this->runs = $runs;
        return $this;
    }

    public function getRemainingQuantity(): int
    {
        return max(0, $this->requiredQuantity - $this->fulfilledQuantity);
    }

    public function getFulfillmentPercent(): float
    {
        if ($this->requiredQuantity <= 0) {
            return 100.0;
        }

        return min(100.0, ($this->fulfilledQuantity / $this->requiredQuantity) * 100);
    }

    public function isFulfilled(): bool
    {
        return $this->fulfilledQuantity >= $this->requiredQuantity;
    }
}
