<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CorporationWalletDonationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CorporationWalletDonationRepository::class)]
#[ORM\Table(name: 'corporation_wallet_donations')]
#[ORM\UniqueConstraint(columns: ['corporation_id', 'journal_id'])]
#[ORM\Index(columns: ['corporation_id', 'first_party_id'])]
class CorporationWalletDonation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $corporationId;

    #[ORM\Column(type: 'bigint')]
    private int $journalId;

    #[ORM\Column(type: 'bigint')]
    private int $firstPartyId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'float')]
    private float $amount;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $attributedCharacterId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'attributed_by_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $attributedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $attributedAt = null;

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

    public function getJournalId(): int
    {
        return $this->journalId;
    }

    public function setJournalId(int $journalId): static
    {
        $this->journalId = $journalId;

        return $this;
    }

    public function getFirstPartyId(): int
    {
        return $this->firstPartyId;
    }

    public function setFirstPartyId(int $firstPartyId): static
    {
        $this->firstPartyId = $firstPartyId;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getAttributedCharacterId(): ?int
    {
        return $this->attributedCharacterId;
    }

    public function setAttributedCharacterId(?int $attributedCharacterId): static
    {
        $this->attributedCharacterId = $attributedCharacterId;

        return $this;
    }

    public function getAttributedBy(): ?User
    {
        return $this->attributedBy;
    }

    public function setAttributedBy(?User $attributedBy): static
    {
        $this->attributedBy = $attributedBy;

        return $this;
    }

    public function getAttributedAt(): ?\DateTimeImmutable
    {
        return $this->attributedAt;
    }

    public function setAttributedAt(?\DateTimeImmutable $attributedAt): static
    {
        $this->attributedAt = $attributedAt;

        return $this;
    }
}
