<?php

declare(strict_types=1);

namespace App\Entity;

use App\Constant\EveConstants;
use App\Enum\ContributionStatus;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\IndustryProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryProjectRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'industry_projects')]
#[ORM\Index(columns: ['owner_id'])]
#[ORM\Index(columns: ['status'])]
class IndustryProject
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(type: 'integer')]
    private int $productTypeId;

    /** @var string|null Custom project name (null = use product name) */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'integer')]
    private int $runs;

    #[ORM\Column(type: 'integer')]
    private int $meLevel = 0;

    #[ORM\Column(type: 'integer')]
    private int $teLevel = 0;

    /** @var float Maximum job duration in days before splitting (default 2.0 days) */
    #[ORM\Column(type: 'float')]
    private float $maxJobDurationDays = 2.0;

    #[ORM\Column(type: 'string', length: 20, enumType: GroupProjectStatus::class)]
    private GroupProjectStatus $status = GroupProjectStatus::Published;

    #[ORM\Column(type: 'string', length: 10, unique: true)]
    private string $shortLinkCode;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $containerName = null;

    /** @var array<string, int>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $lineRentalRatesOverride = null;

    /** @var int[] */
    #[ORM\Column(type: 'json')]
    private array $blacklistGroupIds = [];

    /** @var int[] */
    #[ORM\Column(type: 'json')]
    private array $blacklistTypeIds = [];

    #[ORM\Column(type: 'float')]
    private float $brokerFeePercent = EveConstants::DEFAULT_BROKER_FEE_PERCENT;

    #[ORM\Column(type: 'float')]
    private float $salesTaxPercent = EveConstants::DEFAULT_SALES_TAX_PERCENT;

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

    /** @var array<array{typeId: int, typeName: string, quantity: int}>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $inventionMaterials = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $estimatedJobCost = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $estimatedMaterialCost = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $estimatedSellPrice = null;

    /** @var string|null Source of estimated sell price: 'jita' or 'structure' */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $estimatedSellPriceSource = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $estimatedTaxAmount = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var \DateTimeImmutable|null Custom start date for job matching (defaults to createdAt) */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $jobsStartDate = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, IndustryProjectStep> */
    #[ORM\OneToMany(targetEntity: IndustryProjectStep::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $steps;

    /** @var Collection<int, IndustryProjectProduct> */
    #[ORM\OneToMany(targetEntity: IndustryProjectProduct::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    /** @var Collection<int, IndustryProjectParticipant> */
    #[ORM\OneToMany(targetEntity: IndustryProjectParticipant::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $members;

    /** @var Collection<int, IndustryProjectBomLine> */
    #[ORM\OneToMany(targetEntity: IndustryProjectBomLine::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $bomItems;

    /** @var Collection<int, IndustryProjectContribution> */
    #[ORM\OneToMany(targetEntity: IndustryProjectContribution::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $contributions;

    /** @var Collection<int, IndustryProjectSale> */
    #[ORM\OneToMany(targetEntity: IndustryProjectSale::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $sales;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->shortLinkCode = self::generateShortLinkCode();
        $this->steps = new ArrayCollection();
        $this->items = new ArrayCollection();
        $this->members = new ArrayCollection();
        $this->bomItems = new ArrayCollection();
        $this->contributions = new ArrayCollection();
        $this->sales = new ArrayCollection();
    }

    private static function generateShortLinkCode(): string
    {
        return substr(bin2hex(random_bytes(5)), 0, 10);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    public function transferOwnershipTo(IndustryProjectParticipant $member): void
    {
        $newOwner = $member->getUser();
        if ($member->getProject() !== $this || $newOwner === null) {
            throw new \DomainException('Ownership can only be transferred to a member of the project with an account.');
        }

        $member->setRole(GroupMemberRole::Owner);
        $this->owner = $newOwner;
    }

    public function isPlannedBySteps(): bool
    {
        return $this->items->isEmpty();
    }

    #[ORM\PreFlush]
    public function takeProductFromFirstItem(): void
    {
        if (isset($this->productTypeId)) {
            return;
        }

        $firstItem = $this->items->first();
        if ($firstItem === false) {
            return;
        }

        $this->productTypeId = $firstItem->getTypeId();
        $this->runs = $firstItem->getRuns();
        $this->meLevel = $firstItem->getMeLevel();
        $this->teLevel = $firstItem->getTeLevel();
    }

    public function isPlannedByStepsAndOwnedBy(User $user): bool
    {
        return $this->isPlannedBySteps() && $this->owner === $user;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
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

    public function getTeLevel(): int
    {
        return $this->teLevel;
    }

    public function setTeLevel(int $teLevel): static
    {
        $this->teLevel = $teLevel;
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

    public function getStatus(): GroupProjectStatus
    {
        return $this->status;
    }

    public function setStatus(GroupProjectStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->status === GroupProjectStatus::Completed;
    }

    public function isDeletable(): bool
    {
        if ($this->isCompleted() || !$this->sales->isEmpty()) {
            return false;
        }

        return !$this->contributions->exists(
            static fn (int $key, IndustryProjectContribution $contribution): bool => $contribution->getStatus() === ContributionStatus::Approved,
        );
    }

    public function getShortLinkCode(): string
    {
        return $this->shortLinkCode;
    }

    public function setShortLinkCode(string $shortLinkCode): static
    {
        $this->shortLinkCode = $shortLinkCode;
        return $this;
    }

    public function getContainerName(): ?string
    {
        return $this->containerName;
    }

    public function setContainerName(?string $containerName): static
    {
        $this->containerName = $containerName;
        return $this;
    }

    /** @return array<string, int>|null */
    public function getLineRentalRatesOverride(): ?array
    {
        return $this->lineRentalRatesOverride;
    }

    /** @param array<string, int>|null $rates */
    public function setLineRentalRatesOverride(?array $rates): static
    {
        $this->lineRentalRatesOverride = $rates;
        return $this;
    }

    /** @return int[] */
    public function getBlacklistGroupIds(): array
    {
        return $this->blacklistGroupIds;
    }

    /** @param int[] $ids */
    public function setBlacklistGroupIds(array $ids): static
    {
        $this->blacklistGroupIds = $ids;
        return $this;
    }

    /** @return int[] */
    public function getBlacklistTypeIds(): array
    {
        return $this->blacklistTypeIds;
    }

    /** @param int[] $ids */
    public function setBlacklistTypeIds(array $ids): static
    {
        $this->blacklistTypeIds = $ids;
        return $this;
    }

    public function getBrokerFeePercent(): float
    {
        return $this->brokerFeePercent;
    }

    public function setBrokerFeePercent(float $brokerFeePercent): static
    {
        $this->brokerFeePercent = $brokerFeePercent;
        return $this;
    }

    public function getSalesTaxPercent(): float
    {
        return $this->salesTaxPercent;
    }

    public function setSalesTaxPercent(float $salesTaxPercent): static
    {
        $this->salesTaxPercent = $salesTaxPercent;
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

    public function getEstimatedJobCost(): ?float
    {
        return $this->estimatedJobCost;
    }

    public function setEstimatedJobCost(?float $estimatedJobCost): static
    {
        $this->estimatedJobCost = $estimatedJobCost;
        return $this;
    }

    public function getEstimatedMaterialCost(): ?float
    {
        return $this->estimatedMaterialCost;
    }

    public function setEstimatedMaterialCost(?float $estimatedMaterialCost): static
    {
        $this->estimatedMaterialCost = $estimatedMaterialCost;
        return $this;
    }

    public function getEstimatedSellPrice(): ?float
    {
        return $this->estimatedSellPrice;
    }

    public function setEstimatedSellPrice(?float $estimatedSellPrice): static
    {
        $this->estimatedSellPrice = $estimatedSellPrice;
        return $this;
    }

    public function getEstimatedSellPriceSource(): ?string
    {
        return $this->estimatedSellPriceSource;
    }

    public function setEstimatedSellPriceSource(?string $estimatedSellPriceSource): static
    {
        $this->estimatedSellPriceSource = $estimatedSellPriceSource;
        return $this;
    }

    public function getEstimatedTaxAmount(): ?float
    {
        return $this->estimatedTaxAmount;
    }

    public function setEstimatedTaxAmount(?float $estimatedTaxAmount): static
    {
        $this->estimatedTaxAmount = $estimatedTaxAmount;
        return $this;
    }

    public function recordEstimatedSellPrice(float $price, string $source, float $tax): static
    {
        $this->estimatedSellPrice = $price;
        $this->estimatedSellPriceSource = $source;
        $this->estimatedTaxAmount = $tax;
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

    /**
     * @param list<int> $blacklistedTypeIds
     * @return list<int>
     */
    public function excludedTypeIdsWithBlacklist(array $blacklistedTypeIds): array
    {
        return array_values(array_unique([...$blacklistedTypeIds, ...$this->excludedTypeIds]));
    }

    /** @return array<array{typeId: int, typeName: string, quantity: int}>|null */
    public function getInventionMaterials(): ?array
    {
        return $this->inventionMaterials;
    }

    /** @param array<array{typeId: int, typeName: string, quantity: int}>|null $inventionMaterials */
    public function setInventionMaterials(?array $inventionMaterials): static
    {
        $this->inventionMaterials = $inventionMaterials;
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
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

    public function removeStep(IndustryProjectStep $step): static
    {
        $this->steps->removeElement($step);

        return $this;
    }

    /** @return Collection<int, IndustryProjectProduct> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function getItemTypeName(int $typeId): ?string
    {
        foreach ($this->items as $item) {
            if ($item->getTypeId() === $typeId) {
                return $item->getTypeName();
            }
        }

        return null;
    }

    public function addItem(IndustryProjectProduct $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setProject($this);
        }
        return $this;
    }

    /** @return Collection<int, IndustryProjectParticipant> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function enrolOwner(): static
    {
        return $this->addMember(
            (new IndustryProjectParticipant())
                ->setUser($this->owner)
                ->setRole(GroupMemberRole::Owner)
                ->setStatus(GroupMemberStatus::Accepted),
        );
    }

    public function addMember(IndustryProjectParticipant $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setProject($this);
        }
        return $this;
    }

    /** @return Collection<int, IndustryProjectBomLine> */
    public function getBomItems(): Collection
    {
        return $this->bomItems;
    }

    public function addBomItem(IndustryProjectBomLine $bomItem): static
    {
        if (!$this->bomItems->contains($bomItem)) {
            $this->bomItems->add($bomItem);
            $bomItem->setProject($this);
        }
        return $this;
    }

    public function removeBomItem(IndustryProjectBomLine $bomItem): static
    {
        $this->bomItems->removeElement($bomItem);

        return $this;
    }

    /** @return Collection<int, IndustryProjectContribution> */
    public function getContributions(): Collection
    {
        return $this->contributions;
    }

    public function addContribution(IndustryProjectContribution $contribution): static
    {
        if (!$this->contributions->contains($contribution)) {
            $this->contributions->add($contribution);
            $contribution->setProject($this);
        }
        return $this;
    }

    /** @return Collection<int, IndustryProjectSale> */
    public function getSales(): Collection
    {
        return $this->sales;
    }

    public function addSale(IndustryProjectSale $sale): static
    {
        if (!$this->sales->contains($sale)) {
            $this->sales->add($sale);
            $sale->setProject($this);
        }
        return $this;
    }

    /**
     * @param \Closure(IndustryProjectStep): list<int> $materialTypeIdsOf
     */
    public function refusalToDeleteStep(IndustryProjectStep $step, \Closure $materialTypeIdsOf): ?string
    {
        if (!$step->getJobMatches()->isEmpty()) {
            return 'Cannot delete a step linked to an ESI job';
        }

        if ($this->isLastProductionStepOfProjectProduct($step)) {
            return 'Cannot delete the last step of the project product';
        }

        if ($step->isCommitted() && $this->otherJobsOfGroupOf($step) === []) {
            return 'Cannot delete a step holding purchases, stock or a purchased mark';
        }

        if ($this->leavesMaterialStepWithoutConsumer($step, $materialTypeIdsOf)) {
            return 'Cannot delete a step whose material steps have no other consumer; delete them first';
        }

        return null;
    }

    /**
     * @return list<IndustryProjectStep>
     */
    public function otherJobsOfGroupOf(IndustryProjectStep $step): array
    {
        if ($step->getSplitGroupId() === null) {
            return [];
        }

        return array_values($this->steps->filter(
            static fn (IndustryProjectStep $other): bool => $other !== $step && $other->getSplitGroupId() === $step->getSplitGroupId(),
        )->toArray());
    }

    /**
     * @param \Closure(IndustryProjectStep): list<int> $materialTypeIdsOf
     */
    private function leavesMaterialStepWithoutConsumer(IndustryProjectStep $step, \Closure $materialTypeIdsOf): bool
    {
        if ($step->getActivityType() === 'copy') {
            return false;
        }

        $remainingProductionSteps = array_values($this->steps->filter(
            static fn (IndustryProjectStep $other): bool => $other !== $step && $other->getActivityType() !== 'copy',
        )->toArray());
        $remainingProductTypeIds = array_map(static fn (IndustryProjectStep $other): int => $other->getProductTypeId(), $remainingProductionSteps);
        $materialStepProductTypeIds = array_intersect(array_unique($materialTypeIdsOf($step)), $remainingProductTypeIds);

        foreach ($materialStepProductTypeIds as $materialStepProductTypeId) {
            $hasOtherConsumer = array_any(
                $remainingProductionSteps,
                static fn (IndustryProjectStep $consumer): bool => \in_array($materialStepProductTypeId, $materialTypeIdsOf($consumer), true),
            );
            if (!$hasOtherConsumer) {
                return true;
            }
        }

        return false;
    }

    private function isLastProductionStepOfProjectProduct(IndustryProjectStep $step): bool
    {
        if ($step->getActivityType() === 'copy' || $step->getProductTypeId() !== $this->productTypeId) {
            return false;
        }

        return $this->steps->filter(
            static fn (IndustryProjectStep $other): bool => $other !== $step
                && $other->getActivityType() !== 'copy'
                && $other->getProductTypeId() === $step->getProductTypeId(),
        )->isEmpty();
    }

    public function getJobsCost(): float
    {
        $total = 0.0;
        foreach ($this->steps as $step) {
            $total += $step->getJobsCost();
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
