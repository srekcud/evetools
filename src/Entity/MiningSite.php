<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MiningSiteStatus;
use App\Repository\MiningSiteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MiningSiteRepository::class)]
#[ORM\Table(name: 'mining_sites')]
#[ORM\Index(columns: ['owner_id'])]
class MiningSite
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne(targetEntity: MiningOperation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MiningOperation $operation = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $label = '';

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solarSystemId = null;

    #[ORM\Column(type: 'string', length: 20, enumType: MiningSiteStatus::class)]
    private MiningSiteStatus $status = MiningSiteStatus::Active;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

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

    public function getOperation(): ?MiningOperation
    {
        return $this->operation;
    }

    public function setOperation(?MiningOperation $operation): static
    {
        $this->operation = $operation;

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

    public function getSolarSystemId(): ?int
    {
        return $this->solarSystemId;
    }

    public function setSolarSystemId(?int $solarSystemId): static
    {
        $this->solarSystemId = $solarSystemId;

        return $this;
    }

    public function getStatus(): MiningSiteStatus
    {
        return $this->status;
    }

    public function setStatus(MiningSiteStatus $status): static
    {
        $this->status = $status;

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
}
