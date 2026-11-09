<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ContributionStatus;
use App\Enum\ContributionType;
use App\Repository\IndustryProjectContributionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryProjectContributionRepository::class)]
#[ORM\Table(name: 'industry_project_contributions')]
#[ORM\Index(columns: ['project_id'])]
#[ORM\Index(columns: ['member_id'])]
#[ORM\Index(columns: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_industry_project_derived_contribution', columns: ['project_id', 'member_id', 'bom_item_id', 'type'], options: ['where' => '(is_auto_detected = true)'])]
class IndustryProjectContribution
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: IndustryProject::class, inversedBy: 'contributions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProject $project;

    #[ORM\ManyToOne(targetEntity: IndustryProjectParticipant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProjectParticipant $member;

    #[ORM\ManyToOne(targetEntity: IndustryProjectBomLine::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?IndustryProjectBomLine $bomItem = null;

    #[ORM\Column(type: 'string', length: 20, enumType: ContributionType::class)]
    private ContributionType $type;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $estimatedValue = null;

    #[ORM\Column(type: 'string', length: 10, enumType: ContributionStatus::class)]
    private ContributionStatus $status = ContributionStatus::Pending;

    #[ORM\Column(type: 'boolean')]
    private bool $isAutoDetected = false;

    #[ORM\Column(type: 'boolean')]
    private bool $isVerified = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getMember(): IndustryProjectParticipant
    {
        return $this->member;
    }

    public function setMember(IndustryProjectParticipant $member): static
    {
        $this->member = $member;
        return $this;
    }

    public function getBomItem(): ?IndustryProjectBomLine
    {
        return $this->bomItem;
    }

    public function setBomItem(?IndustryProjectBomLine $bomItem): static
    {
        $this->bomItem = $bomItem;
        return $this;
    }

    public function getType(): ContributionType
    {
        return $this->type;
    }

    public function setType(ContributionType $type): static
    {
        $this->type = $type;
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

    public function getEstimatedValue(): ?float
    {
        return $this->estimatedValue;
    }

    public function setEstimatedValue(?float $estimatedValue): static
    {
        $this->estimatedValue = $estimatedValue;
        return $this;
    }

    public function getStatus(): ContributionStatus
    {
        return $this->status;
    }

    public function setStatus(ContributionStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function duplicatesDerived(): bool
    {
        if ($this->isAutoDetected || ContributionStatus::Approved !== $this->status) {
            return false;
        }

        foreach ($this->getProject()->getContributions() as $contribution) {
            if ($contribution->isAutoDetected()
                && $contribution->getMember() === $this->member
                && $contribution->getBomItem() === $this->bomItem
                && $contribution->getType() === $this->type) {
                return true;
            }
        }

        return false;
    }

    public function isAutoDetected(): bool
    {
        return $this->isAutoDetected;
    }

    public function setIsAutoDetected(bool $isAutoDetected): static
    {
        $this->isAutoDetected = $isAutoDetected;
        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): static
    {
        $this->verifiedAt = $verifiedAt;
        return $this;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?User $reviewedBy): static
    {
        $this->reviewedBy = $reviewedBy;
        return $this;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?\DateTimeImmutable $reviewedAt): static
    {
        $this->reviewedAt = $reviewedAt;
        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
