<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PveIncomeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PveIncomeRepository::class)]
#[ORM\Table(name: 'pve_income')]
#[ORM\Index(columns: ['user_id', 'date'])]
#[ORM\Index(columns: ['user_id', 'transaction_id'])]
#[ORM\Index(columns: ['user_id', 'journal_entry_id'])]
#[ORM\Index(columns: ['session_id'])]
class PveIncome
{
    public const TYPE_LOOT_SALE = 'loot_sale';
    public const TYPE_BOUNTY = 'bounty';
    public const TYPE_ESS = 'ess';
    public const TYPE_MISSION = 'mission';
    public const TYPE_LOOT_CONTRACT = 'loot_contract';
    public const TYPE_CORP_PROJECT = 'corp_project';
    public const TYPE_OTHER = 'other';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: PveSession::class, inversedBy: 'incomes')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PveSession $session = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $type;

    #[ORM\Column(type: 'string', length: 255)]
    private string $description;

    #[ORM\Column(type: 'float')]
    private float $amount;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $transactionId = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $journalEntryId = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $contractId = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->date = new \DateTimeImmutable();
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTransactionId(): ?int
    {
        return $this->transactionId;
    }

    public function setTransactionId(?int $transactionId): static
    {
        $this->transactionId = $transactionId;
        return $this;
    }

    public function getJournalEntryId(): ?int
    {
        return $this->journalEntryId;
    }

    public function setJournalEntryId(?int $journalEntryId): static
    {
        $this->journalEntryId = $journalEntryId;
        return $this;
    }

    public function getContractId(): ?int
    {
        return $this->contractId;
    }

    public function setContractId(?int $contractId): static
    {
        $this->contractId = $contractId;
        return $this;
    }

    public function getSession(): ?PveSession
    {
        return $this->session;
    }

    public function setSession(?PveSession $session): static
    {
        $this->session = $session;
        return $this;
    }
}
