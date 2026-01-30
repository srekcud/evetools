<?php

namespace App\Entity;

use App\Repository\PveSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PveSessionRepository::class)]
#[ORM\Table(name: 'pve_sessions')]
#[ORM\Index(columns: ['user_id', 'status'], name: 'idx_pve_session_user_status')]
#[ORM\Index(columns: ['user_id', 'started_at'], name: 'idx_pve_session_user_started')]
#[ORM\HasLifecycleCallbacks]
class PveSession
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, PveIncome> */
    #[ORM\OneToMany(targetEntity: PveIncome::class, mappedBy: 'session')]
    private Collection $incomes;

    /** @var Collection<int, PveExpense> */
    #[ORM\OneToMany(targetEntity: PveExpense::class, mappedBy: 'session')]
    private Collection $expenses;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->startedAt = new \DateTimeImmutable();
        $this->incomes = new ArrayCollection();
        $this->expenses = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): self
    {
        $this->endedAt = $endedAt;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function stop(): self
    {
        $this->endedAt = new \DateTimeImmutable();
        $this->status = self::STATUS_COMPLETED;
        return $this;
    }

    /**
     * Get duration in seconds
     */
    public function getDurationSeconds(): int
    {
        $end = $this->endedAt ?? new \DateTimeImmutable();
        return $end->getTimestamp() - $this->startedAt->getTimestamp();
    }

    /**
     * Get duration formatted as HH:MM:SS
     */
    public function getDurationFormatted(): string
    {
        $seconds = $this->getDurationSeconds();
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * @return Collection<int, PveIncome>
     */
    public function getIncomes(): Collection
    {
        return $this->incomes;
    }

    public function addIncome(PveIncome $income): self
    {
        if (!$this->incomes->contains($income)) {
            $this->incomes->add($income);
            $income->setSession($this);
        }
        return $this;
    }

    /**
     * @return Collection<int, PveExpense>
     */
    public function getExpenses(): Collection
    {
        return $this->expenses;
    }

    public function addExpense(PveExpense $expense): self
    {
        if (!$this->expenses->contains($expense)) {
            $this->expenses->add($expense);
            $expense->setSession($this);
        }
        return $this;
    }

    /**
     * Calculate total income for this session
     */
    public function getTotalIncome(): float
    {
        $total = 0.0;
        foreach ($this->incomes as $income) {
            $total += $income->getAmount();
        }
        return $total;
    }

    /**
     * Calculate total expenses for this session
     */
    public function getTotalExpenses(): float
    {
        $total = 0.0;
        foreach ($this->expenses as $expense) {
            $total += $expense->getAmount();
        }
        return $total;
    }

    /**
     * Calculate profit for this session
     */
    public function getProfit(): float
    {
        return $this->getTotalIncome() - $this->getTotalExpenses();
    }

    /**
     * Calculate ISK per hour for this session
     */
    public function getIskPerHour(): float
    {
        $durationSeconds = $this->getDurationSeconds();
        if ($durationSeconds <= 0) {
            return 0.0;
        }
        $hours = $durationSeconds / 3600;
        return $this->getProfit() / $hours;
    }
}
