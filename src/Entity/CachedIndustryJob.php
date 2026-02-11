<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CachedIndustryJobRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CachedIndustryJobRepository::class)]
#[ORM\Table(name: 'cached_industry_jobs')]
#[ORM\UniqueConstraint(columns: ['job_id'])]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['blueprint_type_id'])]
#[ORM\Index(columns: ['product_type_id'])]
#[ORM\Index(columns: ['status'])]
class CachedIndustryJob
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\Column(type: 'integer')]
    private int $jobId;

    #[ORM\Column(type: 'integer')]
    private int $activityId;

    #[ORM\Column(type: 'integer')]
    private int $blueprintTypeId;

    #[ORM\Column(type: 'integer')]
    private int $productTypeId;

    #[ORM\Column(type: 'integer')]
    private int $runs;

    #[ORM\Column(type: 'float')]
    private float $cost;

    #[ORM\Column(type: 'string', length: 50)]
    private string $status;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedDate = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $stationId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $cachedAt;

    public function __construct()
    {
        $this->cachedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function setCharacter(Character $character): static
    {
        $this->character = $character;
        return $this;
    }

    public function getJobId(): int
    {
        return $this->jobId;
    }

    public function setJobId(int $jobId): static
    {
        $this->jobId = $jobId;
        return $this;
    }

    public function getActivityId(): int
    {
        return $this->activityId;
    }

    public function setActivityId(int $activityId): static
    {
        $this->activityId = $activityId;
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

    public function getRuns(): int
    {
        return $this->runs;
    }

    public function setRuns(int $runs): static
    {
        $this->runs = $runs;
        return $this;
    }

    public function getCost(): float
    {
        return $this->cost;
    }

    public function setCost(float $cost): static
    {
        $this->cost = $cost;
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

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function getCompletedDate(): ?\DateTimeImmutable
    {
        return $this->completedDate;
    }

    public function setCompletedDate(?\DateTimeImmutable $completedDate): static
    {
        $this->completedDate = $completedDate;
        return $this;
    }

    public function getStationId(): ?int
    {
        return $this->stationId;
    }

    public function setStationId(?int $stationId): static
    {
        $this->stationId = $stationId;
        return $this;
    }

    public function getCachedAt(): \DateTimeImmutable
    {
        return $this->cachedAt;
    }

    public function setCachedAt(\DateTimeImmutable $cachedAt): static
    {
        $this->cachedAt = $cachedAt;
        return $this;
    }
}
