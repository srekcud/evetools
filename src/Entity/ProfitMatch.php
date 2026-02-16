<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProfitMatchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProfitMatchRepository::class)]
#[ORM\Table(name: 'profit_matches')]
#[ORM\Index(columns: ['user_id', 'product_type_id'])]
#[ORM\Index(columns: ['user_id', 'matched_at'])]
class ProfitMatch
{
    public const STATUS_MATCHED = 'matched';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_IGNORED = 'ignored';

    public const COST_SOURCE_MARKET = 'market';
    public const COST_SOURCE_PROJECT = 'project';
    public const COST_SOURCE_MANUAL = 'manual';

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

    #[ORM\ManyToOne(targetEntity: CachedIndustryJob::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CachedIndustryJob $job = null;

    #[ORM\ManyToOne(targetEntity: CachedWalletTransaction::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CachedWalletTransaction $transaction = null;

    #[ORM\Column(type: 'integer')]
    private int $jobRuns;

    #[ORM\Column(type: 'integer')]
    private int $quantitySold;

    #[ORM\Column(type: 'float')]
    private float $materialCost;

    #[ORM\Column(type: 'float')]
    private float $jobInstallCost;

    #[ORM\Column(type: 'float')]
    private float $taxAmount;

    #[ORM\Column(type: 'float')]
    private float $revenue;

    #[ORM\Column(type: 'float')]
    private float $profit;

    #[ORM\Column(type: 'string', length: 20)]
    private string $costSource = self::COST_SOURCE_MARKET;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_MATCHED;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $matchedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->matchedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getJob(): ?CachedIndustryJob
    {
        return $this->job;
    }

    public function setJob(?CachedIndustryJob $job): static
    {
        $this->job = $job;
        return $this;
    }

    public function getTransaction(): ?CachedWalletTransaction
    {
        return $this->transaction;
    }

    public function setTransaction(?CachedWalletTransaction $transaction): static
    {
        $this->transaction = $transaction;
        return $this;
    }

    public function getJobRuns(): int
    {
        return $this->jobRuns;
    }

    public function setJobRuns(int $jobRuns): static
    {
        $this->jobRuns = $jobRuns;
        return $this;
    }

    public function getQuantitySold(): int
    {
        return $this->quantitySold;
    }

    public function setQuantitySold(int $quantitySold): static
    {
        $this->quantitySold = $quantitySold;
        return $this;
    }

    public function getMaterialCost(): float
    {
        return $this->materialCost;
    }

    public function setMaterialCost(float $materialCost): static
    {
        $this->materialCost = $materialCost;
        return $this;
    }

    public function getJobInstallCost(): float
    {
        return $this->jobInstallCost;
    }

    public function setJobInstallCost(float $jobInstallCost): static
    {
        $this->jobInstallCost = $jobInstallCost;
        return $this;
    }

    public function getTaxAmount(): float
    {
        return $this->taxAmount;
    }

    public function setTaxAmount(float $taxAmount): static
    {
        $this->taxAmount = $taxAmount;
        return $this;
    }

    public function getRevenue(): float
    {
        return $this->revenue;
    }

    public function setRevenue(float $revenue): static
    {
        $this->revenue = $revenue;
        return $this;
    }

    public function getProfit(): float
    {
        return $this->profit;
    }

    public function setProfit(float $profit): static
    {
        $this->profit = $profit;
        return $this;
    }

    public function getCostSource(): string
    {
        return $this->costSource;
    }

    public function setCostSource(string $costSource): static
    {
        $this->costSource = $costSource;
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

    public function getMatchedAt(): \DateTimeImmutable
    {
        return $this->matchedAt;
    }

    public function setMatchedAt(\DateTimeImmutable $matchedAt): static
    {
        $this->matchedAt = $matchedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
