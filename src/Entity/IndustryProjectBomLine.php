<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryProjectBomLineRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryProjectBomLineRepository::class)]
#[ORM\Table(name: 'industry_project_bom_lines')]
#[ORM\Index(columns: ['project_id'])]
#[ORM\Index(columns: ['type_id'])]
class IndustryProjectBomLine
{
    public const string ACTIVITY_MANUFACTURING = 'manufacturing';
    public const string ACTIVITY_REACTION = 'reaction';
    private const string JOB_GROUP_COMPONENT = 'component';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: IndustryProject::class, inversedBy: 'bomItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProject $project;

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

    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $plannedStructureId = null;

    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $plannedStructureStatus = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $surplusQuantity = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $meLevel = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $teLevel = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $runs = null;

    /** @var Collection<int, IndustryProjectJobMatch> */
    #[ORM\OneToMany(targetEntity: IndustryProjectJobMatch::class, mappedBy: 'bomLine', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $jobMatches;

    /** @var Collection<int, IndustryProjectPurchase> */
    #[ORM\OneToMany(targetEntity: IndustryProjectPurchase::class, mappedBy: 'bomLine', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $purchases;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->jobMatches = new ArrayCollection();
        $this->purchases = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProject(): IndustryProject
    {
        return $this->project;
    }

    public function setProject(IndustryProject $project): static
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

    public function getPlannedStructureId(): ?string
    {
        return $this->plannedStructureId;
    }

    public function setPlannedStructureId(?string $plannedStructureId): static
    {
        $this->plannedStructureId = $plannedStructureId;
        return $this;
    }

    public function getPlannedStructureStatus(): ?string
    {
        return $this->plannedStructureStatus;
    }

    public function setPlannedStructureStatus(?string $plannedStructureStatus): static
    {
        $this->plannedStructureStatus = $plannedStructureStatus;
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

    public function getSurplusQuantity(): ?int
    {
        return $this->surplusQuantity;
    }

    public function setSurplusQuantity(?int $surplusQuantity): static
    {
        $this->surplusQuantity = $surplusQuantity;
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

    public function isCoveredByStock(): bool
    {
        return $this->isJob && 0 === $this->runs;
    }

    public function isPurchasable(): bool
    {
        return !$this->isJob || self::JOB_GROUP_COMPONENT === $this->jobGroup;
    }

    public function isManufacturingOrReactionJob(): bool
    {
        return $this->isJob && \in_array($this->activityType, [self::ACTIVITY_MANUFACTURING, self::ACTIVITY_REACTION], true);
    }

    /** @return Collection<int, IndustryProjectJobMatch> */
    public function getJobMatches(): Collection
    {
        return $this->jobMatches;
    }

    public function addJobMatch(IndustryProjectJobMatch $jobMatch): static
    {
        if (!$this->jobMatches->contains($jobMatch)) {
            $this->jobMatches->add($jobMatch);
            $jobMatch->setBomLine($this);
        }

        return $this;
    }

    public function getMatchedRuns(): int
    {
        return array_sum($this->jobMatches->map(static fn (IndustryProjectJobMatch $jobMatch): int => $jobMatch->getRuns())->toArray());
    }

    public function hasManualJobMatch(): bool
    {
        return $this->jobMatches->exists(static fn (int $key, IndustryProjectJobMatch $jobMatch): bool => $jobMatch->isManual());
    }

    /** @return Collection<int, IndustryProjectPurchase> */
    public function getPurchases(): Collection
    {
        return $this->purchases;
    }

    public function addPurchase(IndustryProjectPurchase $purchase): static
    {
        if (!$this->purchases->contains($purchase)) {
            $this->purchases->add($purchase);
            $purchase->setBomLine($this);
        }

        return $this;
    }

    public function getPurchasedQuantity(): int
    {
        $purchasedQuantity = 0;
        foreach ($this->purchases as $purchase) {
            if ($purchase->getTypeId() === $this->typeId) {
                $purchasedQuantity += $purchase->getQuantity();
            }
        }

        return $purchasedQuantity;
    }
}
