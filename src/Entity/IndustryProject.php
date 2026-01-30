<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryProjectRepository::class)]
#[ORM\Table(name: 'industry_projects')]
#[ORM\Index(columns: ['user_id'])]
#[ORM\Index(columns: ['status'])]
class IndustryProject
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'integer')]
    private int $productTypeId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $productTypeName;

    #[ORM\Column(type: 'integer')]
    private int $runs;

    #[ORM\Column(type: 'integer')]
    private int $meLevel = 0;

    /** @var float Maximum job duration in days before splitting (default 2.0 days) */
    #[ORM\Column(type: 'float')]
    private float $maxJobDurationDays = 2.0;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = 'active';

    #[ORM\Column(type: 'boolean')]
    private bool $personalUse = false;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $bpoCost = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $materialCost = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $transportCost = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $taxAmount = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $sellPrice = null;

    /** @var int[] Type IDs to exclude from building (treated as raw materials) */
    #[ORM\Column(type: 'json')]
    private array $excludedTypeIds = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var \DateTimeImmutable|null Custom start date for job matching (defaults to createdAt) */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $jobsStartDate = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, IndustryProjectStep> */
    #[ORM\OneToMany(targetEntity: IndustryProjectStep::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $steps;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->steps = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getProductTypeId(): int
    {
        return $this->productTypeId;
    }

    public function setProductTypeId(int $productTypeId): static
    {
        $this->productTypeId = $productTypeId;
        return $this;
    }

    public function getProductTypeName(): string
    {
        return $this->productTypeName;
    }

    public function setProductTypeName(string $productTypeName): static
    {
        $this->productTypeName = $productTypeName;
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

    public function getMeLevel(): int
    {
        return $this->meLevel;
    }

    public function setMeLevel(int $meLevel): static
    {
        $this->meLevel = $meLevel;
        return $this;
    }

    public function getMaxJobDurationDays(): float
    {
        return $this->maxJobDurationDays;
    }

    public function setMaxJobDurationDays(float $maxJobDurationDays): static
    {
        $this->maxJobDurationDays = $maxJobDurationDays;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isPersonalUse(): bool
    {
        return $this->personalUse;
    }

    public function setPersonalUse(bool $personalUse): static
    {
        $this->personalUse = $personalUse;
        return $this;
    }

    public function getBpoCost(): ?float
    {
        return $this->bpoCost;
    }

    public function setBpoCost(?float $bpoCost): static
    {
        $this->bpoCost = $bpoCost;
        return $this;
    }

    public function getMaterialCost(): ?float
    {
        return $this->materialCost;
    }

    public function setMaterialCost(?float $materialCost): static
    {
        $this->materialCost = $materialCost;
        return $this;
    }

    public function getTransportCost(): ?float
    {
        return $this->transportCost;
    }

    public function setTransportCost(?float $transportCost): static
    {
        $this->transportCost = $transportCost;
        return $this;
    }

    public function getTaxAmount(): ?float
    {
        return $this->taxAmount;
    }

    public function setTaxAmount(?float $taxAmount): static
    {
        $this->taxAmount = $taxAmount;
        return $this;
    }

    public function getSellPrice(): ?float
    {
        return $this->sellPrice;
    }

    public function setSellPrice(?float $sellPrice): static
    {
        $this->sellPrice = $sellPrice;
        return $this;
    }

    /** @return int[] */
    public function getExcludedTypeIds(): array
    {
        return $this->excludedTypeIds;
    }

    /** @param int[] $excludedTypeIds */
    public function setExcludedTypeIds(array $excludedTypeIds): static
    {
        $this->excludedTypeIds = $excludedTypeIds;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getJobsStartDate(): ?\DateTimeImmutable
    {
        return $this->jobsStartDate;
    }

    public function setJobsStartDate(?\DateTimeImmutable $jobsStartDate): static
    {
        $this->jobsStartDate = $jobsStartDate;
        return $this;
    }

    /**
     * Get the effective start date for job matching.
     * Uses jobsStartDate if set, otherwise createdAt.
     */
    public function getEffectiveJobsStartDate(): \DateTimeImmutable
    {
        return $this->jobsStartDate ?? $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    /**
     * @return Collection<int, IndustryProjectStep>
     */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    public function addStep(IndustryProjectStep $step): static
    {
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
            $step->setProject($this);
        }
        return $this;
    }

    public function getJobsCost(): float
    {
        $total = 0.0;
        foreach ($this->steps as $step) {
            if ($step->getEsiJobCost() !== null) {
                $total += $step->getEsiJobCost();
            }
        }
        return $total;
    }

    public function getTotalCost(): float
    {
        return ($this->bpoCost ?? 0)
            + ($this->materialCost ?? 0)
            + ($this->transportCost ?? 0)
            + $this->getJobsCost()
            + ($this->taxAmount ?? 0);
    }

    public function getProfit(): ?float
    {
        if ($this->sellPrice === null) {
            return null;
        }
        return $this->sellPrice - $this->getTotalCost();
    }

    public function getProfitPercent(): ?float
    {
        $totalCost = $this->getTotalCost();
        $profit = $this->getProfit();
        if ($profit === null || $totalCost <= 0) {
            return null;
        }
        return ($profit / $totalCost) * 100;
    }
}
