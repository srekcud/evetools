<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ContributionStatus;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryProjectRepository::class)]
#[ORM\Table(name: 'industry_projects')]
#[ORM\Index(columns: ['owner_id'])]
#[ORM\Index(columns: ['status'])]
class IndustryProject
{
    public const float DEFAULT_MINIMUM_OUTSIDE_SAVING = 500000.0;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: IndustryProjectTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?IndustryProjectTemplate $template = null;

    #[ORM\Column(type: 'string', length: 20, enumType: ProjectStatus::class)]
    private ProjectStatus $status = ProjectStatus::Draft;

    #[ORM\Column(type: 'string', length: 10, unique: true)]
    private string $shortLinkCode;

    /** @var array<string, int>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $lineRentalRatesOverride = null;

    /** @var int[] */
    #[ORM\Column(type: 'json')]
    private array $blacklistGroupIds = [];

    /** @var int[] */
    #[ORM\Column(type: 'json')]
    private array $blacklistTypeIds = [];

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $brokerFeePercent = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $salesTaxPercent = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $acceptsOutsideFavoriteSystem = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $excludesInvention = false;

    #[ORM\Column(type: 'float', options: ['default' => self::DEFAULT_MINIMUM_OUTSIDE_SAVING])]
    private float $minimumOutsideSaving = self::DEFAULT_MINIMUM_OUTSIDE_SAVING;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $sellStructureId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $sellStructureName = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $private = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $personalUse = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, IndustryProjectProduct> */
    #[ORM\OneToMany(targetEntity: IndustryProjectProduct::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    /** @var Collection<int, IndustryProjectParticipant> */
    #[ORM\OneToMany(targetEntity: IndustryProjectParticipant::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $members;

    /** @var Collection<int, IndustryProjectContainer> */
    #[ORM\OneToMany(targetEntity: IndustryProjectContainer::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $containers;

    /** @var Collection<int, IndustryProjectBomLine> */
    #[ORM\OneToMany(targetEntity: IndustryProjectBomLine::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $bomItems;

    /** @var Collection<int, IndustryProjectContribution> */
    #[ORM\OneToMany(targetEntity: IndustryProjectContribution::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $contributions;

    /** @var Collection<int, IndustryProjectSale> */
    #[ORM\OneToMany(targetEntity: IndustryProjectSale::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $sales;

    /** @var Collection<int, IndustryProjectDeclaredBlueprintCopy> */
    #[ORM\OneToMany(targetEntity: IndustryProjectDeclaredBlueprintCopy::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $declaredBlueprintCopies;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->shortLinkCode = self::generateShortLinkCode();
        $this->items = new ArrayCollection();
        $this->members = new ArrayCollection();
        $this->containers = new ArrayCollection();
        $this->bomItems = new ArrayCollection();
        $this->contributions = new ArrayCollection();
        $this->sales = new ArrayCollection();
        $this->declaredBlueprintCopies = new ArrayCollection();
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

    public function isPersonalUse(): bool
    {
        return $this->personalUse;
    }

    public function setPersonalUse(bool $personalUse): static
    {
        $this->personalUse = $personalUse;

        return $this;
    }

    public function getSellStructureId(): ?int
    {
        return $this->sellStructureId;
    }

    public function getSellStructureName(): ?string
    {
        return $this->sellStructureName;
    }

    public function setSellStructure(?int $id, ?string $name): static
    {
        $this->sellStructureId = $id;
        $this->sellStructureName = $name;

        return $this;
    }

    public function getTemplate(): ?IndustryProjectTemplate
    {
        return $this->template;
    }

    public function setTemplate(?IndustryProjectTemplate $template): static
    {
        $this->template = $template;
        return $this;
    }

    public function isSoloProjectOf(User $user): bool
    {
        if ($this->owner !== $user) {
            return false;
        }

        foreach ($this->members as $member) {
            if ($member->getStatus() === ParticipantStatus::Accepted && $member->getUser() !== $user) {
                return false;
            }
        }

        return true;
    }

    public function isSoleReviewingOwner(User $user): bool
    {
        if ($this->owner !== $user) {
            return false;
        }

        foreach ($this->members as $member) {
            $canReview = $member->getStatus() === ParticipantStatus::Accepted
                && \in_array($member->getRole(), [ParticipantRole::Owner, ParticipantRole::Admin], true);
            if ($canReview && $member->getUser() !== $user) {
                return false;
            }
        }

        return true;
    }

    public function transferOwnershipTo(IndustryProjectParticipant $member): void
    {
        $newOwner = $member->getUser();
        if ($member->getProject() !== $this || $newOwner === null) {
            throw new \DomainException('Ownership can only be transferred to a member of the project with an account.');
        }

        $member->setRole(ParticipantRole::Owner);
        $this->owner = $newOwner;

        foreach ($this->bomItems as $bomLine) {
            $bomLine->getJobMatches()->clear();
            $bomLine->getPurchases()->clear();
        }
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

    public function getStatus(): ProjectStatus
    {
        return $this->status;
    }

    public function setStatus(ProjectStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->status === ProjectStatus::Completed;
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

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function setPrivate(bool $private): static
    {
        $this->private = $private;
        return $this;
    }

    public function isAcceptsOutsideFavoriteSystem(): bool
    {
        return $this->acceptsOutsideFavoriteSystem;
    }

    public function setAcceptsOutsideFavoriteSystem(bool $acceptsOutsideFavoriteSystem): static
    {
        $this->acceptsOutsideFavoriteSystem = $acceptsOutsideFavoriteSystem;
        return $this;
    }

    public function isExcludesInvention(): bool
    {
        return $this->excludesInvention;
    }

    public function setExcludesInvention(bool $excludesInvention): static
    {
        $this->excludesInvention = $excludesInvention;
        return $this;
    }

    public function getMinimumOutsideSaving(): float
    {
        return $this->minimumOutsideSaving;
    }

    public function setMinimumOutsideSaving(float $minimumOutsideSaving): static
    {
        $this->minimumOutsideSaving = $minimumOutsideSaving;
        return $this;
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

    /** @return Collection<int, IndustryProjectContainer> */
    public function getContainers(): Collection
    {
        return $this->containers;
    }

    /** @return list<string> */
    public function getContainerNames(): array
    {
        return array_values($this->containers->map(static fn (IndustryProjectContainer $container): string => $container->getName())->toArray());
    }

    /** @param list<string> $containerNames */
    public function setContainerNames(array $containerNames): static
    {
        $wantedNamesByKey = [];
        foreach ($containerNames as $containerName) {
            $trimmedName = trim($containerName);
            $key = IndustryProjectContainer::keyOf($trimmedName);
            if ('' !== $key && !isset($wantedNamesByKey[$key])) {
                $wantedNamesByKey[$key] = $trimmedName;
            }
        }

        $keptKeys = [];
        foreach ($this->containers->toArray() as $container) {
            $key = IndustryProjectContainer::keyOf($container->getName());
            if (isset($wantedNamesByKey[$key]) && $wantedNamesByKey[$key] === $container->getName()) {
                $keptKeys[$key] = true;
            } else {
                $this->containers->removeElement($container);
            }
        }

        foreach ($wantedNamesByKey as $key => $name) {
            if (!isset($keptKeys[$key])) {
                $this->containers->add(new IndustryProjectContainer($this, $name));
            }
        }

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

    public function getBrokerFeePercent(): ?float
    {
        return $this->brokerFeePercent;
    }

    public function setBrokerFeePercent(?float $brokerFeePercent): static
    {
        $this->brokerFeePercent = $brokerFeePercent;
        return $this;
    }

    public function getSalesTaxPercent(): ?float
    {
        return $this->salesTaxPercent;
    }

    public function setSalesTaxPercent(?float $salesTaxPercent): static
    {
        $this->salesTaxPercent = $salesTaxPercent;
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

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;
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

    public function getOwnerParticipant(): IndustryProjectParticipant
    {
        foreach ($this->members as $member) {
            if ($member->getUser() === $this->owner) {
                return $member;
            }
        }

        throw new \LogicException(sprintf('Project %s has no participant for its owner.', $this->id->toRfc4122()));
    }

    public function enrolOwner(): static
    {
        return $this->addMember(
            (new IndustryProjectParticipant())
                ->setUser($this->owner)
                ->setRole(ParticipantRole::Owner)
                ->setStatus(ParticipantStatus::Accepted),
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

    /** @return Collection<int, IndustryProjectDeclaredBlueprintCopy> */
    public function getDeclaredBlueprintCopies(): Collection
    {
        return $this->declaredBlueprintCopies;
    }

    /** @param list<IndustryProjectDeclaredBlueprintCopy> $declaredBlueprintCopies */
    public function replaceDeclaredBlueprintCopies(array $declaredBlueprintCopies): static
    {
        $existingByProductTypeId = [];
        foreach ($this->declaredBlueprintCopies as $existingCopy) {
            $existingByProductTypeId[$existingCopy->getProductTypeId()] = $existingCopy;
        }

        $retainedCopies = [];
        foreach ($declaredBlueprintCopies as $declaredBlueprintCopy) {
            $existingCopy = $existingByProductTypeId[$declaredBlueprintCopy->getProductTypeId()] ?? null;
            if (null !== $existingCopy && $existingCopy !== $declaredBlueprintCopy) {
                $retainedCopies[] = $existingCopy->setRuns($declaredBlueprintCopy->getRuns());
            } else {
                $retainedCopies[] = $declaredBlueprintCopy;
            }
        }

        $this->declaredBlueprintCopies->clear();
        foreach ($retainedCopies as $retainedCopy) {
            $this->declaredBlueprintCopies->add($retainedCopy->setProject($this));
        }

        return $this;
    }

    /** @return array<int, int> */
    public function declaredRunsByProductTypeId(): array
    {
        $runsByProductTypeId = [];
        foreach ($this->declaredBlueprintCopies as $declaredBlueprintCopy) {
            $runsByProductTypeId[$declaredBlueprintCopy->getProductTypeId()] = $declaredBlueprintCopy->getRuns();
        }

        return $runsByProductTypeId;
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
}
