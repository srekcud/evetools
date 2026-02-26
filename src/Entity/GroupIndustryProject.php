<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GroupProjectStatus;
use App\Repository\GroupIndustryProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GroupIndustryProjectRepository::class)]
#[ORM\Table(name: 'group_industry_projects')]
#[ORM\Index(columns: ['owner_id'])]
#[ORM\Index(columns: ['status'])]
class GroupIndustryProject
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', length: 20, enumType: GroupProjectStatus::class)]
    private GroupProjectStatus $status = GroupProjectStatus::Published;

    #[ORM\Column(type: 'string', length: 10, unique: true)]
    private string $shortLinkCode;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $containerName = null;

    /** @var array<string, int>|null Override line rental rates for this project */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $lineRentalRatesOverride = null;

    /** @var int[] SDE group IDs blacklisted from production */
    #[ORM\Column(type: 'json')]
    private array $blacklistGroupIds = [];

    /** @var int[] Individual type IDs blacklisted from production */
    #[ORM\Column(type: 'json')]
    private array $blacklistTypeIds = [];

    #[ORM\Column(type: 'float')]
    private float $brokerFeePercent = 3.6;

    #[ORM\Column(type: 'float')]
    private float $salesTaxPercent = 3.6;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, GroupIndustryProjectItem> */
    #[ORM\OneToMany(targetEntity: GroupIndustryProjectItem::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    /** @var Collection<int, GroupIndustryProjectMember> */
    #[ORM\OneToMany(targetEntity: GroupIndustryProjectMember::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $members;

    /** @var Collection<int, GroupIndustryBomItem> */
    #[ORM\OneToMany(targetEntity: GroupIndustryBomItem::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $bomItems;

    /** @var Collection<int, GroupIndustryContribution> */
    #[ORM\OneToMany(targetEntity: GroupIndustryContribution::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $contributions;

    /** @var Collection<int, GroupIndustrySale> */
    #[ORM\OneToMany(targetEntity: GroupIndustrySale::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $sales;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->shortLinkCode = self::generateShortLinkCode();
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

    public function getId(): ?Uuid
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
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

    /** @return Collection<int, GroupIndustryProjectItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(GroupIndustryProjectItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setProject($this);
        }
        return $this;
    }

    /** @return Collection<int, GroupIndustryProjectMember> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(GroupIndustryProjectMember $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setProject($this);
        }
        return $this;
    }

    /** @return Collection<int, GroupIndustryBomItem> */
    public function getBomItems(): Collection
    {
        return $this->bomItems;
    }

    public function addBomItem(GroupIndustryBomItem $bomItem): static
    {
        if (!$this->bomItems->contains($bomItem)) {
            $this->bomItems->add($bomItem);
            $bomItem->setProject($this);
        }
        return $this;
    }

    /** @return Collection<int, GroupIndustryContribution> */
    public function getContributions(): Collection
    {
        return $this->contributions;
    }

    public function addContribution(GroupIndustryContribution $contribution): static
    {
        if (!$this->contributions->contains($contribution)) {
            $this->contributions->add($contribution);
            $contribution->setProject($this);
        }
        return $this;
    }

    /** @return Collection<int, GroupIndustrySale> */
    public function getSales(): Collection
    {
        return $this->sales;
    }

    public function addSale(GroupIndustrySale $sale): static
    {
        if (!$this->sales->contains($sale)) {
            $this->sales->add($sale);
            $sale->setProject($this);
        }
        return $this;
    }
}
