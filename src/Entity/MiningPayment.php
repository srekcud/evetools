<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MiningPaymentKind;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'mining_payments')]
#[ORM\UniqueConstraint(columns: ['operation_id', 'position'])]
class MiningPayment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $paidBy = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $paidByName = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MiningOperation::class, inversedBy: 'payments')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private MiningOperation $operation,
        #[ORM\Column(type: 'smallint')]
        private int $position,
        #[ORM\Column(type: 'string', length: 30, enumType: MiningPaymentKind::class)]
        private MiningPaymentKind $kind,
        #[ORM\ManyToOne(targetEntity: MiningOperationMember::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?MiningOperationMember $payerMember,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        private ?string $payerName,
        #[ORM\ManyToOne(targetEntity: MiningOperationMember::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?MiningOperationMember $beneficiaryMember,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        private ?string $beneficiaryName,
        #[ORM\Column(type: 'float')]
        private float $amount,
    ) {
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOperation(): MiningOperation
    {
        return $this->operation;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getKind(): MiningPaymentKind
    {
        return $this->kind;
    }

    public function getPayerMember(): ?MiningOperationMember
    {
        return $this->payerMember;
    }

    public function getPayerName(): ?string
    {
        return $this->payerName;
    }

    public function getBeneficiaryMember(): ?MiningOperationMember
    {
        return $this->beneficiaryMember;
    }

    public function getBeneficiaryName(): ?string
    {
        return $this->beneficiaryName;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getPaidBy(): ?User
    {
        return $this->paidBy;
    }

    public function getPaidByName(): ?string
    {
        return $this->paidByName;
    }

    public function isPaid(): bool
    {
        return $this->paidAt !== null;
    }

    public function markPaid(User $paidBy, ?string $paidByName, \DateTimeImmutable $paidAt): void
    {
        $this->paidAt = $paidAt;
        $this->paidBy = $paidBy;
        $this->paidByName = $paidByName;
    }

    public function unmarkPaid(): void
    {
        $this->paidAt = null;
        $this->paidBy = null;
        $this->paidByName = null;
    }
}
