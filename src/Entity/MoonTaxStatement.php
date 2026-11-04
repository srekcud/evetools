<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MoonTaxStatementRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MoonTaxStatementRepository::class)]
#[ORM\Table(name: 'moon_tax_statements')]
#[ORM\UniqueConstraint(columns: ['corporation_id', 'structure_id', 'period_start', 'character_id'])]
#[ORM\Index(columns: ['corporation_id', 'period_start'])]
class MoonTaxStatement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $corporationId;

    #[ORM\Column(type: 'bigint')]
    private int $structureId;

    #[ORM\Column(type: 'bigint')]
    private int $characterId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $periodEnd;

    #[ORM\Column(type: 'string', length: 24, unique: true)]
    private string $reference;

    /** @var list<array{typeId: int, quantity: int, unitPrice: float, ratePercent: float, tax: float}> */
    #[ORM\Column(type: 'json')]
    private array $lines = [];

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $tax = null;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $unknownTypeIds = [];

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $recordedCorporationId = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $outsideAlliance = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $markedPaidAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'acted_by_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $actedAt = null;

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

    public function getCorporationId(): int
    {
        return $this->corporationId;
    }

    public function setCorporationId(int $corporationId): static
    {
        $this->corporationId = $corporationId;

        return $this;
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

    public function getCharacterId(): int
    {
        return $this->characterId;
    }

    public function setCharacterId(int $characterId): static
    {
        $this->characterId = $characterId;

        return $this;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function setPeriodStart(\DateTimeImmutable $periodStart): static
    {
        $this->periodStart = $periodStart;

        return $this;
    }

    public function getPeriodEnd(): \DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function setPeriodEnd(\DateTimeImmutable $periodEnd): static
    {
        $this->periodEnd = $periodEnd;

        return $this;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    /** @return list<array{typeId: int, quantity: int, unitPrice: float, ratePercent: float, tax: float}> */
    public function getLines(): array
    {
        return $this->lines;
    }

    /** @param list<array{typeId: int, quantity: int, unitPrice: float, ratePercent: float, tax: float}> $lines */
    public function setLines(array $lines): static
    {
        $this->lines = $lines;

        return $this;
    }

    public function getTax(): ?float
    {
        return $this->tax;
    }

    public function setTax(?float $tax): static
    {
        $this->tax = $tax;

        return $this;
    }

    /** @return list<int> */
    public function getUnknownTypeIds(): array
    {
        return $this->unknownTypeIds;
    }

    /** @param list<int> $unknownTypeIds */
    public function setUnknownTypeIds(array $unknownTypeIds): static
    {
        $this->unknownTypeIds = $unknownTypeIds;

        return $this;
    }

    public function getRecordedCorporationId(): ?int
    {
        return $this->recordedCorporationId;
    }

    public function setRecordedCorporationId(?int $recordedCorporationId): static
    {
        $this->recordedCorporationId = $recordedCorporationId;

        return $this;
    }

    public function isOutsideAlliance(): ?bool
    {
        return $this->outsideAlliance;
    }

    public function setOutsideAlliance(?bool $outsideAlliance): static
    {
        $this->outsideAlliance = $outsideAlliance;

        return $this;
    }

    public function getMarkedPaidAt(): ?\DateTimeImmutable
    {
        return $this->markedPaidAt;
    }

    public function setMarkedPaidAt(?\DateTimeImmutable $markedPaidAt): static
    {
        $this->markedPaidAt = $markedPaidAt;

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static
    {
        $this->cancelledAt = $cancelledAt;

        return $this;
    }

    public function getActedBy(): ?User
    {
        return $this->actedBy;
    }

    public function setActedBy(?User $actedBy): static
    {
        $this->actedBy = $actedBy;

        return $this;
    }

    public function getActedAt(): ?\DateTimeImmutable
    {
        return $this->actedAt;
    }

    public function setActedAt(?\DateTimeImmutable $actedAt): static
    {
        $this->actedAt = $actedAt;

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
