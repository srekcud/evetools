<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryProjectStepRepository;
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

    #[ORM\Column(type: 'string', length: 255)]
    private string $productTypeName;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'integer')]
    private int $runs;

    #[ORM\Column(type: 'integer')]
    private int $depth;

    /** @var int|null ME level for this step (only used for depth 0 root products) */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $meLevel = null;

    /** @var int|null TE level for this step (only used for depth 0 root products) */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $teLevel = null;

    #[ORM\Column(type: 'string', length: 30)]
    private string $activityType = 'manufacturing';

    #[ORM\Column(type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $purchased = false;

    /** @var bool If true, product is already in stock (no purchase cost, not in shopping list) */
    #[ORM\Column(type: 'boolean')]
    private bool $inStock = false;

    /** @var int Quantity already in stock (0 = none, partial = some, >= quantity = all) */
    #[ORM\Column(type: 'integer')]
    private int $inStockQuantity = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $esiJobId = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $esiJobCost = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $esiJobStatus = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $esiJobEndDate = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $esiJobRuns = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $esiJobCharacterName = null;

    /** @var int[] List of all matched ESI job IDs */
    #[ORM\Column(type: 'json')]
    private array $esiJobIds = [];

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $esiJobsCount = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $esiJobsTotalRuns = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $esiJobsActiveRuns = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $esiJobsDeliveredRuns = null;

    /** @var bool If true, auto-matching won't overwrite job data */
    #[ORM\Column(type: 'boolean')]
    private bool $manualJobData = false;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $recommendedStructureName = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $structureBonus = null;

    /** @var float|null Structure time bonus percentage (e.g., 24.03 for 24.03% reduction) */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $structureTimeBonus = null;

    /** @var int|null Adjusted time per run in seconds (with TE 20 and structure bonus applied) */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $timePerRun = null;

    /**
     * Jobs with same blueprint but different runs (for warning).
     * Format: [{ "characterName": "Foo", "runs": 5, "jobId": 123, "status": "active" }, ...]
     *
     * @var array<array{characterName: string, runs: int, jobId: int, status: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $similarJobs = [];

    /** @var string|null UUID to group related splits together */
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $splitGroupId = null;

    /** @var int Index within the split group (0 for first/only, 1, 2, etc.) */
    #[ORM\Column(type: 'integer')]
    private int $splitIndex = 0;

    /** @var int|null Total runs for the entire split group (set on all splits) */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $totalGroupRuns = null;

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

    public function getProductTypeName(): string
    {
        return $this->productTypeName;
    }

    public function setProductTypeName(string $productTypeName): static
    {
        $this->productTypeName = $productTypeName;
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

    public function isInStock(): bool
    {
        return $this->inStock;
    }

    public function setInStock(bool $inStock): static
    {
        $this->inStock = $inStock;
        return $this;
    }

    public function getInStockQuantity(): int
    {
        return $this->inStockQuantity;
    }

    public function setInStockQuantity(int $inStockQuantity): static
    {
        $this->inStockQuantity = max(0, $inStockQuantity);
        // Keep boolean in sync for backward compatibility
        $this->inStock = $inStockQuantity >= $this->quantity;
        return $this;
    }

    /**
     * Get the quantity still needed (not covered by stock).
     */
    public function getMissingQuantity(): int
    {
        return max(0, $this->quantity - $this->inStockQuantity);
    }

    public function getEsiJobId(): ?int
    {
        return $this->esiJobId;
    }

    public function setEsiJobId(?int $esiJobId): static
    {
        $this->esiJobId = $esiJobId;
        return $this;
    }

    public function getEsiJobCost(): ?float
    {
        return $this->esiJobCost;
    }

    public function setEsiJobCost(?float $esiJobCost): static
    {
        $this->esiJobCost = $esiJobCost;
        return $this;
    }

    public function getEsiJobStatus(): ?string
    {
        return $this->esiJobStatus;
    }

    public function setEsiJobStatus(?string $esiJobStatus): static
    {
        $this->esiJobStatus = $esiJobStatus;
        return $this;
    }

    public function getEsiJobEndDate(): ?\DateTimeImmutable
    {
        return $this->esiJobEndDate;
    }

    public function setEsiJobEndDate(?\DateTimeImmutable $esiJobEndDate): static
    {
        $this->esiJobEndDate = $esiJobEndDate;
        return $this;
    }

    public function getEsiJobRuns(): ?int
    {
        return $this->esiJobRuns;
    }

    public function setEsiJobRuns(?int $esiJobRuns): static
    {
        $this->esiJobRuns = $esiJobRuns;
        return $this;
    }

    public function getEsiJobCharacterName(): ?string
    {
        return $this->esiJobCharacterName;
    }

    public function setEsiJobCharacterName(?string $esiJobCharacterName): static
    {
        $this->esiJobCharacterName = $esiJobCharacterName;
        return $this;
    }

    /** @return int[] */
    public function getEsiJobIds(): array
    {
        return $this->esiJobIds;
    }

    /** @param int[] $esiJobIds */
    public function setEsiJobIds(array $esiJobIds): static
    {
        $this->esiJobIds = $esiJobIds;
        return $this;
    }

    public function getEsiJobsCount(): ?int
    {
        return $this->esiJobsCount;
    }

    public function setEsiJobsCount(?int $esiJobsCount): static
    {
        $this->esiJobsCount = $esiJobsCount;
        return $this;
    }

    public function getEsiJobsTotalRuns(): ?int
    {
        return $this->esiJobsTotalRuns;
    }

    public function setEsiJobsTotalRuns(?int $esiJobsTotalRuns): static
    {
        $this->esiJobsTotalRuns = $esiJobsTotalRuns;
        return $this;
    }

    public function getEsiJobsActiveRuns(): ?int
    {
        return $this->esiJobsActiveRuns;
    }

    public function setEsiJobsActiveRuns(?int $esiJobsActiveRuns): static
    {
        $this->esiJobsActiveRuns = $esiJobsActiveRuns;
        return $this;
    }

    public function getEsiJobsDeliveredRuns(): ?int
    {
        return $this->esiJobsDeliveredRuns;
    }

    public function setEsiJobsDeliveredRuns(?int $esiJobsDeliveredRuns): static
    {
        $this->esiJobsDeliveredRuns = $esiJobsDeliveredRuns;
        return $this;
    }

    public function isManualJobData(): bool
    {
        return $this->manualJobData;
    }

    public function setManualJobData(bool $manualJobData): static
    {
        $this->manualJobData = $manualJobData;
        return $this;
    }

    public function getRecommendedStructureName(): ?string
    {
        return $this->recommendedStructureName;
    }

    public function setRecommendedStructureName(?string $recommendedStructureName): static
    {
        $this->recommendedStructureName = $recommendedStructureName;
        return $this;
    }

    public function getStructureBonus(): ?float
    {
        return $this->structureBonus;
    }

    public function setStructureBonus(?float $structureBonus): static
    {
        $this->structureBonus = $structureBonus;
        return $this;
    }

    public function getStructureTimeBonus(): ?float
    {
        return $this->structureTimeBonus;
    }

    public function setStructureTimeBonus(?float $structureTimeBonus): static
    {
        $this->structureTimeBonus = $structureTimeBonus;
        return $this;
    }

    /**
     * Clear all job matching data.
     */
    public function clearJobData(): void
    {
        $this->esiJobId = null;
        $this->esiJobCost = null;
        $this->esiJobStatus = null;
        $this->esiJobEndDate = null;
        $this->esiJobRuns = null;
        $this->esiJobCharacterName = null;
        $this->esiJobIds = [];
        $this->esiJobsCount = null;
        $this->esiJobsTotalRuns = null;
        $this->esiJobsActiveRuns = null;
        $this->esiJobsDeliveredRuns = null;
        $this->manualJobData = false;
        $this->similarJobs = [];
    }

    /**
     * @return array<array{characterName: string, runs: int, jobId: int, status: string}>
     */
    public function getSimilarJobs(): array
    {
        return $this->similarJobs;
    }

    /**
     * @param array<array{characterName: string, runs: int, jobId: int, status: string}> $similarJobs
     */
    public function setSimilarJobs(array $similarJobs): static
    {
        $this->similarJobs = $similarJobs;
        return $this;
    }

    public function getTimePerRun(): ?int
    {
        return $this->timePerRun;
    }

    public function setTimePerRun(?int $timePerRun): static
    {
        $this->timePerRun = $timePerRun;
        return $this;
    }

    /**
     * Get estimated duration in days for this step.
     */
    public function getEstimatedDurationDays(): ?float
    {
        if ($this->timePerRun === null) {
            return null;
        }
        // time is in seconds, convert to days
        return ($this->timePerRun * $this->runs) / 86400;
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

    /**
     * Check if this step is part of a split group.
     */
    public function isSplit(): bool
    {
        return $this->splitGroupId !== null;
    }
}
