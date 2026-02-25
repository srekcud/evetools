<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EscalationBmStatus;
use App\Enum\EscalationSaleStatus;
use App\Enum\EscalationVisibility;
use App\Repository\EscalationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EscalationRepository::class)]
#[ORM\Table(name: 'escalations')]
#[ORM\Index(columns: ['user_id', 'expires_at'])]
#[ORM\Index(columns: ['corporation_id', 'visibility'])]
#[ORM\Index(columns: ['visibility', 'sale_status', 'expires_at'])]
#[ORM\Index(columns: ['alliance_id', 'visibility'])]
class Escalation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'bigint')]
    private int $characterId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $characterName;

    #[ORM\Column(type: 'string', length: 100)]
    private string $type;

    #[ORM\Column(type: 'integer')]
    private int $solarSystemId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $solarSystemName;

    #[ORM\Column(type: 'float')]
    private float $securityStatus;

    #[ORM\Column(type: 'integer')]
    private int $price;

    #[ORM\Column(type: 'string', length: 10, enumType: EscalationVisibility::class)]
    private EscalationVisibility $visibility = EscalationVisibility::Perso;

    #[ORM\Column(type: 'string', length: 10, enumType: EscalationBmStatus::class)]
    private EscalationBmStatus $bmStatus = EscalationBmStatus::Nouveau;

    #[ORM\Column(type: 'string', length: 10, enumType: EscalationSaleStatus::class)]
    private EscalationSaleStatus $saleStatus = EscalationSaleStatus::EnVente;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'bigint')]
    private int $corporationId;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $allianceId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getCharacterId(): int
    {
        return $this->characterId;
    }

    public function setCharacterId(int $characterId): static
    {
        $this->characterId = $characterId;
        return $this;
    }

    public function getCharacterName(): string
    {
        return $this->characterName;
    }

    public function setCharacterName(string $characterName): static
    {
        $this->characterName = $characterName;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getSolarSystemId(): int
    {
        return $this->solarSystemId;
    }

    public function setSolarSystemId(int $solarSystemId): static
    {
        $this->solarSystemId = $solarSystemId;
        return $this;
    }

    public function getSolarSystemName(): string
    {
        return $this->solarSystemName;
    }

    public function setSolarSystemName(string $solarSystemName): static
    {
        $this->solarSystemName = $solarSystemName;
        return $this;
    }

    public function getSecurityStatus(): float
    {
        return $this->securityStatus;
    }

    public function setSecurityStatus(float $securityStatus): static
    {
        $this->securityStatus = $securityStatus;
        return $this;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function setPrice(int $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getVisibility(): EscalationVisibility
    {
        return $this->visibility;
    }

    public function setVisibility(EscalationVisibility $visibility): static
    {
        $this->visibility = $visibility;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getBmStatus(): EscalationBmStatus
    {
        return $this->bmStatus;
    }

    public function setBmStatus(EscalationBmStatus $bmStatus): static
    {
        $this->bmStatus = $bmStatus;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getSaleStatus(): EscalationSaleStatus
    {
        return $this->saleStatus;
    }

    public function setSaleStatus(EscalationSaleStatus $saleStatus): static
    {
        $this->saleStatus = $saleStatus;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getCorporationId(): int
    {
        return $this->corporationId;
    }

    public function setCorporationId(int $corporationId): static
    {
        $this->corporationId = $corporationId;
        return $this;
    }

    public function getAllianceId(): ?int
    {
        return $this->allianceId;
    }

    public function setAllianceId(?int $allianceId): static
    {
        $this->allianceId = $allianceId;
        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    public function isOwnedBy(User $user): bool
    {
        $thisId = $this->user->getId();
        $otherId = $user->getId();

        if ($thisId === null || $otherId === null) {
            return false;
        }

        return $thisId->equals($otherId);
    }
}
