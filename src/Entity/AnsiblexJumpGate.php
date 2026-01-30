<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AnsiblexJumpGateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnsiblexJumpGateRepository::class)]
#[ORM\Table(name: 'ansiblex_jump_gates')]
#[ORM\Index(columns: ['source_solar_system_id'])]
#[ORM\Index(columns: ['destination_solar_system_id'])]
#[ORM\Index(columns: ['owner_alliance_id'])]
#[ORM\Index(columns: ['is_active'])]
#[ORM\UniqueConstraint(columns: ['source_solar_system_id', 'destination_solar_system_id'])]
class AnsiblexJumpGate
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    private int $structureId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'integer')]
    private int $sourceSolarSystemId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $sourceSolarSystemName;

    #[ORM\Column(type: 'integer')]
    private int $destinationSolarSystemId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $destinationSolarSystemName;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $ownerAllianceId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $ownerAllianceName = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getStructureId(): int
    {
        return $this->structureId;
    }

    public function setStructureId(int $structureId): static
    {
        $this->structureId = $structureId;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getSourceSolarSystemId(): int
    {
        return $this->sourceSolarSystemId;
    }

    public function setSourceSolarSystemId(int $sourceSolarSystemId): static
    {
        $this->sourceSolarSystemId = $sourceSolarSystemId;
        return $this;
    }

    public function getSourceSolarSystemName(): string
    {
        return $this->sourceSolarSystemName;
    }

    public function setSourceSolarSystemName(string $sourceSolarSystemName): static
    {
        $this->sourceSolarSystemName = $sourceSolarSystemName;
        return $this;
    }

    public function getDestinationSolarSystemId(): int
    {
        return $this->destinationSolarSystemId;
    }

    public function setDestinationSolarSystemId(int $destinationSolarSystemId): static
    {
        $this->destinationSolarSystemId = $destinationSolarSystemId;
        return $this;
    }

    public function getDestinationSolarSystemName(): string
    {
        return $this->destinationSolarSystemName;
    }

    public function setDestinationSolarSystemName(string $destinationSolarSystemName): static
    {
        $this->destinationSolarSystemName = $destinationSolarSystemName;
        return $this;
    }

    public function getOwnerAllianceId(): ?int
    {
        return $this->ownerAllianceId;
    }

    public function setOwnerAllianceId(?int $ownerAllianceId): static
    {
        $this->ownerAllianceId = $ownerAllianceId;
        return $this;
    }

    public function getOwnerAllianceName(): ?string
    {
        return $this->ownerAllianceName;
    }

    public function setOwnerAllianceName(?string $ownerAllianceName): static
    {
        $this->ownerAllianceName = $ownerAllianceName;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;
        return $this;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->lastSeenAt = new \DateTimeImmutable();
        return $this;
    }
}
