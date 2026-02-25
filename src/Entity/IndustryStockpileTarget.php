<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryStockpileTargetRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryStockpileTargetRepository::class)]
#[ORM\Table(name: 'industry_stockpile_targets')]
#[ORM\UniqueConstraint(name: 'uniq_user_type', columns: ['user_id', 'type_id'])]
#[ORM\Index(columns: ['user_id'])]
#[ORM\Index(columns: ['stage'])]
class IndustryStockpileTarget
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
    private int $typeId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $typeName;

    #[ORM\Column(type: 'integer')]
    private int $targetQuantity;

    #[ORM\Column(type: 'string', length: 20)]
    private string $stage;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sourceProductTypeId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
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

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): static
    {
        $this->typeId = $typeId;
        return $this;
    }

    public function getTypeName(): string
    {
        return $this->typeName;
    }

    public function setTypeName(string $typeName): static
    {
        $this->typeName = $typeName;
        return $this;
    }

    public function getTargetQuantity(): int
    {
        return $this->targetQuantity;
    }

    public function setTargetQuantity(int $targetQuantity): static
    {
        $this->targetQuantity = $targetQuantity;
        return $this;
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function setStage(string $stage): static
    {
        $this->stage = $stage;
        return $this;
    }

    public function getSourceProductTypeId(): ?int
    {
        return $this->sourceProductTypeId;
    }

    public function setSourceProductTypeId(?int $sourceProductTypeId): static
    {
        $this->sourceProductTypeId = $sourceProductTypeId;
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
}
