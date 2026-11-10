<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CachedWalletJournalEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CachedWalletJournalEntryRepository::class)]
#[ORM\Table(name: 'cached_wallet_journal_entries')]
#[ORM\UniqueConstraint(columns: ['character_id', 'journal_entry_id'])]
#[ORM\Index(columns: ['context_id'])]
class CachedWalletJournalEntry
{
    public const string CONTRACT_BROKERS_FEE = 'contract_brokers_fee';
    public const string CONTRACT_SALES_TAX = 'contract_sales_tax';
    public const array CONTRACT_FEE_REF_TYPES = [self::CONTRACT_BROKERS_FEE, self::CONTRACT_SALES_TAX];
    public const string CONTRACT_CONTEXT_TYPE = 'contract_id';
    public const string PLAYER_DONATION = 'player_donation';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\Column(type: 'bigint')]
    private int $journalEntryId;

    #[ORM\Column(type: 'string', length: 64)]
    private string $refType;

    #[ORM\Column(type: 'float')]
    private float $amount;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $contextId = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $contextIdType = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $firstPartyId = null;

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

    public function getJournalEntryId(): int
    {
        return $this->journalEntryId;
    }

    public function setJournalEntryId(int $journalEntryId): static
    {
        $this->journalEntryId = $journalEntryId;

        return $this;
    }

    public function getRefType(): string
    {
        return $this->refType;
    }

    public function setRefType(string $refType): static
    {
        $this->refType = $refType;

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

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getContextId(): ?int
    {
        return $this->contextId;
    }

    public function setContextId(?int $contextId): static
    {
        $this->contextId = $contextId;

        return $this;
    }

    public function getContextIdType(): ?string
    {
        return $this->contextIdType;
    }

    public function setContextIdType(?string $contextIdType): static
    {
        $this->contextIdType = $contextIdType;

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

    public function getFirstPartyId(): ?int
    {
        return $this->firstPartyId;
    }

    public function setFirstPartyId(?int $firstPartyId): static
    {
        $this->firstPartyId = $firstPartyId;

        return $this;
    }
}
