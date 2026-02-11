<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryProjectStepRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryProjectStepRepository::class)]
#[ORM\Table(name: 'industry_project_steps')]
#[ORM\Index(columns: ['project_id'])]
#[ORM\Index(columns: ['blueprint_type_id'])]
class IndustryProjectStep
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: IndustryProject::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProject $project;

    #[ORM\Column(type: 'integer')]
    private int $blueprintTypeId;

    #[ORM\Column(type: 'integer')]
    private int $productTypeId;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'integer')]
    private int $runs;

    #[ORM\Column(type: 'integer')]
    private int $depth;

    #[ORM\Column(type: 'integer')]
    private int $meLevel = 10;

    #[ORM\Column(type: 'integer')]
    private int $teLevel = 20;

    #[ORM\Column(type: 'string', length: 30)]
    private string $activityType = 'manufacturing';

    #[ORM\Column(type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $purchased = false;

    #[ORM\Column(type: 'integer')]
    private int $inStockQuantity = 0;

    /** 'auto' | 'manual' | 'none' */
    #[ORM\Column(type: 'string', length: 20)]
    private string $jobMatchMode = 'auto';

    #[ORM\ManyToOne(targetEntity: IndustryStructureConfig::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?IndustryStructureConfig $structureConfig = null;

    /** @var string|null UUID to group related splits together */
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $splitGroupId = null;

    /** @var int Index within the split group (0 for first/only, 1, 2, etc.) */
    #[ORM\Column(type: 'integer')]
    private int $splitIndex = 0;

    /** @var int|null Total runs for the entire split group (set on all splits) */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $totalGroupRuns = null;

    /** @var Collection<int, IndustryStepJobMatch> */
    #[ORM\OneToMany(targetEntity: IndustryStepJobMatch::class, mappedBy: 'step', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $jobMatches;

    /** @var Collection<int, IndustryStepPurchase> */
    #[ORM\OneToMany(targetEntity: IndustryStepPurchase::class, mappedBy: 'step', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $purchases;

    public function __construct()
    {
        $this->jobMatches = new ArrayCollection();
        $this->purchases = new ArrayCollection();
    }

    public function getId(): ?Uuid
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

    public function getBlueprintTypeId(): int
    {
        return $this->blueprintTypeId;
    }

    public function setBlueprintTypeId(int $blueprintTypeId): static
    {
        $this->blueprintTypeId = $blueprintTypeId;
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

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;
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

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function setDepth(int $depth): static
    {
        $this->depth = $depth;
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

    public function getActivityType(): string
    {
        return $this->activityType;
    }

    public function setActivityType(string $activityType): static
    {
        $this->activityType = $activityType;
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

    public function isPurchased(): bool
    {
        return $this->purchased;
    }

    public function setPurchased(bool $purchased): static
    {
        $this->purchased = $purchased;
        return $this;
    }

    public function getInStockQuantity(): int
    {
        return $this->inStockQuantity;
    }

    public function setInStockQuantity(int $inStockQuantity): static
    {
        $this->inStockQuantity = max(0, $inStockQuantity);
        return $this;
    }

    public function isInStock(): bool
    {
        return $this->inStockQuantity >= $this->quantity;
    }

    public function getMissingQuantity(): int
    {
        return max(0, $this->quantity - $this->inStockQuantity);
    }

    public function getJobMatchMode(): string
    {
        return $this->jobMatchMode;
    }

    public function setJobMatchMode(string $jobMatchMode): static
    {
        $this->jobMatchMode = $jobMatchMode;
        return $this;
    }

    public function getStructureConfig(): ?IndustryStructureConfig
    {
        return $this->structureConfig;
    }

    public function setStructureConfig(?IndustryStructureConfig $structureConfig): static
    {
        $this->structureConfig = $structureConfig;
        return $this;
    }

    public function getSplitGroupId(): ?string
    {
        return $this->splitGroupId;
    }

    public function setSplitGroupId(?string $splitGroupId): static
    {
        $this->splitGroupId = $splitGroupId;
        return $this;
    }

    public function getSplitIndex(): int
    {
        return $this->splitIndex;
    }

    public function setSplitIndex(int $splitIndex): static
    {
        $this->splitIndex = $splitIndex;
        return $this;
    }

    public function getTotalGroupRuns(): ?int
    {
        return $this->totalGroupRuns;
    }

    public function setTotalGroupRuns(?int $totalGroupRuns): static
    {
        $this->totalGroupRuns = $totalGroupRuns;
        return $this;
    }

    public function isSplit(): bool
    {
        return $this->splitGroupId !== null;
    }

    /**
     * @return Collection<int, IndustryStepJobMatch>
     */
    public function getJobMatches(): Collection
    {
        return $this->jobMatches;
    }

    public function addJobMatch(IndustryStepJobMatch $match): static
    {
        if (!$this->jobMatches->contains($match)) {
            $this->jobMatches->add($match);
            $match->setStep($this);
        }
        return $this;
    }

    /**
     * Get total job cost from all matched jobs.
     */
    public function getJobsCost(): float
    {
        $total = 0.0;
        foreach ($this->jobMatches as $match) {
            if ($match->getCost() !== null) {
                $total += $match->getCost();
            }
        }
        return $total;
    }

    /**
     * @return Collection<int, IndustryStepPurchase>
     */
    public function getPurchases(): Collection
    {
        return $this->purchases;
    }

    public function addPurchase(IndustryStepPurchase $purchase): static
    {
        if (!$this->purchases->contains($purchase)) {
            $this->purchases->add($purchase);
            $purchase->setStep($this);
        }
        return $this;
    }

    /**
     * Get total purchase cost for this step.
     */
    public function getPurchasesCost(): float
    {
        $total = 0.0;
        foreach ($this->purchases as $purchase) {
            $total += $purchase->getTotalPrice();
        }
        return $total;
    }
}
