<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MiningOperationRecurrenceKind;
use App\Enum\MiningOperationVisibility;
use App\Enum\MiningSplitMode;
use App\Repository\MiningOperationRecurrenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MiningOperationRecurrenceRepository::class)]
#[ORM\Table(name: 'mining_operation_recurrences')]
#[ORM\Index(columns: ['owner_id'])]
class MiningOperationRecurrence
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(type: 'string', length: 20, enumType: MiningOperationRecurrenceKind::class)]
    private MiningOperationRecurrenceKind $kind;

    #[ORM\Column(type: 'string', length: 100)]
    private string $label = '';

    #[ORM\Column(type: 'string', length: 20, enumType: MiningOperationVisibility::class)]
    private MiningOperationVisibility $visibility = MiningOperationVisibility::Private;

    #[ORM\Column(type: 'string', length: 30, enumType: MiningSplitMode::class)]
    private MiningSplitMode $splitMode = MiningSplitMode::ValueProrata;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $corporationId = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $structureId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $structureName = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $anchorAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $stoppedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getKind(): MiningOperationRecurrenceKind
    {
        return $this->kind;
    }

    public function setKind(MiningOperationRecurrenceKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getVisibility(): MiningOperationVisibility
    {
        return $this->visibility;
    }

    public function setVisibility(MiningOperationVisibility $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getSplitMode(): MiningSplitMode
    {
        return $this->splitMode;
    }

    public function setSplitMode(MiningSplitMode $splitMode): static
    {
        $this->splitMode = $splitMode;

        return $this;
    }

    public function getCorporationId(): ?int
    {
        return $this->corporationId;
    }

    public function setCorporationId(?int $corporationId): static
    {
        $this->corporationId = $corporationId;

        return $this;
    }

    public function getStructureId(): ?int
    {
        return $this->structureId;
    }

    public function setStructureId(?int $structureId): static
    {
        $this->structureId = $structureId;

        return $this;
    }

    public function getStructureName(): ?string
    {
        return $this->structureName;
    }

    public function setStructureName(?string $structureName): static
    {
        $this->structureName = $structureName;

        return $this;
    }

    public function getAnchorAt(): ?\DateTimeImmutable
    {
        return $this->anchorAt;
    }

    public function setAnchorAt(?\DateTimeImmutable $anchorAt): static
    {
        $this->anchorAt = $anchorAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getStoppedAt(): ?\DateTimeImmutable
    {
        return $this->stoppedAt;
    }

    public function isActive(): bool
    {
        return $this->stoppedAt === null;
    }

    public function stop(\DateTimeImmutable $stoppedAt): void
    {
        $this->stoppedAt = $stoppedAt;
    }
}
